<?php

use App\Models\Elevator;
use App\Models\InventoryMovement;
use App\Models\ServiceOrder;
use App\Models\ServiceOrderItem;
use App\Models\SparePart;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    // Filtros del historial.
    public string $search = '';
    public string $statusFilter = 'open';

    // Orden seleccionada para consultar.
    public ?int $selectedOrderId = null;

    // Datos para completar una orden.
    public ?int $completionOrderId = null;
    public string $diagnosis = '';
    public string $work_performed = '';
    public string $labor_cost = '0.00';

    /**
     * Comprueba que la cuenta sea un técnico activo.
     */
    public function mount(): void
    {
        $this->authorizeTechnician();
    }

    /**
     * Obtiene solamente las órdenes asignadas
     * al técnico autenticado.
     */
    #[Computed]
    public function orders()
    {
        $search = trim($this->search);

        return ServiceOrder::query()
            ->where(
                'technician_id',
                (int) auth()->id()
            )
            ->with([
                'elevator:id,code,client_name,building_name,address',
                'creator:id,name',
            ])
            ->withCount('items')
            ->when($search !== '', function ($query) use ($search) {
                $term = '%' . $search . '%';

                $query->where(function ($query) use ($term) {
                    $query
                        ->where('folio', 'like', $term)
                        ->orWhere('description', 'like', $term)
                        ->orWhereHas('elevator', function ($query) use ($term) {
                            $query
                                ->where('code', 'like', $term)
                                ->orWhere('client_name', 'like', $term)
                                ->orWhere('building_name', 'like', $term)
                                ->orWhere('address', 'like', $term);
                        });
                });
            })
            ->when(
                $this->statusFilter === 'open',
                fn ($query) => $query->whereIn('status', [
                    ServiceOrder::STATUS_SCHEDULED,
                    ServiceOrder::STATUS_IN_PROGRESS,
                ])
            )
            ->when(
                ! in_array(
                    $this->statusFilter,
                    ['all', 'open'],
                    true
                ),
                fn ($query) => $query->where(
                    'status',
                    $this->statusFilter
                )
            )
            ->orderByRaw(
                "CASE
                    WHEN status = 'in_progress' THEN 1
                    WHEN status = 'scheduled' THEN 2
                    WHEN status = 'completed' THEN 3
                    ELSE 4
                END"
            )
            ->orderBy('scheduled_at')
            ->orderByDesc('id')
            ->paginate(10);
    }

    /**
     * Obtiene el detalle únicamente cuando la orden
     * pertenece al técnico autenticado.
     */
    #[Computed]
    public function selectedOrder(): ?ServiceOrder
    {
        if ($this->selectedOrderId === null) {
            return null;
        }

        return ServiceOrder::query()
            ->where(
                'technician_id',
                (int) auth()->id()
            )
            ->with([
                'elevator',
                'creator:id,name',
                'technician:id,name',
                'items.sparePart:id,sku,name,stock',
            ])
            ->find($this->selectedOrderId);
    }

    /**
     * Obtiene la orden que se está completando.
     */
    #[Computed]
    public function completionOrder(): ?ServiceOrder
    {
        if ($this->completionOrderId === null) {
            return null;
        }

        return ServiceOrder::query()
            ->where(
                'technician_id',
                (int) auth()->id()
            )
            ->with([
                'elevator:id,code,client_name,building_name',
                'items.sparePart:id,sku,name,stock,cost',
            ])
            ->find($this->completionOrderId);
    }

    /**
     * Muestra el detalle de una orden propia.
     */
    public function showDetails(int $orderId): void
    {
        $this->authorizeTechnician();

        $this->findAssignedOrderOrFail($orderId);

        $this->selectedOrderId = $orderId;

        unset($this->selectedOrder);
    }

    /**
     * Cierra el detalle.
     */
    public function closeDetails(): void
    {
        $this->selectedOrderId = null;

        unset($this->selectedOrder);
    }

    /**
     * Inicia una orden programada.
     */
    public function startOrder(int $orderId): void
    {
        $this->authorizeTechnician();

        DB::transaction(function () use ($orderId): void {
            $order = ServiceOrder::query()
                ->where(
                    'technician_id',
                    (int) auth()->id()
                )
                ->whereKey($orderId)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $order->isScheduled()) {
                throw ValidationException::withMessages([
                    'order_state' =>
                        'Solo puedes iniciar una orden que esté programada.',
                ]);
            }

            $order->update([
                'status' => ServiceOrder::STATUS_IN_PROGRESS,
                'started_at' => now(),
            ]);
        });

        $this->selectedOrderId = $orderId;

        unset(
            $this->orders,
            $this->selectedOrder
        );

        session()->flash(
            'success',
            'Orden iniciada correctamente.'
        );
    }

    /**
     * Abre el formulario de finalización.
     */
    public function prepareCompletion(int $orderId): void
    {
        $this->authorizeTechnician();

        $order = $this->findAssignedOrderOrFail($orderId);

        if (! $order->isInProgress()) {
            $this->addError(
                'order_state',
                'Solo puedes completar una orden que esté en proceso.'
            );

            return;
        }

        $this->completionOrderId = $order->id;
        $this->diagnosis = $order->diagnosis ?? '';
        $this->work_performed = $order->work_performed ?? '';
        $this->labor_cost = (string) $order->labor_cost;

        $this->resetValidation();

        unset($this->completionOrder);
    }

    /**
     * Completa la orden, descuenta las refacciones
     * y registra las salidas en el Kárdex.
     */
    public function completeOrder(): void
    {
        $this->authorizeTechnician();

        $this->diagnosis = trim($this->diagnosis);
        $this->work_performed = trim($this->work_performed);

        $validated = $this->validate([
            'completionOrderId' => [
                'required',
                'integer',
                Rule::exists('service_orders', 'id'),
            ],

            'diagnosis' => [
                'required',
                'string',
                'min:3',
                'max:3000',
            ],

            'work_performed' => [
                'required',
                'string',
                'min:3',
                'max:5000',
            ],

            'labor_cost' => [
                'required',
                'numeric',
                'min:0',
                'max:9999999999.99',
            ],
        ], [
            'completionOrderId.required' =>
                'Selecciona una orden.',

            'diagnosis.required' =>
                'Escribe el diagnóstico.',

            'diagnosis.min' =>
                'El diagnóstico debe contener al menos 3 caracteres.',

            'diagnosis.max' =>
                'El diagnóstico no puede superar los 3000 caracteres.',

            'work_performed.required' =>
                'Describe el trabajo realizado.',

            'work_performed.min' =>
                'El trabajo realizado debe contener al menos 3 caracteres.',

            'work_performed.max' =>
                'El trabajo realizado no puede superar los 5000 caracteres.',

            'labor_cost.required' =>
                'Escribe el costo de mano de obra.',

            'labor_cost.numeric' =>
                'El costo de mano de obra debe ser un número.',

            'labor_cost.min' =>
                'El costo no puede ser negativo.',

            'labor_cost.max' =>
                'El costo supera el límite permitido.',
        ]);

        $completedOrder = DB::transaction(
            function () use ($validated): ServiceOrder {
                /*
                 * La consulta exige que la orden pertenezca
                 * al técnico autenticado.
                 */
                $order = ServiceOrder::query()
                    ->where(
                        'technician_id',
                        (int) auth()->id()
                    )
                    ->whereKey(
                        (int) $validated['completionOrderId']
                    )
                    ->lockForUpdate()
                    ->firstOrFail();

                if (! $order->isInProgress()) {
                    throw ValidationException::withMessages([
                        'completion' =>
                            'La orden ya no se encuentra en proceso.',
                    ]);
                }

                $elevator = Elevator::query()
                    ->lockForUpdate()
                    ->findOrFail($order->elevator_id);

                /*
                 * Ordenar las refacciones reduce el riesgo
                 * de bloqueos simultáneos.
                 */
                $orderItems = ServiceOrderItem::query()
                    ->where(
                        'service_order_id',
                        $order->id
                    )
                    ->orderBy('spare_part_id')
                    ->get();

                $partsCost = 0;

                foreach ($orderItems as $orderItem) {
                    $sparePart = SparePart::query()
                        ->lockForUpdate()
                        ->findOrFail(
                            $orderItem->spare_part_id
                        );

                    $quantity = (int) $orderItem->quantity;
                    $previousStock = (int) $sparePart->stock;

                    if ($quantity > $previousStock) {
                        throw ValidationException::withMessages([
                            'completion' =>
                                "Existencia insuficiente de {$sparePart->name}. " .
                                "Disponibles: {$previousStock}; " .
                                "requeridas: {$quantity}.",
                        ]);
                    }

                    $newStock = $previousStock - $quantity;
                    $unitCost = round(
                        (float) $sparePart->cost,
                        2
                    );
                    $subtotal = round(
                        $quantity * $unitCost,
                        2
                    );

                    /*
                     * Conserva el costo utilizado al momento
                     * de completar la orden.
                     */
                    $orderItem->update([
                        'unit_cost' => $unitCost,
                        'subtotal' => $subtotal,
                    ]);

                    // Descuenta la existencia.
                    $sparePart->update([
                        'stock' => $newStock,
                    ]);

                    // Registra la salida en el Kárdex.
                    InventoryMovement::create([
                        'spare_part_id' => $sparePart->id,
                        'user_id' => auth()->id(),
                        'purchase_id' => null,
                        'service_order_id' => $order->id,
                        'type' => InventoryMovement::TYPE_EXIT,
                        'quantity' => $quantity,
                        'previous_stock' => $previousStock,
                        'new_stock' => $newStock,
                        'reason' =>
                            'Orden de servicio: ' . $order->folio,
                        'reference' => $order->folio,
                        'notes' =>
                            "Refacción utilizada en el elevador {$elevator->code}.",
                    ]);

                    $partsCost += $subtotal;
                }

                $laborCost = round(
                    (float) $validated['labor_cost'],
                    2
                );

                $partsCost = round(
                    $partsCost,
                    2
                );

                $order->update([
                    'status' => ServiceOrder::STATUS_COMPLETED,
                    'completed_at' => now(),
                    'diagnosis' => $validated['diagnosis'],
                    'work_performed' =>
                        $validated['work_performed'],
                    'labor_cost' => $laborCost,
                    'parts_cost' => $partsCost,
                    'total_cost' => round(
                        $laborCost + $partsCost,
                        2
                    ),
                ]);

                /*
                 * Actualiza el último servicio y deja
                 * el elevador nuevamente activo.
                 */
                $elevator->update([
                    'last_service_date' => now()->toDateString(),
                    'status' => Elevator::STATUS_ACTIVE,
                ]);

                return $order;
            }
        );

        $this->selectedOrderId = $completedOrder->id;

        $this->closeCompletion();
        $this->resetPage();

        unset(
            $this->orders,
            $this->selectedOrder
        );

        session()->flash(
            'success',
            "Orden {$completedOrder->folio} completada correctamente."
        );
    }

    /**
     * Cierra el formulario de finalización.
     */
    public function closeCompletion(): void
    {
        $this->completionOrderId = null;
        $this->diagnosis = '';
        $this->work_performed = '';
        $this->labor_cost = '0.00';

        $this->resetValidation();

        unset($this->completionOrder);
    }

    /**
     * Busca una orden perteneciente al técnico.
     */
    private function findAssignedOrderOrFail(
        int $orderId
    ): ServiceOrder {
        return ServiceOrder::query()
            ->where(
                'technician_id',
                (int) auth()->id()
            )
            ->whereKey($orderId)
            ->firstOrFail();
    }

    /**
     * Comprueba nuevamente la función y el estado
     * antes de ejecutar cualquier operación.
     */
    private function authorizeTechnician(): void
    {
        $user = auth()->user();

        abort_unless(
            $user !== null &&
            $user->canAccessSystem() &&
            $user->isTechnician(),
            403,
            'Esta pantalla está disponible únicamente para técnicos activos.'
        );
    }

    /**
     * Actualiza el listado cuando cambian los filtros.
     */
    public function updatedSearch(): void
    {
        $this->refreshList();
    }

    public function updatedStatusFilter(): void
    {
        $this->refreshList();
    }

    private function refreshList(): void
    {
        $this->resetPage();

        unset($this->orders);
    }
};

