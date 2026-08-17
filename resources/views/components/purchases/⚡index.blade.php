<?php

use App\Models\InventoryMovement;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\SparePart;
use App\Models\Supplier;
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

    // Encabezado de la compra.
    public string $supplier_id = '';
    public string $folio = '';
    public string $purchase_date = '';
    public string $notes = '';
    public bool $include_tax = true;

    /**
     * Partidas de la compra.
     *
     * Cada partida contiene una refacción,
     * cantidad y costo unitario.
     */
    public array $items = [];

    // Filtros del historial.
    public string $search = '';
    public string $supplierFilter = '';
    public string $statusFilter = 'all';

    // Compra seleccionada para consultar su detalle.
    public ?int $selectedPurchaseId = null;

    /**
     * Inicializa el formulario.
     */
    public function mount(): void
    {
        $this->purchase_date = now()->toDateString();
        $this->items = [$this->emptyItem()];
    }

    /**
     * Reglas de validación.
     */
    protected function rules(): array
    {
        return [
            'supplier_id' => [
                'required',
                'integer',
                Rule::exists('suppliers', 'id')
                    ->where('is_active', true),
            ],
            'folio' => [
                'nullable',
                'string',
                'max:100',
                Rule::unique('purchases', 'folio')
                    ->where(function ($query) {
                        return $query->where(
                            'supplier_id',
                            (int) $this->supplier_id
                        );
                    }),
            ],
            'purchase_date' => [
                'required',
                'date',
                'before_or_equal:today',
            ],
            'notes' => [
                'nullable',
                'string',
                'max:2000',
            ],
            'include_tax' => [
                'boolean',
            ],
            'items' => [
                'required',
                'array',
                'min:1',
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
            'items.*.unit_cost' => [
                'required',
                'numeric',
                'min:0.01',
                'max:9999999999.99',
            ],
        ];
    }

    /**
     * Mensajes de validación en español.
     */
    protected function messages(): array
    {
        return [
            'supplier_id.required' => 'Selecciona un proveedor.',
            'supplier_id.exists' => 'El proveedor no existe o está inactivo.',

            'folio.max' => 'El folio no puede superar los 100 caracteres.',
            'folio.unique' => 'Este proveedor ya tiene una compra con el mismo folio.',

            'purchase_date.required' => 'Selecciona la fecha de compra.',
            'purchase_date.date' => 'La fecha de compra no es válida.',
            'purchase_date.before_or_equal' => 'La fecha no puede ser posterior al día de hoy.',

            'notes.max' => 'Las notas no pueden superar los 2000 caracteres.',

            'items.required' => 'Agrega por lo menos una refacción.',
            'items.min' => 'Agrega por lo menos una refacción.',
            'items.max' => 'Una compra no puede superar las 50 partidas.',

            'items.*.spare_part_id.required' => 'Selecciona una refacción.',
            'items.*.spare_part_id.exists' => 'La refacción no existe o está inactiva.',
            'items.*.spare_part_id.distinct' => 'La refacción está repetida en la compra.',

            'items.*.quantity.required' => 'Escribe la cantidad.',
            'items.*.quantity.integer' => 'La cantidad debe ser un número entero.',
            'items.*.quantity.min' => 'La cantidad debe ser mayor que cero.',
            'items.*.quantity.max' => 'La cantidad supera el límite permitido.',

            'items.*.unit_cost.required' => 'Escribe el costo unitario.',
            'items.*.unit_cost.numeric' => 'El costo debe ser un número.',
            'items.*.unit_cost.min' => 'El costo debe ser mayor que cero.',
            'items.*.unit_cost.max' => 'El costo supera el límite permitido.',
        ];
    }

    /**
     * Proveedores disponibles para compras.
     */
    #[Computed]
    public function suppliers()
    {
        return Supplier::query()
            ->active()
            ->orderBy('name')
            ->get([
                'id',
                'name',
                'tax_id',
            ]);
    }

    /**
     * Refacciones disponibles para comprar.
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
     * Calcula subtotal, IVA y total.
     */
    #[Computed]
    public function totals(): array
    {
        return $this->calculateTotals($this->items);
    }

    /**
     * Consulta el historial de compras.
     */
    #[Computed]
    public function purchases()
    {
        $search = trim($this->search);

        return Purchase::query()
            ->with([
                'supplier:id,name',
                'user:id,name',
            ])
            ->withCount('items')
            ->withSum('items as total_units', 'quantity')
            ->when($search !== '', function ($query) use ($search) {
                $term = '%' . $search . '%';

                $query->where(function ($query) use ($term) {
                    $query
                        ->where('folio', 'like', $term)
                        ->orWhereHas('supplier', function ($query) use ($term) {
                            $query->where('name', 'like', $term);
                        })
                        ->orWhereHas('items.sparePart', function ($query) use ($term) {
                            $query
                                ->where('sku', 'like', $term)
                                ->orWhere('name', 'like', $term);
                        });
                });
            })
            ->when(
                $this->supplierFilter !== '',
                fn ($query) => $query->where(
                    'supplier_id',
                    (int) $this->supplierFilter
                )
            )
            ->when(
                $this->statusFilter === Purchase::STATUS_RECEIVED,
                fn ($query) => $query->where(
                    'status',
                    Purchase::STATUS_RECEIVED
                )
            )
            ->when(
                $this->statusFilter === Purchase::STATUS_CANCELLED,
                fn ($query) => $query->where(
                    'status',
                    Purchase::STATUS_CANCELLED
                )
            )
            ->orderByDesc('purchase_date')
            ->orderByDesc('id')
            ->paginate(10);
    }

    /**
     * Obtiene el detalle de una compra seleccionada.
     */
    #[Computed]
    public function selectedPurchase(): ?Purchase
    {
        if ($this->selectedPurchaseId === null) {
            return null;
        }

        return Purchase::query()
            ->with([
                'supplier:id,name,tax_id',
                'user:id,name',
                'items.sparePart:id,sku,name',
            ])
            ->find($this->selectedPurchaseId);
    }

    /**
     * Agrega otra partida al formulario.
     */
    public function addItem(): void
    {
        if (count($this->items) >= 50) {
            $this->addError(
                'items',
                'Una compra no puede superar las 50 partidas.'
            );

            return;
        }

        $this->items[] = $this->emptyItem();

        $this->resetValidation('items');
        unset($this->totals);
    }

    /**
     * Elimina una partida del formulario.
     */
    public function removeItem(int $index): void
    {
        if (count($this->items) === 1) {
            $this->addError(
                'items',
                'La compra debe contener por lo menos una refacción.'
            );

            return;
        }

        if (! array_key_exists($index, $this->items)) {
            return;
        }

        unset($this->items[$index]);

        $this->items = array_values($this->items);

        $this->resetValidation();
        unset($this->totals);
    }

    /**
     * Registra la compra, sus partidas y las entradas
     * de inventario dentro de una sola transacción.
     */
    public function save(): void
    {
        $this->normalizeFields();

        $validated = $this->validate();
        $totals = $this->calculateTotals($validated['items']);

        if ($totals['total'] > 9999999999.99) {
            throw ValidationException::withMessages([
                'items' => 'El total de la compra supera el límite permitido.',
            ]);
        }

        $purchase = DB::transaction(function () use (
            $validated,
            $totals
        ): Purchase {
            /*
             * Bloquea al proveedor durante la operación
             * para comprobar que siga activo.
             */
            $supplier = Supplier::query()
                ->lockForUpdate()
                ->findOrFail((int) $validated['supplier_id']);

            if (! $supplier->is_active) {
                throw ValidationException::withMessages([
                    'supplier_id' => 'El proveedor seleccionado está inactivo.',
                ]);
            }

            // Crea el encabezado de la compra.
            $purchase = Purchase::create([
                'supplier_id' => $supplier->id,
                'user_id' => auth()->id(),
                'folio' => $validated['folio'] !== ''
                    ? $validated['folio']
                    : null,
                'purchase_date' => $validated['purchase_date'],
                'subtotal' => $totals['subtotal'],
                'tax' => $totals['tax'],
                'total' => $totals['total'],
                'status' => Purchase::STATUS_RECEIVED,
                'notes' => $validated['notes'] !== ''
                    ? $validated['notes']
                    : null,
            ]);

            foreach ($validated['items'] as $index => $item) {
                /*
                 * lockForUpdate evita que dos compras modifiquen
                 * la misma existencia simultáneamente.
                 */
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
                $unitCost = round((float) $item['unit_cost'], 2);
                $lineSubtotal = round($quantity * $unitCost, 2);

                $previousStock = (int) $sparePart->stock;
                $previousCost = (float) $sparePart->cost;

                if ($quantity > (999999999 - $previousStock)) {
                    throw ValidationException::withMessages([
                        "items.{$index}.quantity" =>
                            'La existencia resultante supera el límite permitido.',
                    ]);
                }

                $newStock = $previousStock + $quantity;

                /*
                 * Calcula costo promedio ponderado:
                 *
                 * valor anterior + valor comprado
                 * dividido entre las nuevas unidades.
                 */
                $newAverageCost = round(
                    (
                        ($previousStock * $previousCost) +
                        ($quantity * $unitCost)
                    ) / $newStock,
                    2
                );

                // Guarda la partida de la compra.
                PurchaseItem::create([
                    'purchase_id' => $purchase->id,
                    'spare_part_id' => $sparePart->id,
                    'quantity' => $quantity,
                    'unit_cost' => $unitCost,
                    'subtotal' => $lineSubtotal,
                ]);

                // Actualiza existencia y costo promedio.
                $sparePart->update([
                    'stock' => $newStock,
                    'cost' => $newAverageCost,
                ]);

                // Crea automáticamente la entrada en el Kárdex.
                InventoryMovement::create([
                    'spare_part_id' => $sparePart->id,
                    'user_id' => auth()->id(),
                    'purchase_id' => $purchase->id,
                    'type' => InventoryMovement::TYPE_ENTRY,
                    'quantity' => $quantity,
                    'previous_stock' => $previousStock,
                    'new_stock' => $newStock,
                    'reason' => 'Compra a proveedor: ' . $supplier->name,
                    'reference' => $purchase->folio,
                    'notes' => 'Entrada generada automáticamente por la compra #' .
                        $purchase->id . '.',
                ]);
            }

            return $purchase;
        });

        $this->resetForm();
        $this->resetPage();

        $this->selectedPurchaseId = $purchase->id;

        unset(
            $this->suppliers,
            $this->spareParts,
            $this->totals,
            $this->purchases,
            $this->selectedPurchase
        );

        session()->flash(
            'success',
            "Compra #{$purchase->id} registrada correctamente."
        );
    }

    /**
     * Muestra el detalle de una compra.
     */
    public function showDetails(int $purchaseId): void
    {
        $this->selectedPurchaseId = $purchaseId;

        unset($this->selectedPurchase);
    }

    /**
     * Cierra el detalle.
     */
    public function closeDetails(): void
    {
        $this->selectedPurchaseId = null;

        unset($this->selectedPurchase);
    }

    /**
     * Limpia el formulario.
     */
    public function resetForm(): void
    {
        $this->supplier_id = '';
        $this->folio = '';
        $this->purchase_date = now()->toDateString();
        $this->notes = '';
        $this->include_tax = true;
        $this->items = [$this->emptyItem()];

        $this->resetValidation();

        unset($this->totals);
    }

    /**
     * Crea una partida vacía con una clave única.
     */
    private function emptyItem(): array
    {
        return [
            'key' => (string) Str::uuid(),
            'spare_part_id' => '',
            'quantity' => 1,
            'unit_cost' => '0.00',
        ];
    }

    /**
     * Normaliza textos antes de validarlos.
     */
    private function normalizeFields(): void
    {
        $this->folio = Str::upper(
            Str::squish($this->folio)
        );

        $this->notes = trim($this->notes);
    }

    /**
     * Calcula importes sin confiar en valores enviados
     * directamente por el navegador.
     */
    private function calculateTotals(array $items): array
    {
        $subtotal = 0;

        foreach ($items as $item) {
            $quantity = max(
                0,
                (int) ($item['quantity'] ?? 0)
            );

            $unitCost = max(
                0,
                (float) ($item['unit_cost'] ?? 0)
            );

            $subtotal += round(
                $quantity * $unitCost,
                2
            );
        }

        $subtotal = round($subtotal, 2);

        $tax = $this->include_tax
            ? round($subtotal * 0.16, 2)
            : 0;

        return [
            'subtotal' => $subtotal,
            'tax' => $tax,
            'total' => round($subtotal + $tax, 2),
        ];
    }

    /**
     * Actualiza el historial al cambiar filtros.
     */
    public function updatedSearch(): void
    {
        $this->refreshHistory();
    }

    public function updatedSupplierFilter(): void
    {
        $this->refreshHistory();
    }

    public function updatedStatusFilter(): void
    {
        $this->refreshHistory();
    }

    private function refreshHistory(): void
    {
        $this->resetPage();

        unset($this->purchases);
    }
};

