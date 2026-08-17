<?php

use App\Models\Elevator;
use App\Models\InventoryMovement;
use App\Models\ServiceOrder;
use App\Models\ServiceOrderItem;
use App\Models\SparePart;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    // Datos de la nueva orden.
    public string $elevator_id = '';
    public string $technician_id = '';
    public string $service_type = ServiceOrder::TYPE_PREVENTIVE;
    public string $priority = ServiceOrder::PRIORITY_MEDIUM;
    public string $scheduled_at = '';
    public string $description = '';
    public string $notes = '';

    // Refacciones planeadas.
    public array $items = [];

    // Filtros del historial.
    public string $search = '';
    public string $statusFilter = 'all';
    public string $typeFilter = 'all';

    // Orden seleccionada para consultar su detalle.
    public ?int $selectedOrderId = null;

    // Datos para completar una orden.
    public ?int $completionOrderId = null;
    public string $diagnosis = '';
    public string $work_performed = '';
    public string $labor_cost = '0.00';

    /**
     * Inicializa el formulario.
     */
    public function mount(): void
    {
        $this->scheduled_at = now()
            ->addDay()
            ->format('Y-m-d\TH:i');
    }

    /**
     * Reglas para registrar una orden.
     */
    protected function rules(): array
    {
        return [
            'elevator_id' => [
                'required',
                'integer',
                Rule::exists('elevators', 'id')
                    ->where(function ($query) {
                        $query->whereIn('status', [
                            Elevator::STATUS_ACTIVE,
                            Elevator::STATUS_OUT_OF_SERVICE,
                        ]);
                    }),
            ],

            /*
             * Solamente permite usuarios activos cuya función
             * sea Técnico, incluso si se manipula el formulario.
             */
            'technician_id' => [
                'required',
                'integer',
                Rule::exists('users', 'id')
                    ->where(function ($query) {
                        $query
                            ->where('role', User::ROLE_TECHNICIAN)
                            ->where('is_active', true);
                    }),
            ],

            'service_type' => [
                'required',
                Rule::in([
                    ServiceOrder::TYPE_PREVENTIVE,
                    ServiceOrder::TYPE_CORRECTIVE,
                    ServiceOrder::TYPE_INSPECTION,
                    ServiceOrder::TYPE_INSTALLATION,
                ]),
            ],

            'priority' => [
                'required',
                Rule::in([
                    ServiceOrder::PRIORITY_LOW,
                    ServiceOrder::PRIORITY_MEDIUM,
                    ServiceOrder::PRIORITY_HIGH,
                    ServiceOrder::PRIORITY_URGENT,
                ]),
            ],

            'scheduled_at' => [
                'required',
                'date',
                'after_or_equal:today',
            ],

            'description' => [
                'required',
                'string',
                'min:5',
                'max:3000',
            ],

            'notes' => [
                'nullable',
                'string',
                'max:2000',
            ],

            'items' => [
                'array',
                'max:50',
            ],

            'items.*.spare_part_id' => [
                'required',
                'integer',
                'distinct',
                Rule::exists('spare_parts', 'id')
                    ->where('is_active', true),
            ],

            'items.*.quantity' => [
                'required',
                'integer',
                'min:1',
                'max:999999999',
            ],
        ];
    }

    /**
     * Mensajes de validación en español.
     */
    protected function messages(): array
    {
        return [
            'elevator_id.required' => 'Selecciona un elevador.',
            'elevator_id.exists' => 'El elevador no existe o está inactivo.',

            'technician_id.required' => 'Selecciona un técnico.',
            'technician_id.exists' => 'El técnico no existe, está bloqueado o no tiene la función de técnico.',

            'service_type.required' => 'Selecciona el tipo de servicio.',
            'service_type.in' => 'El tipo de servicio no es válido.',

            'priority.required' => 'Selecciona la prioridad.',
            'priority.in' => 'La prioridad no es válida.',

            'scheduled_at.required' => 'Selecciona la fecha programada.',
            'scheduled_at.date' => 'La fecha programada no es válida.',
            'scheduled_at.after_or_equal' => 'La orden no puede programarse antes del día de hoy.',

            'description.required' => 'Describe el servicio solicitado.',
            'description.min' => 'La descripción debe contener al menos 5 caracteres.',
            'description.max' => 'La descripción no puede superar los 3000 caracteres.',

            'notes.max' => 'Las notas no pueden superar los 2000 caracteres.',

            'items.max' => 'La orden no puede superar las 50 refacciones.',

            'items.*.spare_part_id.required' => 'Selecciona una refacción.',
            'items.*.spare_part_id.exists' => 'La refacción no existe o está inactiva.',
            'items.*.spare_part_id.distinct' => 'La refacción está repetida en la orden.',

            'items.*.quantity.required' => 'Escribe la cantidad.',
            'items.*.quantity.integer' => 'La cantidad debe ser un número entero.',
            'items.*.quantity.min' => 'La cantidad debe ser mayor que cero.',
            'items.*.quantity.max' => 'La cantidad supera el límite permitido.',
        ];
    }

    /**
     * Elevadores disponibles para recibir servicio.
     */
    #[Computed]
    public function elevators()
    {
        return Elevator::query()
            ->availableForService()
            ->orderBy('client_name')
            ->orderBy('building_name')
            ->get([
                'id',
                'code',
                'client_name',
                'building_name',
                'status',
            ]);
    }

    /**
     * Obtiene únicamente técnicos activos.
     *
     * Los administradores y usuarios bloqueados
     * no aparecerán en el selector.
     */
    #[Computed]
    public function technicians()
    {
        return User::query()
            ->technicians()
            ->active()
            ->orderBy('name')
            ->get([
                'id',
                'name',
                'email',
            ]);
    }

    /**
     * Refacciones activas disponibles.
     */
    #[Computed]
    public function spareParts()
    {
        return SparePart::query()
            ->active()
            ->orderBy('name')
            ->get([
                'id',
                'sku',
                'name',
                'stock',
                'cost',
            ]);
    }

    /**
     * Calcula el costo estimado de las refacciones.
     */
    #[Computed]
    public function plannedPartsTotal(): float
    {
        $spareParts = $this->spareParts->keyBy('id');
        $total = 0;

        foreach ($this->items as $item) {
            $sparePartId = (int) ($item['spare_part_id'] ?? 0);
            $quantity = max(
                0,
                (int) ($item['quantity'] ?? 0)
            );

            $sparePart = $spareParts->get($sparePartId);

            if ($sparePart === null) {
                continue;
            }

            $total += $quantity * (float) $sparePart->cost;
        }

        return round($total, 2);
    }

    /**
     * Obtiene el historial de órdenes.
     */
    #[Computed]
    public function orders()
    {
        $search = trim($this->search);

        return ServiceOrder::query()
            ->with([
                'elevator:id,code,client_name,building_name',
                'technician:id,name',
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
                                ->orWhere('building_name', 'like', $term);
                        })
                        ->orWhereHas('technician', function ($query) use ($term) {
                            $query->where('name', 'like', $term);
                        });
                });
            })
            ->when(
                $this->statusFilter !== 'all',
                fn ($query) => $query->where(
                    'status',
                    $this->statusFilter
                )
            )
            ->when(
                $this->typeFilter !== 'all',
                fn ($query) => $query->where(
                    'service_type',
                    $this->typeFilter
                )
            )
            ->orderByDesc('created_at')
            ->paginate(10);
    }

    /**
     * Obtiene el detalle de la orden seleccionada.
     */
    #[Computed]
    public function selectedOrder(): ?ServiceOrder
    {
        if ($this->selectedOrderId === null) {
            return null;
        }

        return ServiceOrder::query()
            ->with([
                'elevator',
                'creator:id,name',
                'technician:id,name',
                'items.sparePart:id,sku,name',
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
            ->with([
                'elevator:id,code,client_name,building_name',
                'items.sparePart:id,sku,name,stock,cost',
            ])
            ->find($this->completionOrderId);
    }

    /**
     * Agrega una refacción al formulario.
     */
    public function addItem(): void
    {
        if (count($this->items) >= 50) {
            $this->addError(
                'items',
                'La orden no puede superar las 50 refacciones.'
            );

            return;
        }

        $this->items[] = [
            'key' => (string) Str::uuid(),
            'spare_part_id' => '',
            'quantity' => 1,
        ];

        $this->resetValidation('items');

        unset($this->plannedPartsTotal);
    }

    /**
     * Quita una refacción del formulario.
     */
    public function removeItem(int $index): void
    {
        if (! array_key_exists($index, $this->items)) {
            return;
        }

        unset($this->items[$index]);

        $this->items = array_values($this->items);

        $this->resetValidation();

        unset($this->plannedPartsTotal);
    }

    /**
     * Registra una nueva orden.
     *
     * El inventario no se descuenta en esta etapa.
     */
    public function save(): void
    {
        $this->normalizeFields();

        $validated = $this->validate();

        $order = DB::transaction(function () use ($validated): ServiceOrder {
            /*
             * Bloquea el elevador para confirmar que todavía
             * esté disponible para recibir una orden.
             */
            $elevator = Elevator::query()
                ->lockForUpdate()
                ->findOrFail((int) $validated['elevator_id']);

            if ($elevator->isInactive()) {
                throw ValidationException::withMessages([
                    'elevator_id' => 'No puedes crear órdenes para un elevador inactivo.',
                ]);
            }

            /*
             * Confirma nuevamente que el técnico siga activo
             * y tenga la función correcta.
             */
            $technician = User::query()
                ->technicians()
                ->active()
                ->lockForUpdate()
                ->find((int) $validated['technician_id']);

            if ($technician === null) {
                throw ValidationException::withMessages([
                    'technician_id' => 'El técnico seleccionado ya no está disponible.',
                ]);
            }

            $order = ServiceOrder::create([
                'folio' => null,
                'elevator_id' => $elevator->id,
                'created_by' => auth()->id(),
                'technician_id' => $technician->id,
                'service_type' => $validated['service_type'],
                'priority' => $validated['priority'],
                'status' => ServiceOrder::STATUS_SCHEDULED,
                'scheduled_at' => $validated['scheduled_at'],
                'description' => $validated['description'],
                'notes' => $validated['notes'] !== ''
                    ? $validated['notes']
                    : null,
                'labor_cost' => 0,
                'parts_cost' => 0,
                'total_cost' => 0,
            ]);

            /*
             * El folio se genera utilizando el ID asignado
             * por la base de datos para evitar duplicados.
             */
            $folio = 'OS-' .
                $order->created_at->format('Y') .
                '-' .
                str_pad(
                    (string) $order->id,
                    6,
                    '0',
                    STR_PAD_LEFT
                );

            $order->update([
                'folio' => $folio,
            ]);

            $partsCost = 0;

            foreach ($validated['items'] as $index => $item) {
                $sparePart = SparePart::query()
                    ->lockForUpdate()
                    ->findOrFail((int) $item['spare_part_id']);

                if (! $sparePart->is_active) {
                    throw ValidationException::withMessages([
                        "items.{$index}.spare_part_id" =>
                            'La refacción seleccionada está inactiva.',
                    ]);
                }

                $quantity = (int) $item['quantity'];
                $unitCost = round(
                    (float) $sparePart->cost,
                    2
                );
                $subtotal = round(
                    $quantity * $unitCost,
                    2
                );

                ServiceOrderItem::create([
                    'service_order_id' => $order->id,
                    'spare_part_id' => $sparePart->id,
                    'quantity' => $quantity,
                    'unit_cost' => $unitCost,
                    'subtotal' => $subtotal,
                ]);

                $partsCost += $subtotal;
            }

            $order->update([
                'parts_cost' => round($partsCost, 2),
                'total_cost' => round($partsCost, 2),
            ]);

            return $order;
        });

        $this->resetForm();
        $this->resetPage();

        $this->selectedOrderId = $order->id;

        unset(
            $this->orders,
            $this->selectedOrder,
            $this->plannedPartsTotal
        );

        session()->flash(
            'success',
            "Orden {$order->folio} programada correctamente."
        );
    }

    /**
     * Cambia una orden programada a En proceso.
     */
    public function startOrder(int $orderId): void
    {
        DB::transaction(function () use ($orderId): void {
            $order = ServiceOrder::query()
                ->lockForUpdate()
                ->findOrFail($orderId);

            if (! $order->isScheduled()) {
                throw ValidationException::withMessages([
                    'order_state' => 'Solo se pueden iniciar órdenes programadas.',
                ]);
            }

            $order->update([
                'status' => ServiceOrder::STATUS_IN_PROGRESS,
                'started_at' => now(),
            ]);
        });

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
     * Prepara el formulario para completar la orden.
     */
    public function prepareCompletion(int $orderId): void
    {
        $order = ServiceOrder::query()
            ->findOrFail($orderId);

        if (! $order->isInProgress()) {
            $this->addError(
                'order_state',
                'Solo se pueden completar órdenes que estén en proceso.'
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
     * y genera las salidas del Kárdex.
     */
    public function completeOrder(): void
    {
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
            'diagnosis.required' => 'Escribe el diagnóstico.',
            'diagnosis.min' => 'El diagnóstico debe contener al menos 3 caracteres.',
            'diagnosis.max' => 'El diagnóstico no puede superar los 3000 caracteres.',

            'work_performed.required' => 'Describe el trabajo realizado.',
            'work_performed.min' => 'El trabajo realizado debe contener al menos 3 caracteres.',
            'work_performed.max' => 'El trabajo realizado no puede superar los 5000 caracteres.',

            'labor_cost.required' => 'Escribe el costo de mano de obra.',
            'labor_cost.numeric' => 'El costo de mano de obra debe ser un número.',
            'labor_cost.min' => 'El costo de mano de obra no puede ser negativo.',
            'labor_cost.max' => 'El costo de mano de obra supera el límite permitido.',
        ]);

        $completedOrder = DB::transaction(
            function () use ($validated): ServiceOrder {
                $order = ServiceOrder::query()
                    ->lockForUpdate()
                    ->findOrFail(
                        (int) $validated['completionOrderId']
                    );

                if (! $order->isInProgress()) {
                    throw ValidationException::withMessages([
                        'completion' => 'La orden ya no se encuentra en proceso.',
                    ]);
                }

                $elevator = Elevator::query()
                    ->lockForUpdate()
                    ->findOrFail($order->elevator_id);

                /*
                 * Ordenar las partidas reduce el riesgo de bloqueos
                 * cuando existen operaciones simultáneas.
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
                                "Disponibles: {$previousStock}; requeridas: {$quantity}.",
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

                    // Actualiza el costo histórico de la partida.
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
                        'reason' => 'Orden de servicio: ' . $order->folio,
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

                $partsCost = round($partsCost, 2);

                $order->update([
                    'status' => ServiceOrder::STATUS_COMPLETED,
                    'completed_at' => now(),
                    'diagnosis' => $validated['diagnosis'],
                    'work_performed' => $validated['work_performed'],
                    'labor_cost' => $laborCost,
                    'parts_cost' => $partsCost,
                    'total_cost' => round(
                        $partsCost + $laborCost,
                        2
                    ),
                ]);

                /*
                 * Actualiza el último servicio y deja
                 * nuevamente activo el elevador.
                 */
                $elevator->update([
                    'last_service_date' => now()->toDateString(),
                    'status' => Elevator::STATUS_ACTIVE,
                ]);

                return $order;
            }
        );

        $this->selectedOrderId = $completedOrder->id;

        $this->cancelCompletion();

        unset(
            $this->orders,
            $this->selectedOrder,
            $this->spareParts,
            $this->elevators
        );

        session()->flash(
            'success',
            "Orden {$completedOrder->folio} completada correctamente."
        );
    }

    /**
     * Cancela una orden sin modificar el inventario.
     */
    public function cancelOrder(int $orderId): void
    {
        DB::transaction(function () use ($orderId): void {
            $order = ServiceOrder::query()
                ->lockForUpdate()
                ->findOrFail($orderId);

            if ($order->isCompleted() || $order->isCancelled()) {
                throw ValidationException::withMessages([
                    'order_state' => 'Esta orden ya no puede cancelarse.',
                ]);
            }

            $order->update([
                'status' => ServiceOrder::STATUS_CANCELLED,
            ]);
        });

        if ($this->completionOrderId === $orderId) {
            $this->cancelCompletion();
        }

        unset(
            $this->orders,
            $this->selectedOrder
        );

        session()->flash(
            'success',
            'Orden cancelada correctamente. El inventario no fue modificado.'
        );
    }

    /**
     * Muestra el detalle de una orden.
     */
    public function showDetails(int $orderId): void
    {
        $this->selectedOrderId = $orderId;

        unset($this->selectedOrder);
    }

    /**
     * Cierra el detalle de la orden.
     */
    public function closeDetails(): void
    {
        $this->selectedOrderId = null;

        unset($this->selectedOrder);
    }

    /**
     * Cierra el formulario de finalización.
     */
    public function cancelCompletion(): void
    {
        $this->completionOrderId = null;
        $this->diagnosis = '';
        $this->work_performed = '';
        $this->labor_cost = '0.00';

        $this->resetValidation();

        unset($this->completionOrder);
    }

    /**
     * Limpia el formulario principal.
     */
    public function resetForm(): void
    {
        $this->elevator_id = '';
        $this->technician_id = '';
        $this->service_type = ServiceOrder::TYPE_PREVENTIVE;
        $this->priority = ServiceOrder::PRIORITY_MEDIUM;
        $this->scheduled_at = now()
            ->addDay()
            ->format('Y-m-d\TH:i');
        $this->description = '';
        $this->notes = '';
        $this->items = [];

        $this->resetValidation();

        unset($this->plannedPartsTotal);
    }

    /**
     * Normaliza los textos antes de validarlos.
     */
    private function normalizeFields(): void
    {
        $this->description = Str::squish(
            $this->description
        );

        $this->notes = trim(
            $this->notes
        );
    }

    /**
     * Actualiza el historial al modificar filtros.
     */
    public function updatedSearch(): void
    {
        $this->refreshHistory();
    }

    public function updatedStatusFilter(): void
    {
        $this->refreshHistory();
    }

    public function updatedTypeFilter(): void
    {
        $this->refreshHistory();
    }

    private function refreshHistory(): void
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
            Órdenes de servicio
        </h1>

        <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">
            Programa y controla los servicios realizados a los elevadores.
        </p>
    </div>

    {{-- Mensajes de confirmación y error --}}
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

    {{-- Formulario de nueva orden --}}
    <form
        wire:submit="save"
        class="space-y-6 rounded-xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900"
    >
        <div>
            <h2 class="text-lg font-semibold text-zinc-900 dark:text-white">
                Nueva orden
            </h2>

            <p class="mt-1 text-sm text-zinc-500">
                Las refacciones no se descontarán hasta completar el servicio.
            </p>
        </div>

        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            {{-- Elevador --}}
            <div class="xl:col-span-2">
                <label
                    for="elevator_id"
                    class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300"
                >
                    Elevador *
                </label>

                <select
                    id="elevator_id"
                    wire:model="elevator_id"
                    class="w-full rounded-lg border border-zinc-300 px-3 py-2 outline-none focus:border-blue-500 dark:border-zinc-600 dark:bg-zinc-800 dark:text-white"
                >
                    <option value="">Selecciona un elevador</option>

                    @foreach ($this->elevators as $elevator)
                        <option value="{{ $elevator->id }}">
                            {{ $elevator->code }} —
                            {{ $elevator->client_name }} /
                            {{ $elevator->building_name }}
                        </option>
                    @endforeach
                </select>

                @error('elevator_id')
                    <p class="mt-1 text-sm text-red-600">
                        {{ $message }}
                    </p>
                @enderror
            </div>

            {{-- Técnico --}}
            <div>
                <label
                    for="technician_id"
                    class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300"
                >
                    Técnico *
                </label>

                <select
                    id="technician_id"
                    wire:model="technician_id"
                    class="w-full rounded-lg border border-zinc-300 px-3 py-2 outline-none focus:border-blue-500 dark:border-zinc-600 dark:bg-zinc-800 dark:text-white"
                >
                    <option value="">Selecciona un técnico</option>

                    @foreach ($this->technicians as $technician)
                        <option value="{{ $technician->id }}">
                            {{ $technician->name }}
                        </option>
                    @endforeach
                </select>

                @if ($this->technicians->isEmpty())
                    <p class="mt-1 text-xs text-amber-600">
                        No existen técnicos activos. Registra uno desde Usuarios y técnicos.
                    </p>
                @endif

                @error('technician_id')
                    <p class="mt-1 text-sm text-red-600">
                        {{ $message }}
                    </p>
                @enderror
            </div>

            {{-- Fecha programada --}}
            <div>
                <label
                    for="scheduled_at"
                    class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300"
                >
                    Fecha programada *
                </label>

                <input
                    id="scheduled_at"
                    type="datetime-local"
                    wire:model="scheduled_at"
                    min="{{ now()->toDateString() }}T00:00"
                    class="w-full rounded-lg border border-zinc-300 px-3 py-2 outline-none focus:border-blue-500 dark:border-zinc-600 dark:bg-zinc-800 dark:text-white"
                >

                @error('scheduled_at')
                    <p class="mt-1 text-sm text-red-600">
                        {{ $message }}
                    </p>
                @enderror
            </div>

            {{-- Tipo de servicio --}}
            <div>
                <label
                    for="service_type"
                    class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300"
                >
                    Tipo de servicio *
                </label>

                <select
                    id="service_type"
                    wire:model="service_type"
                    class="w-full rounded-lg border border-zinc-300 px-3 py-2 outline-none dark:border-zinc-600 dark:bg-zinc-800 dark:text-white"
                >
                    <option value="preventive">Preventivo</option>
                    <option value="corrective">Correctivo</option>
                    <option value="inspection">Inspección</option>
                    <option value="installation">Instalación</option>
                </select>

                @error('service_type')
                    <p class="mt-1 text-sm text-red-600">
                        {{ $message }}
                    </p>
                @enderror
            </div>

            {{-- Prioridad --}}
            <div>
                <label
                    for="priority"
                    class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300"
                >
                    Prioridad *
                </label>

                <select
                    id="priority"
                    wire:model="priority"
                    class="w-full rounded-lg border border-zinc-300 px-3 py-2 outline-none dark:border-zinc-600 dark:bg-zinc-800 dark:text-white"
                >
                    <option value="low">Baja</option>
                    <option value="medium">Media</option>
                    <option value="high">Alta</option>
                    <option value="urgent">Urgente</option>
                </select>

                @error('priority')
                    <p class="mt-1 text-sm text-red-600">
                        {{ $message }}
                    </p>
                @enderror
            </div>

            {{-- Descripción --}}
            <div class="md:col-span-2">
                <label
                    for="description"
                    class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300"
                >
                    Servicio solicitado *
                </label>

                <textarea
                    id="description"
                    wire:model="description"
                    rows="3"
                    maxlength="3000"
                    placeholder="Describe la falla o el mantenimiento requerido"
                    class="w-full resize-none rounded-lg border border-zinc-300 px-3 py-2 outline-none focus:border-blue-500 dark:border-zinc-600 dark:bg-zinc-800 dark:text-white"
                ></textarea>

                @error('description')
                    <p class="mt-1 text-sm text-red-600">
                        {{ $message }}
                    </p>
                @enderror
            </div>
        </div>

        {{-- Refacciones planeadas --}}
        <div class="overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700">
            <div class="flex items-center justify-between border-b border-zinc-200 p-4 dark:border-zinc-700">
                <div>
                    <h3 class="font-semibold text-zinc-900 dark:text-white">
                        Refacciones planeadas
                    </h3>

                    <p class="mt-1 text-xs text-zinc-500">
                        Es opcional agregar piezas al programar la orden.
                    </p>
                </div>

                <button
                    type="button"
                    wire:click="addItem"
                    class="rounded-lg border border-blue-400 px-3 py-2 text-sm font-semibold text-blue-600 hover:bg-blue-50 dark:hover:bg-blue-950"
                >
                    Agregar refacción
                </button>
            </div>

            @error('items')
                <p class="p-4 text-sm text-red-600">
                    {{ $message }}
                </p>
            @enderror

            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-700">
                    <thead class="bg-zinc-50 dark:bg-zinc-800">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase">
                                Refacción
                            </th>

                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase">
                                Cantidad
                            </th>

                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase">
                                Importe estimado
                            </th>

                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>

                    <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                        @forelse ($items as $index => $item)
                            @php
                                $selectedPart = $this->spareParts->firstWhere(
                                    'id',
                                    (int) ($item['spare_part_id'] ?? 0)
                                );

                                $lineTotal = $selectedPart
                                    ? (int) ($item['quantity'] ?? 0) *
                                        (float) $selectedPart->cost
                                    : 0;
                            @endphp

                            <tr wire:key="service-item-{{ $item['key'] }}">
                                <td class="px-4 py-4">
                                    <select
                                        wire:model.live="items.{{ $index }}.spare_part_id"
                                        class="w-full min-w-64 rounded-lg border border-zinc-300 px-3 py-2 dark:border-zinc-600 dark:bg-zinc-800 dark:text-white"
                                    >
                                        <option value="">
                                            Selecciona una refacción
                                        </option>

                                        @foreach ($this->spareParts as $sparePart)
                                            <option value="{{ $sparePart->id }}">
                                                {{ $sparePart->sku }} —
                                                {{ $sparePart->name }}
                                                ({{ $sparePart->stock }} disponibles)
                                            </option>
                                        @endforeach
                                    </select>

                                    @error("items.{$index}.spare_part_id")
                                        <p class="mt-1 text-sm text-red-600">
                                            {{ $message }}
                                        </p>
                                    @enderror
                                </td>

                                <td class="w-36 px-4 py-4">
                                    <input
                                        type="number"
                                        wire:model.live.debounce.200ms="items.{{ $index }}.quantity"
                                        min="1"
                                        step="1"
                                        class="w-full rounded-lg border border-zinc-300 px-3 py-2 dark:border-zinc-600 dark:bg-zinc-800 dark:text-white"
                                    >

                                    @error("items.{$index}.quantity")
                                        <p class="mt-1 text-sm text-red-600">
                                            {{ $message }}
                                        </p>
                                    @enderror
                                </td>

                                <td class="whitespace-nowrap px-4 py-4 text-right font-semibold">
                                    ${{ number_format($lineTotal, 2) }}
                                </td>

                                <td class="px-4 py-4 text-right">
                                    <button
                                        type="button"
                                        wire:click="removeItem({{ $index }})"
                                        class="text-sm font-semibold text-red-600 hover:text-red-700"
                                    >
                                        Quitar
                                    </button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td
                                    colspan="4"
                                    class="px-4 py-8 text-center text-sm text-zinc-500"
                                >
                                    No se agregaron refacciones.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="border-t border-zinc-200 p-4 text-right dark:border-zinc-700">
                <span class="text-sm text-zinc-500">
                    Costo estimado de refacciones:
                </span>

                <strong class="ml-2 text-green-600">
                    ${{ number_format($this->plannedPartsTotal, 2) }}
                </strong>
            </div>
        </div>

        {{-- Notas --}}
        <div>
            <label
                for="notes"
                class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300"
            >
                Notas
            </label>

            <textarea
                id="notes"
                wire:model="notes"
                rows="3"
                maxlength="2000"
                placeholder="Indicaciones adicionales"
                class="w-full resize-none rounded-lg border border-zinc-300 px-3 py-2 dark:border-zinc-600 dark:bg-zinc-800 dark:text-white"
            ></textarea>

            @error('notes')
                <p class="mt-1 text-sm text-red-600">
                    {{ $message }}
                </p>
            @enderror
        </div>

        {{-- Botones --}}
        <div class="flex flex-wrap gap-3">
            <button
                type="submit"
                wire:loading.attr="disabled"
                wire:target="save"
                class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700 disabled:opacity-60"
            >
                <span wire:loading.remove wire:target="save">
                    Programar orden
                </span>

                <span wire:loading wire:target="save">
                    Guardando...
                </span>
            </button>

            <button
                type="button"
                wire:click="resetForm"
                class="rounded-lg border border-zinc-300 px-4 py-2 text-sm font-semibold text-zinc-700 hover:bg-zinc-100 dark:border-zinc-600 dark:text-zinc-200 dark:hover:bg-zinc-800"
            >
                Limpiar
            </button>
        </div>
    </form>

    {{-- Historial de órdenes --}}
    <section class="overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
        <div class="border-b border-zinc-200 p-5 dark:border-zinc-700">
            <h2 class="text-lg font-semibold text-zinc-900 dark:text-white">
                Historial de órdenes
            </h2>
        </div>

        {{-- Filtros --}}
        <div class="grid gap-3 border-b border-zinc-200 p-4 dark:border-zinc-700 md:grid-cols-3">
            <input
                type="search"
                wire:model.live.debounce.300ms="search"
                placeholder="Buscar folio, elevador o técnico..."
                class="rounded-lg border border-zinc-300 px-3 py-2 dark:border-zinc-600 dark:bg-zinc-800 dark:text-white"
            >

            <select
                wire:model.live="statusFilter"
                class="rounded-lg border border-zinc-300 px-3 py-2 dark:border-zinc-600 dark:bg-zinc-800 dark:text-white"
            >
                <option value="all">Todos los estados</option>
                <option value="scheduled">Programadas</option>
                <option value="in_progress">En proceso</option>
                <option value="completed">Completadas</option>
                <option value="cancelled">Canceladas</option>
            </select>

            <select
                wire:model.live="typeFilter"
                class="rounded-lg border border-zinc-300 px-3 py-2 dark:border-zinc-600 dark:bg-zinc-800 dark:text-white"
            >
                <option value="all">Todos los tipos</option>
                <option value="preventive">Preventivo</option>
                <option value="corrective">Correctivo</option>
                <option value="inspection">Inspección</option>
                <option value="installation">Instalación</option>
            </select>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-700">
                <thead class="bg-zinc-50 dark:bg-zinc-800">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase">
                            Orden
                        </th>

                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase">
                            Elevador
                        </th>

                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase">
                            Servicio
                        </th>

                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase">
                            Técnico
                        </th>

                        <th class="px-4 py-3 text-center text-xs font-semibold uppercase">
                            Estado
                        </th>

                        <th class="px-4 py-3 text-right text-xs font-semibold uppercase">
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
                        @endphp

                        <tr wire:key="order-{{ $order->id }}">
                            <td class="px-4 py-4">
                                <p class="font-semibold">
                                    {{ $order->folio }}
                                </p>

                                <p class="mt-1 text-xs text-zinc-500">
                                    {{ $order->scheduled_at?->format('d/m/Y H:i') }}
                                </p>
                            </td>

                            <td class="px-4 py-4">
                                <p class="font-medium">
                                    {{ $order->elevator->code }}
                                </p>

                                <p class="mt-1 text-xs text-zinc-500">
                                    {{ $order->elevator->building_name }}
                                </p>
                            </td>

                            <td class="px-4 py-4 text-sm">
                                {{ $order->serviceTypeLabel() }}

                                <p class="mt-1 text-xs text-zinc-500">
                                    Prioridad: {{ $order->priorityLabel() }}
                                </p>
                            </td>

                            <td class="px-4 py-4 text-sm">
                                {{ $order->technician?->name ?? 'Sin asignar' }}
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
                                        class="rounded-lg border border-blue-400 px-3 py-2 text-sm font-semibold text-blue-600"
                                    >
                                        Ver
                                    </button>

                                    @if ($order->isScheduled())
                                        <button
                                            type="button"
                                            wire:click="startOrder({{ $order->id }})"
                                            wire:confirm="¿Confirmas que deseas iniciar esta orden?"
                                            class="rounded-lg bg-amber-500 px-3 py-2 text-sm font-semibold text-white"
                                        >
                                            Iniciar
                                        </button>
                                    @endif

                                    @if ($order->isInProgress())
                                        <button
                                            type="button"
                                            wire:click="prepareCompletion({{ $order->id }})"
                                            class="rounded-lg bg-green-600 px-3 py-2 text-sm font-semibold text-white"
                                        >
                                            Completar
                                        </button>
                                    @endif

                                    @if (! $order->isCompleted() && ! $order->isCancelled())
                                        <button
                                            type="button"
                                            wire:click="cancelOrder({{ $order->id }})"
                                            wire:confirm="¿Confirmas que deseas cancelar esta orden?"
                                            class="rounded-lg border border-red-400 px-3 py-2 text-sm font-semibold text-red-600"
                                        >
                                            Cancelar
                                        </button>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td
                                colspan="6"
                                class="px-4 py-10 text-center text-sm text-zinc-500"
                            >
                                Todavía no hay órdenes registradas.
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

    {{-- Formulario para completar la orden --}}
    @if ($this->completionOrder)
        @php
            $completionOrder = $this->completionOrder;
        @endphp

        <form
            wire:submit="completeOrder"
            class="space-y-5 rounded-xl border border-green-400 bg-white p-5 dark:bg-zinc-900"
        >
            <div>
                <h2 class="text-lg font-semibold">
                    Completar {{ $completionOrder->folio }}
                </h2>

                <p class="mt-1 text-sm text-zinc-500">
                    {{ $completionOrder->elevator->code }} ·
                    {{ $completionOrder->elevator->building_name }}
                </p>
            </div>

            @error('completion')
                <div class="rounded-lg bg-red-50 p-4 text-sm text-red-700 dark:bg-red-950">
                    {{ $message }}
                </div>
            @enderror

            <div class="grid gap-4 md:grid-cols-2">
                <div>
                    <label
                        for="diagnosis"
                        class="mb-1 block text-sm font-medium"
                    >
                        Diagnóstico *
                    </label>

                    <textarea
                        id="diagnosis"
                        wire:model="diagnosis"
                        rows="4"
                        maxlength="3000"
                        class="w-full resize-none rounded-lg border border-zinc-300 px-3 py-2 dark:border-zinc-600 dark:bg-zinc-800"
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
                        class="mb-1 block text-sm font-medium"
                    >
                        Trabajo realizado *
                    </label>

                    <textarea
                        id="work_performed"
                        wire:model="work_performed"
                        rows="4"
                        maxlength="5000"
                        class="w-full resize-none rounded-lg border border-zinc-300 px-3 py-2 dark:border-zinc-600 dark:bg-zinc-800"
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
                    class="mb-1 block text-sm font-medium"
                >
                    Costo de mano de obra
                </label>

                <input
                    id="labor_cost"
                    type="number"
                    wire:model="labor_cost"
                    min="0"
                    step="0.01"
                    class="w-full rounded-lg border border-zinc-300 px-3 py-2 dark:border-zinc-600 dark:bg-zinc-800"
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
                    class="rounded-lg bg-green-600 px-4 py-2 text-sm font-semibold text-white disabled:opacity-60"
                >
                    <span wire:loading.remove wire:target="completeOrder">
                        Confirmar y descontar inventario
                    </span>

                    <span wire:loading wire:target="completeOrder">
                        Procesando...
                    </span>
                </button>

                <button
                    type="button"
                    wire:click="cancelCompletion"
                    class="rounded-lg border border-zinc-300 px-4 py-2 text-sm font-semibold"
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

        <section class="overflow-hidden rounded-xl border border-blue-300 bg-white dark:border-blue-800 dark:bg-zinc-900">
            <div class="flex items-start justify-between gap-4 border-b border-zinc-200 p-5 dark:border-zinc-700">
                <div>
                    <h2 class="text-lg font-semibold">
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
                    class="text-sm font-semibold"
                >
                    Cerrar
                </button>
            </div>

            <div class="grid gap-4 p-5 md:grid-cols-2 xl:grid-cols-4">
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
                        Técnico
                    </p>

                    <p class="mt-1 font-semibold">
                        {{ $selectedOrder->technician?->name ?? 'Sin asignar' }}
                    </p>
                </div>

                <div>
                    <p class="text-xs uppercase text-zinc-500">
                        Costo total
                    </p>

                    <p class="mt-1 font-semibold text-green-600">
                        ${{ number_format((float) $selectedOrder->total_cost, 2) }}
                    </p>
                </div>
            </div>

            <div class="border-t border-zinc-200 p-5 dark:border-zinc-700">
                <p class="text-sm font-semibold">
                    Servicio solicitado
                </p>

                <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-300">
                    {{ $selectedOrder->description }}
                </p>

                @if ($selectedOrder->diagnosis)
                    <p class="mt-4 text-sm font-semibold">
                        Diagnóstico
                    </p>

                    <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-300">
                        {{ $selectedOrder->diagnosis }}
                    </p>
                @endif

                @if ($selectedOrder->work_performed)
                    <p class="mt-4 text-sm font-semibold">
                        Trabajo realizado
                    </p>

                    <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-300">
                        {{ $selectedOrder->work_performed }}
                    </p>
                @endif
            </div>

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