?>

<div class="mx-auto w-full max-w-7xl space-y-6 p-4 sm:p-6">
    {{-- Encabezado --}}
    <div>
        <h1 class="text-2xl font-bold text-zinc-900 dark:text-white">
            Mis órdenes de servicio
        </h1>

        <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">
            Consulta y atiende los servicios que tienes asignados.
        </p>
    </div>

    {{-- Mensajes --}}
    @if (session('success'))
        <div class="rounded-lg border border-green-300 bg-green-50 p-4 text-sm text-green-800 dark:border-green-700 dark:bg-green-950 dark:text-green-200">
            {{ session('success') }}
        </div>
    @endif

    @error('order_state')
        <div class="rounded-lg border border-red-300 bg-red-50 p-4 text-sm text-red-800 dark:border-red-700 dark:bg-red-950 dark:text-red-200">
            {{ $message }}
        </div>
    @enderror

    {{-- Listado de órdenes --}}
    <section class="overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
        {{-- Filtros --}}
        <div class="grid gap-3 border-b border-zinc-200 p-4 dark:border-zinc-700 md:grid-cols-2">
            <input
                type="search"
                wire:model.live.debounce.300ms="search"
                placeholder="Buscar folio, elevador, cliente o edificio..."
                class="rounded-lg border border-zinc-300 px-3 py-2 outline-none focus:border-blue-500 dark:border-zinc-600 dark:bg-zinc-800 dark:text-white"
            >

            <select
                wire:model.live="statusFilter"
                class="rounded-lg border border-zinc-300 px-3 py-2 outline-none dark:border-zinc-600 dark:bg-zinc-800 dark:text-white"
            >
                <option value="open">Pendientes y en proceso</option>
                <option value="all">Todas las órdenes</option>
                <option value="scheduled">Programadas</option>
                <option value="in_progress">En proceso</option>
                <option value="completed">Completadas</option>
                <option value="cancelled">Canceladas</option>
            </select>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-700">
                <thead class="bg-zinc-50 dark:bg-zinc-800">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase text-zinc-600 dark:text-zinc-300">
                            Orden
                        </th>

                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase text-zinc-600 dark:text-zinc-300">
                            Elevador
                        </th>

                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase text-zinc-600 dark:text-zinc-300">
                            Servicio
                        </th>

                        <th class="px-4 py-3 text-center text-xs font-semibold uppercase text-zinc-600 dark:text-zinc-300">
                            Estado
                        </th>

                        <th class="px-4 py-3 text-right text-xs font-semibold uppercase text-zinc-600 dark:text-zinc-300">
                            Acciones
                        </th>
                    </tr>
                </thead>

                <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                    @forelse ($this->orders as $order)
                        @php
                            $statusClasses = match ($order->status) {
                                \App\Models\ServiceOrder::STATUS_SCHEDULED =>
                                    'bg-blue-100 text-blue-700',

                                \App\Models\ServiceOrder::STATUS_IN_PROGRESS =>
                                    'bg-amber-100 text-amber-700',

                                \App\Models\ServiceOrder::STATUS_COMPLETED =>
                                    'bg-green-100 text-green-700',

                                default =>
                                    'bg-red-100 text-red-700',
                            };

                            $priorityClasses = match ($order->priority) {
                                \App\Models\ServiceOrder::PRIORITY_URGENT =>
                                    'text-red-600',

                                \App\Models\ServiceOrder::PRIORITY_HIGH =>
                                    'text-orange-600',

                                default =>
                                    'text-zinc-500',
                            };
                        @endphp

                        <tr wire:key="my-order-{{ $order->id }}">
                            <td class="px-4 py-4">
                                <p class="font-semibold text-zinc-900 dark:text-white">
                                    {{ $order->folio }}
                                </p>

                                <p class="mt-1 text-xs text-zinc-500">
                                    {{ $order->scheduled_at?->format('d/m/Y H:i') }}
                                </p>
                            </td>

                            <td class="px-4 py-4">
                                <p class="font-medium text-zinc-900 dark:text-white">
                                    {{ $order->elevator->code }}
                                </p>

                                <p class="mt-1 text-xs text-zinc-500">
                                    {{ $order->elevator->building_name }}
                                </p>

                                <p class="mt-1 text-xs text-zinc-500">
                                    {{ $order->elevator->client_name }}
                                </p>
                            </td>

                            <td class="px-4 py-4">
                                <p class="text-sm font-medium text-zinc-900 dark:text-white">
                                    {{ $order->serviceTypeLabel() }}
                                </p>

                                <p class="mt-1 text-xs {{ $priorityClasses }}">
                                    Prioridad: {{ $order->priorityLabel() }}
                                </p>

                                <p class="mt-1 text-xs text-zinc-500">
                                    {{ $order->items_count }}
                                    {{ $order->items_count === 1 ? 'refacción' : 'refacciones' }}
                                </p>
                            </td>

                            <td class="px-4 py-4 text-center">
                                <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold {{ $statusClasses }}">
                                    {{ $order->statusLabel() }}
                                </span>
                            </td>

                            <td class="px-4 py-4">
                                <div class="flex flex-wrap justify-end gap-2">
                                    <button
                                        type="button"
                                        wire:click="showDetails({{ $order->id }})"
                                        class="rounded-lg border border-blue-400 px-3 py-2 text-sm font-semibold text-blue-600 hover:bg-blue-50 dark:hover:bg-blue-950"
                                    >
                                        Ver
                                    </button>

                                    @if ($order->isScheduled())
                                        <button
                                            type="button"
                                            wire:click="startOrder({{ $order->id }})"
                                            wire:confirm="¿Confirmas que deseas iniciar esta orden?"
                                            class="rounded-lg bg-amber-500 px-3 py-2 text-sm font-semibold text-white hover:bg-amber-600"
                                        >
                                            Iniciar
                                        </button>
                                    @endif

                                    @if ($order->isInProgress())
                                        <button
                                            type="button"
                                            wire:click="prepareCompletion({{ $order->id }})"
                                            class="rounded-lg bg-green-600 px-3 py-2 text-sm font-semibold text-white hover:bg-green-700"
                                        >
                                            Completar
                                        </button>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td
                                colspan="5"
                                class="px-4 py-12 text-center text-sm text-zinc-500"
                            >
                                No tienes órdenes que coincidan con los filtros.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="border-t border-zinc-200 p-4 dark:border-zinc-700">
            {{ $this->orders->links() }}
        </div>
    </section>

    {{-- Formulario para completar una orden --}}
    @if ($this->completionOrder)
        @php
            $completionOrder = $this->completionOrder;
        @endphp

        <form
            wire:submit="completeOrder"
            class="space-y-5 rounded-xl border border-green-400 bg-white p-5 shadow-sm dark:bg-zinc-900"
        >
            <div>
                <h2 class="text-lg font-semibold text-zinc-900 dark:text-white">
                    Completar {{ $completionOrder->folio }}
                </h2>

                <p class="mt-1 text-sm text-zinc-500">
                    {{ $completionOrder->elevator->code }} ·
                    {{ $completionOrder->elevator->building_name }}
                </p>
            </div>

            @error('completion')
                <div class="rounded-lg border border-red-300 bg-red-50 p-4 text-sm text-red-700 dark:border-red-700 dark:bg-red-950 dark:text-red-200">
                    {{ $message }}
                </div>
            @enderror

            {{-- Refacciones que serán descontadas --}}
            <div class="rounded-lg border border-zinc-200 dark:border-zinc-700">
                <div class="border-b border-zinc-200 p-4 dark:border-zinc-700">
                    <h3 class="font-semibold text-zinc-900 dark:text-white">
                        Refacciones que serán descontadas
                    </h3>
                </div>

                <div class="divide-y divide-zinc-200 dark:divide-zinc-700">
                    @forelse ($completionOrder->items as $item)
                        <div class="flex items-center justify-between gap-4 p-4">
                            <div>
                                <p class="font-medium text-zinc-900 dark:text-white">
                                    {{ $item->sparePart->name }}
                                </p>

                                <p class="mt-1 text-xs text-zinc-500">
                                    {{ $item->sparePart->sku }}
                                </p>
                            </div>

                            <div class="text-right">
                                <p class="font-semibold">
                                    {{ $item->quantity }} unidades
                                </p>

                                <p class="mt-1 text-xs {{ $item->quantity > $item->sparePart->stock ? 'text-red-600' : 'text-zinc-500' }}">
                                    Disponibles:
                                    {{ $item->sparePart->stock }}
                                </p>
                            </div>
                        </div>
                    @empty
                        <p class="p-4 text-sm text-zinc-500">
                            Esta orden no utiliza refacciones.
                        </p>
                    @endforelse
                </div>
            </div>

            <div class="grid gap-4 md:grid-cols-2">
                <div>
                    <label
                        for="diagnosis"
                        class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300"
                    >
                        Diagnóstico *
                    </label>

                    <textarea
                        id="diagnosis"
                        wire:model="diagnosis"
                        rows="4"
                        maxlength="3000"
                        placeholder="Describe la causa de la falla"
                        class="w-full resize-none rounded-lg border border-zinc-300 px-3 py-2 dark:border-zinc-600 dark:bg-zinc-800 dark:text-white"
                    ></textarea>

                    @error('diagnosis')
                        <p class="mt-1 text-sm text-red-600">
                            {{ $message }}
                        </p>
                    @enderror
                </div>

                <div>
                    <label
                        for="work_performed"
                        class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300"
                    >
                        Trabajo realizado *
                    </label>

                    <textarea
                        id="work_performed"
                        wire:model="work_performed"
                        rows="4"
                        maxlength="5000"
                        placeholder="Describe las actividades realizadas"
                        class="w-full resize-none rounded-lg border border-zinc-300 px-3 py-2 dark:border-zinc-600 dark:bg-zinc-800 dark:text-white"
                    ></textarea>

                    @error('work_performed')
                        <p class="mt-1 text-sm text-red-600">
                            {{ $message }}
                        </p>
                    @enderror
                </div>
            </div>

            <div class="max-w-sm">
                <label
                    for="labor_cost"
                    class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300"
                >
                    Costo de mano de obra
                </label>

                <input
                    id="labor_cost"
                    type="number"
                    wire:model="labor_cost"
                    min="0"
                    step="0.01"
                    class="w-full rounded-lg border border-zinc-300 px-3 py-2 dark:border-zinc-600 dark:bg-zinc-800 dark:text-white"
                >

                @error('labor_cost')
                    <p class="mt-1 text-sm text-red-600">
                        {{ $message }}
                    </p>
                @enderror
            </div>

            <div class="flex flex-wrap gap-3">
                <button
                    type="submit"
                    wire:loading.attr="disabled"
                    wire:target="completeOrder"
                    class="rounded-lg bg-green-600 px-4 py-2 text-sm font-semibold text-white hover:bg-green-700 disabled:opacity-60"
                >
                    <span wire:loading.remove wire:target="completeOrder">
                        Confirmar servicio
                    </span>

                    <span wire:loading wire:target="completeOrder">
                        Procesando...
                    </span>
                </button>

                <button
                    type="button"
                    wire:click="closeCompletion"
                    class="rounded-lg border border-zinc-300 px-4 py-2 text-sm font-semibold text-zinc-700 dark:border-zinc-600 dark:text-zinc-200"
                >
                    Cerrar
                </button>
            </div>
        </form>
    @endif

    {{-- Detalle de la orden --}}
    @if ($this->selectedOrder)
        @php
            $selectedOrder = $this->selectedOrder;
        @endphp

        <section class="overflow-hidden rounded-xl border border-blue-300 bg-white shadow-sm dark:border-blue-800 dark:bg-zinc-900">
            <div class="flex items-start justify-between gap-4 border-b border-zinc-200 p-5 dark:border-zinc-700">
                <div>
                    <h2 class="text-lg font-semibold text-zinc-900 dark:text-white">
                        {{ $selectedOrder->folio }}
                    </h2>

                    <p class="mt-1 text-sm text-zinc-500">
                        {{ $selectedOrder->elevator->code }} ·
                        {{ $selectedOrder->elevator->building_name }}
                    </p>
                </div>

                <button
                    type="button"
                    wire:click="closeDetails"
                    class="text-sm font-semibold text-zinc-700 dark:text-zinc-200"
                >
                    Cerrar
                </button>
            </div>

            <div class="grid gap-4 p-5 sm:grid-cols-2 xl:grid-cols-4">
                <div>
                    <p class="text-xs uppercase text-zinc-500">
                        Estado
                    </p>

                    <p class="mt-1 font-semibold">
                        {{ $selectedOrder->statusLabel() }}
                    </p>
                </div>

                <div>
                    <p class="text-xs uppercase text-zinc-500">
                        Tipo
                    </p>

                    <p class="mt-1 font-semibold">
                        {{ $selectedOrder->serviceTypeLabel() }}
                    </p>
                </div>

                <div>
                    <p class="text-xs uppercase text-zinc-500">
                        Prioridad
                    </p>

                    <p class="mt-1 font-semibold">
                        {{ $selectedOrder->priorityLabel() }}
                    </p>
                </div>

                <div>
                    <p class="text-xs uppercase text-zinc-500">
                        Fecha programada
                    </p>

                    <p class="mt-1 font-semibold">
                        {{ $selectedOrder->scheduled_at?->format('d/m/Y H:i') }}
                    </p>
                </div>
            </div>

            <div class="grid gap-5 border-t border-zinc-200 p-5 dark:border-zinc-700 lg:grid-cols-2">
                <div>
                    <p class="text-sm font-semibold">
                        Cliente y ubicación
                    </p>

                    <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-300">
                        {{ $selectedOrder->elevator->client_name }}
                    </p>

                    <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-300">
                        {{ $selectedOrder->elevator->address }}
                    </p>
                </div>

                <div>
                    <p class="text-sm font-semibold">
                        Servicio solicitado
                    </p>

                    <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-300">
                        {{ $selectedOrder->description }}
                    </p>
                </div>
            </div>

            @if ($selectedOrder->notes)
                <div class="border-t border-zinc-200 p-5 dark:border-zinc-700">
                    <p class="text-sm font-semibold">
                        Notas
                    </p>

                    <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-300">
                        {{ $selectedOrder->notes }}
                    </p>
                </div>
            @endif

            @if ($selectedOrder->diagnosis)
                <div class="grid gap-5 border-t border-zinc-200 p-5 dark:border-zinc-700 lg:grid-cols-2">
                    <div>
                        <p class="text-sm font-semibold">
                            Diagnóstico
                        </p>

                        <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-300">
                            {{ $selectedOrder->diagnosis }}
                        </p>
                    </div>

                    <div>
                        <p class="text-sm font-semibold">
                            Trabajo realizado
                        </p>

                        <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-300">
                            {{ $selectedOrder->work_performed }}
                        </p>
                    </div>
                </div>
            @endif

            <div class="border-t border-zinc-200 p-5 dark:border-zinc-700">
                <h3 class="font-semibold">
                    Refacciones
                </h3>

                <div class="mt-3 space-y-2">
                    @forelse ($selectedOrder->items as $item)
                        <div class="flex justify-between gap-4 rounded-lg bg-zinc-50 p-3 dark:bg-zinc-800">
                            <span>
                                {{ $item->sparePart->sku }} —
                                {{ $item->sparePart->name }}
                            </span>

                            <span class="font-semibold">
                                {{ $item->quantity }} unidades
                            </span>
                        </div>
                    @empty
                        <p class="text-sm text-zinc-500">
                            Esta orden no tiene refacciones.
                        </p>
                    @endforelse
                </div>
            </div>
        </section>
    @endif
</div>