?>

<div class="mx-auto w-full max-w-7xl space-y-6 p-4 sm:p-6">
    @php
        $totals = $this->totals;
    @endphp

    {{-- Encabezado --}}
    <div>
        <h1 class="text-2xl font-bold text-zinc-900 dark:text-white">
            Compras
        </h1>

        <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">
            Registra compras y actualiza automáticamente el inventario.
        </p>
    </div>

    {{-- Confirmación --}}
    @if (session('success'))
        <div class="rounded-lg border border-green-300 bg-green-50 p-4 text-sm text-green-800 dark:border-green-700 dark:bg-green-950 dark:text-green-200">
            {{ session('success') }}
        </div>
    @endif

    {{-- Formulario de compra --}}
    <form
        wire:submit="save"
        class="space-y-6 rounded-xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900"
    >
        <div>
            <h2 class="text-lg font-semibold text-zinc-900 dark:text-white">
                Nueva compra
            </h2>

            <p class="mt-1 text-sm text-zinc-500">
                Al guardarla se generarán automáticamente las entradas del Kárdex.
            </p>
        </div>

        {{-- Datos generales --}}
        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            <div class="xl:col-span-2">
                <label
                    for="supplier_id"
                    class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300"
                >
                    Proveedor *
                </label>

                <select
                    id="supplier_id"
                    wire:model="supplier_id"
                    class="w-full rounded-lg border border-zinc-300 px-3 py-2 outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-200 dark:border-zinc-600 dark:bg-zinc-800 dark:text-white"
                >
                    <option value="">Selecciona un proveedor</option>

                    @foreach ($this->suppliers as $supplier)
                        <option value="{{ $supplier->id }}">
                            {{ $supplier->name }}
                        </option>
                    @endforeach
                </select>

                @error('supplier_id')
                    <p class="mt-1 text-sm text-red-600">
                        {{ $message }}
                    </p>
                @enderror
            </div>

            <div>
                <label
                    for="folio"
                    class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300"
                >
                    Factura o folio
                </label>

                <input
                    id="folio"
                    type="text"
                    wire:model="folio"
                    maxlength="100"
                    placeholder="Ej. FAC-001"
                    class="w-full uppercase rounded-lg border border-zinc-300 px-3 py-2 outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-200 dark:border-zinc-600 dark:bg-zinc-800 dark:text-white"
                >

                @error('folio')
                    <p class="mt-1 text-sm text-red-600">
                        {{ $message }}
                    </p>
                @enderror
            </div>

            <div>
                <label
                    for="purchase_date"
                    class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300"
                >
                    Fecha de compra *
                </label>

                <input
                    id="purchase_date"
                    type="date"
                    wire:model="purchase_date"
                    max="{{ now()->toDateString() }}"
                    class="w-full rounded-lg border border-zinc-300 px-3 py-2 outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-200 dark:border-zinc-600 dark:bg-zinc-800 dark:text-white"
                >

                @error('purchase_date')
                    <p class="mt-1 text-sm text-red-600">
                        {{ $message }}
                    </p>
                @enderror
            </div>
        </div>

        {{-- Partidas --}}
        <div class="overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700">
            <div class="flex items-center justify-between border-b border-zinc-200 p-4 dark:border-zinc-700">
                <div>
                    <h3 class="font-semibold text-zinc-900 dark:text-white">
                        Refacciones compradas
                    </h3>

                    <p class="mt-1 text-xs text-zinc-500">
                        Cada refacción puede aparecer una sola vez.
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
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase text-zinc-600 dark:text-zinc-300">
                                Refacción
                            </th>

                            <th class="w-32 px-4 py-3 text-left text-xs font-semibold uppercase text-zinc-600 dark:text-zinc-300">
                                Cantidad
                            </th>

                            <th class="w-44 px-4 py-3 text-left text-xs font-semibold uppercase text-zinc-600 dark:text-zinc-300">
                                Costo unitario
                            </th>

                            <th class="w-40 px-4 py-3 text-right text-xs font-semibold uppercase text-zinc-600 dark:text-zinc-300">
                                Importe
                            </th>

                            <th class="w-24 px-4 py-3"></th>
                        </tr>
                    </thead>

                    <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                        @foreach ($items as $index => $item)
                            @php
                                $lineSubtotal =
                                    max(0, (int) ($item['quantity'] ?? 0)) *
                                    max(0, (float) ($item['unit_cost'] ?? 0));
                            @endphp

                            <tr wire:key="purchase-item-{{ $item['key'] }}">
                                <td class="px-4 py-4">
                                    <select
                                        wire:model.live="items.{{ $index }}.spare_part_id"
                                        class="w-full min-w-64 rounded-lg border border-zinc-300 px-3 py-2 outline-none dark:border-zinc-600 dark:bg-zinc-800 dark:text-white"
                                    >
                                        <option value="">Selecciona una refacción</option>

                                        @foreach ($this->spareParts as $sparePart)
                                            <option value="{{ $sparePart->id }}">
                                                {{ $sparePart->sku }} — {{ $sparePart->name }}
                                                (Existencia: {{ $sparePart->stock }})
                                            </option>
                                        @endforeach
                                    </select>

                                    @error("items.{$index}.spare_part_id")
                                        <p class="mt-1 text-sm text-red-600">
                                            {{ $message }}
                                        </p>
                                    @enderror
                                </td>

                                <td class="px-4 py-4">
                                    <input
                                        type="number"
                                        wire:model.live.debounce.200ms="items.{{ $index }}.quantity"
                                        min="1"
                                        step="1"
                                        class="w-full rounded-lg border border-zinc-300 px-3 py-2 outline-none dark:border-zinc-600 dark:bg-zinc-800 dark:text-white"
                                    >

                                    @error("items.{$index}.quantity")
                                        <p class="mt-1 text-sm text-red-600">
                                            {{ $message }}
                                        </p>
                                    @enderror
                                </td>

                                <td class="px-4 py-4">
                                    <input
                                        type="number"
                                        wire:model.live.debounce.200ms="items.{{ $index }}.unit_cost"
                                        min="0.01"
                                        step="0.01"
                                        placeholder="0.00"
                                        class="w-full rounded-lg border border-zinc-300 px-3 py-2 outline-none dark:border-zinc-600 dark:bg-zinc-800 dark:text-white"
                                    >

                                    @error("items.{$index}.unit_cost")
                                        <p class="mt-1 text-sm text-red-600">
                                            {{ $message }}
                                        </p>
                                    @enderror
                                </td>

                                <td class="whitespace-nowrap px-4 py-4 text-right font-semibold text-zinc-900 dark:text-white">
                                    ${{ number_format($lineSubtotal, 2) }}
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
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Notas y totales --}}
        <div class="grid gap-6 lg:grid-cols-5">
            <div class="lg:col-span-3">
                <label
                    for="notes"
                    class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300"
                >
                    Notas
                </label>

                <textarea
                    id="notes"
                    wire:model="notes"
                    rows="4"
                    maxlength="2000"
                    placeholder="Información adicional de la compra"
                    class="w-full resize-none rounded-lg border border-zinc-300 px-3 py-2 outline-none dark:border-zinc-600 dark:bg-zinc-800 dark:text-white"
                ></textarea>

                @error('notes')
                    <p class="mt-1 text-sm text-red-600">
                        {{ $message }}
                    </p>
                @enderror
            </div>

            <div class="space-y-3 rounded-xl bg-zinc-50 p-5 dark:bg-zinc-800 lg:col-span-2">
                <label class="flex cursor-pointer items-center justify-between gap-3">
                    <span class="text-sm font-medium text-zinc-700 dark:text-zinc-300">
                        Agregar IVA (16%)
                    </span>

                    <input
                        type="checkbox"
                        wire:model.live="include_tax"
                        class="rounded border-zinc-300 text-blue-600 focus:ring-blue-500"
                    >
                </label>

                <div class="flex justify-between text-sm">
                    <span class="text-zinc-500">Subtotal</span>
                    <span class="font-medium text-zinc-900 dark:text-white">
                        ${{ number_format($totals['subtotal'], 2) }}
                    </span>
                </div>

                <div class="flex justify-between text-sm">
                    <span class="text-zinc-500">IVA</span>
                    <span class="font-medium text-zinc-900 dark:text-white">
                        ${{ number_format($totals['tax'], 2) }}
                    </span>
                </div>

                <div class="flex justify-between border-t border-zinc-300 pt-3 text-lg dark:border-zinc-600">
                    <span class="font-semibold text-zinc-900 dark:text-white">
                        Total
                    </span>

                    <span class="font-bold text-green-600">
                        ${{ number_format($totals['total'], 2) }}
                    </span>
                </div>
            </div>
        </div>

        {{-- Botones --}}
        <div class="flex flex-wrap gap-3">
            <button
                type="submit"
                wire:loading.attr="disabled"
                wire:target="save"
                class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-60"
            >
                <span wire:loading.remove wire:target="save">
                    Registrar compra
                </span>

                <span wire:loading wire:target="save">
                    Registrando...
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

    {{-- Historial de compras --}}
    <section class="overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
        <div class="border-b border-zinc-200 p-5 dark:border-zinc-700">
            <h2 class="text-lg font-semibold text-zinc-900 dark:text-white">
                Historial de compras
            </h2>

            <p class="mt-1 text-sm text-zinc-500">
                Consulta las compras recibidas y sus importes.
            </p>
        </div>

        {{-- Filtros --}}
        <div class="grid gap-3 border-b border-zinc-200 p-4 dark:border-zinc-700 md:grid-cols-3">
            <input
                type="search"
                wire:model.live.debounce.300ms="searchsearch"
                placeholder="Buscar por folio, proveedor o pieza..."
                class="rounded-lg border border-zinc-300 px-3 py-2 outline-none dark:border-zinc-600 dark:bg-zinc-800 dark:text-white"
            >

            <select
                wire:model.live="supplierFilter"
                class="rounded-lg border border-zinc-300 px-3 py-2 outline-none dark:border-zinc-600 dark:bg-zinc-800 dark:text-white"
            >
                <option value="">Todos los proveedores</option>

                @foreach ($this->suppliers as $supplier)
                    <option value="{{ $supplier->id }}">
                        {{ $supplier->name }}
                    </option>
                @endforeach
            </select>

            <select
                wire:model.live="statusFilter"
                class="rounded-lg border border-zinc-300 px-3 py-2 outline-none dark:border-zinc-600 dark:bg-zinc-800 dark:text-white"
            >
                <option value="all">Todos los estados</option>
                <option value="received">Recibidas</option>
                <option value="cancelled">Canceladas</option>
            </select>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-700">
                <thead class="bg-zinc-50 dark:bg-zinc-800">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase text-zinc-600 dark:text-zinc-300">
                            Compra
                        </th>

                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase text-zinc-600 dark:text-zinc-300">
                            Proveedor
                        </th>

                        <th class="px-4 py-3 text-center text-xs font-semibold uppercase text-zinc-600 dark:text-zinc-300">
                            Refacciones
                        </th>

                        <th class="px-4 py-3 text-right text-xs font-semibold uppercase text-zinc-600 dark:text-zinc-300">
                            Total
                        </th>

                        <th class="px-4 py-3 text-center text-xs font-semibold uppercase text-zinc-600 dark:text-zinc-300">
                            Estado
                        </th>

                        <th class="px-4 py-3 text-right text-xs font-semibold uppercase text-zinc-600 dark:text-zinc-300">
                            Acción
                        </th>
                    </tr>
                </thead>

                <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                    @forelse ($this->purchases as $purchase)
                        <tr wire:key="purchase-{{ $purchase->id }}">
                            <td class="px-4 py-4">
                                <p class="font-medium text-zinc-900 dark:text-white">
                                    {{ $purchase->folio ?: 'Compra #' . $purchase->id }}
                                </p>

                                <p class="mt-1 text-xs text-zinc-500">
                                    {{ $purchase->purchase_date->format('d/m/Y') }}
                                </p>
                            </td>

                            <td class="px-4 py-4">
                                <p class="font-medium text-zinc-900 dark:text-white">
                                    {{ $purchase->supplier->name }}
                                </p>

                                <p class="mt-1 text-xs text-zinc-500">
                                    Registró: {{ $purchase->user->name }}
                                </p>
                            </td>

                            <td class="px-4 py-4 text-center text-sm text-zinc-700 dark:text-zinc-300">
                                {{ $purchase->items_count }} partidas

                                <p class="mt-1 text-xs text-zinc-500">
                                    {{ (int) $purchase->total_units }} unidades
                                </p>
                            </td>

                            <td class="whitespace-nowrap px-4 py-4 text-right font-bold text-zinc-900 dark:text-white">
                                ${{ number_format((float) $purchase->total, 2) }}
                            </td>

                            <td class="px-4 py-4 text-center">
                                <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold {{ $purchase->isReceived() ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700' }}">
                                    {{ $purchase->isReceived() ? 'Recibida' : 'Cancelada' }}
                                </span>
                            </td>

                            <td class="px-4 py-4 text-right">
                                <button
                                    type="button"
                                    wire:click="showDetails({{ $purchase->id }})"
                                    class="rounded-lg border border-blue-400 px-3 py-2 text-sm font-semibold text-blue-600 hover:bg-blue-50 dark:hover:bg-blue-950"
                                >
                                    Ver detalle
                                </button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-4 py-10 text-center text-sm text-zinc-500">
                                Todavía no hay compras registradas.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="border-t border-zinc-200 p-4 dark:border-zinc-700">
            {{ $this->purchases->links() }}
        </div>
    </section>

    {{-- Detalle de la compra seleccionada --}}
    @if ($this->selectedPurchase)
        @php
            $selectedPurchase = $this->selectedPurchase;
        @endphp

        <section class="overflow-hidden rounded-xl border border-blue-300 bg-white shadow-sm dark:border-blue-800 dark:bg-zinc-900">
            <div class="flex items-start justify-between gap-4 border-b border-zinc-200 p-5 dark:border-zinc-700">
                <div>
                    <h2 class="text-lg font-semibold text-zinc-900 dark:text-white">
                        Detalle de
                        {{ $selectedPurchase->folio ?: 'compra #' . $selectedPurchase->id }}
                    </h2>

                    <p class="mt-1 text-sm text-zinc-500">
                        {{ $selectedPurchase->supplier->name }}
                        · {{ $selectedPurchase->purchase_date->format('d/m/Y') }}
                    </p>
                </div>

                <button
                    type="button"
                    wire:click="closeDetails"
                    class="text-sm font-semibold text-zinc-600 hover:text-zinc-900 dark:text-zinc-300 dark:hover:text-white"
                >
                    Cerrar
                </button>
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-700">
                    <thead class="bg-zinc-50 dark:bg-zinc-800">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase text-zinc-600 dark:text-zinc-300">
                                Refacción
                            </th>

                            <th class="px-4 py-3 text-center text-xs font-semibold uppercase text-zinc-600 dark:text-zinc-300">
                                Cantidad
                            </th>

                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase text-zinc-600 dark:text-zinc-300">
                                Costo
                            </th>

                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase text-zinc-600 dark:text-zinc-300">
                                Importe
                            </th>
                        </tr>
                    </thead>

                    <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                        @foreach ($selectedPurchase->items as $purchaseItem)
                            <tr wire:key="selected-item-{{ $purchaseItem->id }}">
                                <td class="px-4 py-4">
                                    <p class="font-medium text-zinc-900 dark:text-white">
                                        {{ $purchaseItem->sparePart->name }}
                                    </p>

                                    <p class="mt-1 text-xs text-zinc-500">
                                        {{ $purchaseItem->sparePart->sku }}
                                    </p>
                                </td>

                                <td class="px-4 py-4 text-center">
                                    {{ $purchaseItem->quantity }}
                                </td>

                                <td class="px-4 py-4 text-right">
                                    ${{ number_format((float) $purchaseItem->unit_cost, 2) }}
                                </td>

                                <td class="px-4 py-4 text-right font-semibold">
                                    ${{ number_format((float) $purchaseItem->subtotal, 2) }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="flex justify-end p-5">
                <div class="w-full max-w-sm space-y-2">
                    <div class="flex justify-between text-sm">
                        <span class="text-zinc-500">Subtotal</span>
                        <span>${{ number_format((float) $selectedPurchase->subtotal, 2) }}</span>
                    </div>

                    <div class="flex justify-between text-sm">
                        <span class="text-zinc-500">IVA</span>
                        <span>${{ number_format((float) $selectedPurchase->tax, 2) }}</span>
                    </div>

                    <div class="flex justify-between border-t border-zinc-300 pt-2 text-lg font-bold dark:border-zinc-600">
                        <span>Total</span>
                        <span class="text-green-600">
                            ${{ number_format((float) $selectedPurchase->total, 2) }}
                        </span>
                    </div>
                </div>
            </div>
        </section>
    @endif
</div>