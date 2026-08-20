<?php

use App\Models\Customer;
use App\Models\Sale;
use App\Models\SparePart;
use App\Models\User;
use App\Services\SaleService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    /*
    |--------------------------------------------------------------------------
    | Formulario de venta
    |--------------------------------------------------------------------------
    */

    public string $customer_id = '';
    public string $sale_date = '';
    public string $payment_method = Sale::PAYMENT_CASH;
    public string $payment_reference = '';
    public string $tax_rate = '16';
    public string $notes = '';

    /**
     * Código capturado manualmente o mediante lector.
     */
    public string $identifier = '';

    /**
     * Refacción seleccionada en el catálogo.
     */
    public string $selected_spare_part_id = '';

    /**
     * Partidas actuales del carrito.
     *
     * @var array<int, array<string, mixed>>
     */
    public array $items = [];

    /*
    |--------------------------------------------------------------------------
    | Historial y detalle
    |--------------------------------------------------------------------------
    */

    public string $salesSearch = '';
    public string $statusFilter = 'all';
    public ?int $selectedSaleId = null;

    /**
     * Inicializa la fecha y protege el componente.
     */
    public function mount(): void
    {
        $this->authorizeAdministrator();
        $this->sale_date = now()->format('Y-m-d\TH:i');
    }

    /**
     * Reglas del formulario.
     */
    protected function rules(): array
    {
        return [
            'customer_id' => [
                'required',
                'integer',
                'exists:customers,id',
            ],
            'sale_date' => [
                'required',
                'date',
            ],
            'payment_method' => [
                'required',
                Rule::in([
                    Sale::PAYMENT_CASH,
                    Sale::PAYMENT_CARD,
                    Sale::PAYMENT_TRANSFER,
                    Sale::PAYMENT_CREDIT,
                    Sale::PAYMENT_OTHER,
                ]),
            ],
            'payment_reference' => [
                'nullable',
                'string',
                'max:255',
            ],
            'tax_rate' => [
                'required',
                'numeric',
                'min:0',
                'max:100',
            ],
            'notes' => [
                'nullable',
                'string',
                'max:5000',
            ],
            'items' => [
                'required',
                'array',
                'min:1',
            ],
            'items.*.spare_part_id' => [
                'required',
                'integer',
                'distinct',
                'exists:spare_parts,id',
            ],
            'items.*.quantity' => [
                'required',
                'integer',
                'min:1',
            ],
            'items.*.unit_price' => [
                'required',
                'numeric',
                'min:0',
            ],
        ];
    }

    /**
     * Mensajes de validación.
     */
    protected function messages(): array
    {
        return [
            'customer_id.required' =>
                'Selecciona el cliente de la venta.',
            'customer_id.exists' =>
                'El cliente seleccionado no existe.',

            'sale_date.required' =>
                'Indica la fecha de la venta.',
            'sale_date.date' =>
                'La fecha de venta no es válida.',

            'payment_method.required' =>
                'Selecciona un método de pago.',
            'payment_method.in' =>
                'El método de pago seleccionado no es válido.',

            'tax_rate.required' =>
                'Indica el porcentaje de IVA.',
            'tax_rate.numeric' =>
                'El IVA debe ser un número.',
            'tax_rate.min' =>
                'El IVA no puede ser negativo.',
            'tax_rate.max' =>
                'El IVA no puede ser mayor a 100%.',

            'items.required' =>
                'Agrega al menos una refacción.',
            'items.min' =>
                'Agrega al menos una refacción.',
            'items.*.quantity.required' =>
                'Indica la cantidad.',
            'items.*.quantity.min' =>
                'La cantidad debe ser mayor que cero.',
            'items.*.unit_price.required' =>
                'Indica el precio unitario.',
            'items.*.unit_price.min' =>
                'El precio no puede ser negativo.',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Catálogos
    |--------------------------------------------------------------------------
    */

    /**
     * Clientes activos disponibles para vender.
     */
    #[Computed]
    public function customers()
    {
        return Customer::query()
            ->active()
            ->orderBy('name')
            ->get();
    }

    /**
     * Refacciones activas disponibles para selección manual.
     */
    #[Computed]
    public function availableSpareParts()
    {
        return SparePart::query()
            ->active()
            ->orderBy('name')
            ->get();
    }

    /*
    |--------------------------------------------------------------------------
    | Totales
    |--------------------------------------------------------------------------
    */

    #[Computed]
    public function subtotal(): float
    {
        return round(
            collect($this->items)->sum(
                fn (array $item): float =>
                    max(0, (int) ($item['quantity'] ?? 0))
                    * max(0, (float) ($item['unit_price'] ?? 0))
            ),
            2
        );
    }

    #[Computed]
    public function tax(): float
    {
        return round(
            $this->subtotal
                * (max(0, (float) $this->tax_rate) / 100),
            2
        );
    }

    #[Computed]
    public function total(): float
    {
        return round(
            $this->subtotal + $this->tax,
            2
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Carrito
    |--------------------------------------------------------------------------
    */

    /**
     * Busca una refacción mediante código de barras o SKU.
     */
    public function addByIdentifier(): void
    {
        $this->authorizeAdministrator();

        $identifier = trim($this->identifier);

        if ($identifier === '') {
            $this->addError(
                'identifier',
                'Escribe o escanea un código de barras o SKU.'
            );

            return;
        }

        $sparePart = SparePart::query()
            ->active()
            ->byIdentifier($identifier)
            ->first();

        if ($sparePart === null) {
            $this->addError(
                'identifier',
                "No se encontró una refacción activa con el código {$identifier}."
            );

            return;
        }

        $this->pushSparePart($sparePart);

        $this->identifier = '';
        $this->resetErrorBag('identifier');
    }

    /**
     * Agrega la refacción seleccionada en el catálogo.
     */
    public function addSelectedPart(): void
    {
        $this->authorizeAdministrator();

        if ($this->selected_spare_part_id === '') {
            $this->addError(
                'selected_spare_part_id',
                'Selecciona una refacción.'
            );

            return;
        }

        $sparePart = SparePart::query()
            ->active()
            ->find($this->selected_spare_part_id);

        if ($sparePart === null) {
            $this->addError(
                'selected_spare_part_id',
                'La refacción seleccionada no está disponible.'
            );

            return;
        }

        $this->pushSparePart($sparePart);

        $this->selected_spare_part_id = '';
        $this->resetErrorBag('selected_spare_part_id');
    }

    /**
     * Agrega una refacción o incrementa su cantidad.
     */
    private function pushSparePart(SparePart $sparePart): void
    {
        foreach ($this->items as $index => $item) {
            if (
                (int) $item['spare_part_id']
                === (int) $sparePart->id
            ) {
                $currentQuantity = (int) $item['quantity'];

                if ($currentQuantity >= (int) $sparePart->stock) {
                    $this->addError(
                        'items',
                        "No hay más existencias disponibles de {$sparePart->name}."
                    );

                    return;
                }

                $this->items[$index]['quantity'] =
                    $currentQuantity + 1;

                $this->forgetTotals();
                $this->resetErrorBag('items');

                return;
            }
        }

        if ((int) $sparePart->stock <= 0) {
            $this->addError(
                'items',
                "La refacción {$sparePart->name} no tiene existencias."
            );

            return;
        }

        /*
         * El costo se propone como precio inicial.
         * El vendedor puede capturar el precio comercial real.
         */
        $this->items[] = [
            'spare_part_id' => $sparePart->id,
            'sku' => $sparePart->sku,
            'barcode' => $sparePart->barcode,
            'name' => $sparePart->name,
            'stock' => (int) $sparePart->stock,
            'quantity' => 1,
            'unit_price' => number_format(
                (float) $sparePart->cost,
                2,
                '.',
                ''
            ),
        ];

        $this->forgetTotals();
        $this->resetErrorBag('items');
    }

    /**
     * Elimina una partida del carrito.
     */
    public function removeItem(int $index): void
    {
        if (! array_key_exists($index, $this->items)) {
            return;
        }

        unset($this->items[$index]);

        $this->items = array_values($this->items);

        $this->forgetTotals();
        $this->resetErrorBag('items');
    }

    /**
     * Recalcula totales al cambiar cantidades o precios.
     */
    public function updatedItems(): void
    {
        $this->forgetTotals();
    }

    public function updatedTaxRate(): void
    {
        $this->forgetTotals();
    }

    /*
    |--------------------------------------------------------------------------
    | Registrar venta
    |--------------------------------------------------------------------------
    */

    /**
     * Registra la venta mediante el servicio transaccional.
     */
    public function save(): void
    {
        $this->authorizeAdministrator();
        $this->normalizeFields();

        $validated = $this->validate();

        /** @var User $user */
        $user = auth()->user();

        $sale = app(SaleService::class)->register(
            user: $user,
            data: [
                'customer_id' =>
                    (int) $validated['customer_id'],
                'sale_date' =>
                    $validated['sale_date'],
                'payment_method' =>
                    $validated['payment_method'],
                'payment_reference' =>
                    $validated['payment_reference'] !== ''
                        ? $validated['payment_reference']
                        : null,
                'tax_rate' =>
                    (float) $validated['tax_rate'],
                'notes' =>
                    $validated['notes'] !== ''
                        ? $validated['notes']
                        : null,
                'items' => collect($validated['items'])
                    ->map(
                        fn (array $item): array => [
                            'spare_part_id' =>
                                (int) $item['spare_part_id'],
                            'quantity' =>
                                (int) $item['quantity'],
                            'unit_price' =>
                                round(
                                    (float) $item['unit_price'],
                                    2
                                ),
                        ]
                    )
                    ->values()
                    ->all(),
            ]
        );

        $this->resetForm();

        $this->selectedSaleId = $sale->id;

        unset($this->sales);
        unset($this->saleDetail);

        $this->resetPage();

        session()->flash(
            'success',
            "Venta {$sale->folio} registrada correctamente."
        );
    }

    /**
     * Limpia únicamente el formulario de la venta.
     */
    public function resetForm(): void
    {
        $this->reset([
            'customer_id',
            'payment_reference',
            'notes',
            'identifier',
            'selected_spare_part_id',
            'items',
        ]);

        $this->payment_method = Sale::PAYMENT_CASH;
        $this->tax_rate = '16';
        $this->sale_date = now()->format('Y-m-d\TH:i');

        $this->forgetTotals();
        $this->resetValidation();
    }

    /*
    |--------------------------------------------------------------------------
    | Historial
    |--------------------------------------------------------------------------
    */

    /**
     * Ventas registradas con filtros.
     */
    #[Computed]
    public function sales()
    {
        $search = trim($this->salesSearch);

        return Sale::query()
            ->with([
                'customer',
                'user',
            ])
            ->withCount('items')
            ->when(
                $search !== '',
                function (Builder $query) use ($search): void {
                    $term = '%' . $search . '%';

                    $query->where(
                        function (Builder $query) use ($term): void {
                            $query
                                ->where('folio', 'like', $term)
                                ->orWhereHas(
                                    'customer',
                                    fn (Builder $customerQuery) =>
                                        $customerQuery
                                            ->where('name', 'like', $term)
                                            ->orWhere('tax_id', 'like', $term)
                                )
                                ->orWhereHas(
                                    'items.sparePart',
                                    fn (Builder $partQuery) =>
                                        $partQuery
                                            ->where('name', 'like', $term)
                                            ->orWhere('sku', 'like', $term)
                                            ->orWhere('barcode', 'like', $term)
                                );
                        }
                    );
                }
            )
            ->when(
                $this->statusFilter !== 'all',
                fn (Builder $query) =>
                    $query->where(
                        'status',
                        $this->statusFilter
                    )
            )
            ->orderByDesc('sale_date')
            ->orderByDesc('id')
            ->paginate(10);
    }

    /**
     * Muestra el detalle de una venta.
     */
    public function showDetail(int $saleId): void
    {
        $this->selectedSaleId = $saleId;

        unset($this->saleDetail);
    }

    /**
     * Cierra el detalle de venta.
     */
    public function closeDetail(): void
    {
        $this->selectedSaleId = null;

        unset($this->saleDetail);
    }

    /**
     * Venta seleccionada con todas sus relaciones.
     */
    #[Computed]
    public function saleDetail(): ?Sale
    {
        if ($this->selectedSaleId === null) {
            return null;
        }

        return Sale::query()
            ->with([
                'customer',
                'user',
                'cancelledBy',
                'items.sparePart',
                'inventoryMovements',
            ])
            ->find($this->selectedSaleId);
    }

    /**
     * Cancela una venta y devuelve las existencias.
     */
    public function cancelSale(int $saleId): void
    {
        $this->authorizeAdministrator();

        $sale = Sale::query()
            ->findOrFail($saleId);

        /** @var User $user */
        $user = auth()->user();

        app(SaleService::class)->cancel(
            sale: $sale,
            user: $user
        );

        unset($this->sales);
        unset($this->saleDetail);

        session()->flash(
            'success',
            "Venta {$sale->folio} cancelada y existencias devueltas."
        );
    }

    /**
     * Reinicia la paginación al cambiar filtros.
     */
    public function updatedSalesSearch(): void
    {
        $this->refreshSales();
    }

    public function updatedStatusFilter(): void
    {
        $this->refreshSales();
    }

    private function refreshSales(): void
    {
        $this->resetPage();

        unset($this->sales);
    }

    /*
    |--------------------------------------------------------------------------
    | Seguridad y normalización
    |--------------------------------------------------------------------------
    */

    private function authorizeAdministrator(): void
    {
        $user = auth()->user();

        abort_unless(
            $user instanceof User
                && $user->isAdmin()
                && $user->canAccessSystem(),
            403,
            'No tienes permisos para administrar ventas.'
        );
    }

    private function normalizeFields(): void
    {
        $this->payment_reference = Str::squish(
            $this->payment_reference
        );

        $this->notes = trim($this->notes);
    }

    private function forgetTotals(): void
    {
        unset($this->subtotal);
        unset($this->tax);
        unset($this->total);
    }
};

?>

<div class="mx-auto w-full max-w-7xl space-y-6 p-4 sm:p-6">
    {{-- Encabezado --}}
    <div>
        <h1 class="text-2xl font-bold text-zinc-900 dark:text-white">
            Ventas
        </h1>

        <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">
            Registra ventas de refacciones y descuenta automáticamente el inventario.
        </p>
    </div>

    {{-- Mensajes --}}
    @if (session('success'))
        <div class="rounded-lg border border-green-300 bg-green-50 p-4 text-sm text-green-800 dark:border-green-700 dark:bg-green-950 dark:text-green-200">
            {{ session('success') }}
        </div>
    @endif

    {{-- Formulario de venta --}}
    <form
        wire:submit="save"
        class="space-y-6 rounded-xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900"
    >
        <div>
            <h2 class="text-lg font-semibold text-zinc-900 dark:text-white">
                Nueva venta
            </h2>

            <p class="mt-1 text-sm text-zinc-500">
                Los importes y las existencias se validarán nuevamente en el servidor.
            </p>
        </div>

        {{-- Cliente, fecha y método de pago --}}
        <div class="grid gap-4 lg:grid-cols-3">
            <div>
                <label
                    for="sale-customer"
                    class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300"
                >
                    Cliente *
                </label>

                <select
                    id="sale-customer"
                    wire:model="customer_id"
                    class="w-full rounded-lg border border-zinc-300 px-3 py-2 outline-none focus:border-blue-500 dark:border-zinc-600 dark:bg-zinc-800 dark:text-white"
                >
                    <option value="">Selecciona un cliente</option>

                    @foreach ($this->customers as $customer)
                        <option value="{{ $customer->id }}">
                            {{ $customer->name }}
                        </option>
                    @endforeach
                </select>

                @error('customer_id')
                    <p class="mt-1 text-sm text-red-600">
                        {{ $message }}
                    </p>
                @enderror
            </div>

            <div>
                <label
                    for="sale-date"
                    class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300"
                >
                    Fecha de venta *
                </label>

                <input
                    id="sale-date"
                    type="datetime-local"
                    wire:model="sale_date"
                    class="w-full rounded-lg border border-zinc-300 px-3 py-2 outline-none focus:border-blue-500 dark:border-zinc-600 dark:bg-zinc-800 dark:text-white"
                >

                @error('sale_date')
                    <p class="mt-1 text-sm text-red-600">
                        {{ $message }}
                    </p>
                @enderror
            </div>

            <div>
                <label
                    for="payment-method"
                    class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300"
                >
                    Método de pago *
                </label>

                <select
                    id="payment-method"
                    wire:model.live="payment_method"
                    class="w-full rounded-lg border border-zinc-300 px-3 py-2 outline-none focus:border-blue-500 dark:border-zinc-600 dark:bg-zinc-800 dark:text-white"
                >
                    <option value="{{ Sale::PAYMENT_CASH }}">
                        Efectivo
                    </option>

                    <option value="{{ Sale::PAYMENT_CARD }}">
                        Tarjeta
                    </option>

                    <option value="{{ Sale::PAYMENT_TRANSFER }}">
                        Transferencia
                    </option>

                    <option value="{{ Sale::PAYMENT_CREDIT }}">
                        Crédito
                    </option>

                    <option value="{{ Sale::PAYMENT_OTHER }}">
                        Otro
                    </option>
                </select>

                @error('payment_method')
                    <p class="mt-1 text-sm text-red-600">
                        {{ $message }}
                    </p>
                @enderror
            </div>
        </div>

        {{-- Referencia --}}
        @if ($payment_method !== Sale::PAYMENT_CASH)
            <div>
                <label
                    for="payment-reference"
                    class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300"
                >
                    Referencia de pago
                </label>

                <input
                    id="payment-reference"
                    type="text"
                    wire:model="payment_reference"
                    maxlength="255"
                    placeholder="Ej. autorización, transferencia o folio"
                    class="w-full rounded-lg border border-zinc-300 px-3 py-2 outline-none focus:border-blue-500 dark:border-zinc-600 dark:bg-zinc-800 dark:text-white"
                >
            </div>
        @endif

        {{-- Captura por código --}}
        <div class="rounded-xl border border-blue-200 bg-blue-50 p-4 dark:border-blue-900 dark:bg-blue-950/40">
            <h3 class="font-semibold text-zinc-900 dark:text-white">
                Agregar refacción
            </h3>

            <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">
                Escanea el código, escribe el SKU o selecciona una refacción.
            </p>

            <div class="mt-4 grid gap-3 lg:grid-cols-2">
                <div>
                    <div class="flex gap-2">
                        <input
                            type="text"
                            wire:model="identifier"
                            wire:keydown.enter.prevent="addByIdentifier"
                            autocomplete="off"
                            placeholder="Código de barras o SKU"
                            class="min-w-0 flex-1 rounded-lg border border-zinc-300 px-3 py-2 outline-none focus:border-blue-500 dark:border-zinc-600 dark:bg-zinc-800 dark:text-white"
                        >

                        <button
                            type="button"
                            wire:click="addByIdentifier"
                            class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700"
                        >
                            Agregar
                        </button>
                    </div>

                    @error('identifier')
                        <p class="mt-1 text-sm text-red-600">
                            {{ $message }}
                        </p>
                    @enderror
                </div>

                <div>
                    <div class="flex gap-2">
                        <select
                            wire:model="selected_spare_part_id"
                            class="min-w-0 flex-1 rounded-lg border border-zinc-300 px-3 py-2 outline-none dark:border-zinc-600 dark:bg-zinc-800 dark:text-white"
                        >
                            <option value="">
                                Selecciona una refacción
                            </option>

                            @foreach ($this->availableSpareParts as $sparePart)
                                <option value="{{ $sparePart->id }}">
                                    {{ $sparePart->sku }} ·
                                    {{ $sparePart->name }} ·
                                    Existencia: {{ $sparePart->stock }}
                                </option>
                            @endforeach
                        </select>

                        <button
                            type="button"
                            wire:click="addSelectedPart"
                            class="rounded-lg border border-blue-500 px-4 py-2 text-sm font-semibold text-blue-600 hover:bg-blue-100 dark:hover:bg-blue-950"
                        >
                            Añadir
                        </button>
                    </div>

                    @error('selected_spare_part_id')
                        <p class="mt-1 text-sm text-red-600">
                            {{ $message }}
                        </p>
                    @enderror
                </div>
            </div>
        </div>

        {{-- Carrito --}}
        <div class="overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700">
            <div class="border-b border-zinc-200 p-4 dark:border-zinc-700">
                <h3 class="font-semibold text-zinc-900 dark:text-white">
                    Refacciones vendidas
                </h3>
            </div>

            @error('items')
                <p class="m-4 rounded-lg bg-red-50 p-3 text-sm text-red-700 dark:bg-red-950 dark:text-red-300">
                    {{ $message }}
                </p>
            @enderror

            <div class="overflow-x-auto">
                <table class="min-w-full">
                    <thead class="bg-zinc-50 dark:bg-zinc-800">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase text-zinc-600 dark:text-zinc-300">
                                Refacción
                            </th>

                            <th class="px-4 py-3 text-center text-xs font-semibold uppercase text-zinc-600 dark:text-zinc-300">
                                Existencia
                            </th>

                            <th class="px-4 py-3 text-center text-xs font-semibold uppercase text-zinc-600 dark:text-zinc-300">
                                Cantidad
                            </th>

                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase text-zinc-600 dark:text-zinc-300">
                                Precio
                            </th>

                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase text-zinc-600 dark:text-zinc-300">
                                Importe
                            </th>

                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase text-zinc-600 dark:text-zinc-300">
                                Acción
                            </th>
                        </tr>
                    </thead>

                    <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                        @forelse ($items as $index => $item)
                            <tr wire:key="sale-item-{{ $item['spare_part_id'] }}">
                                <td class="px-4 py-4">
                                    <p class="font-medium text-zinc-900 dark:text-white">
                                        {{ $item['name'] }}
                                    </p>

                                    <p class="mt-1 text-xs text-zinc-500">
                                        SKU: {{ $item['sku'] }}
                                    </p>
                                </td>

                                <td class="px-4 py-4 text-center text-sm text-zinc-700 dark:text-zinc-300">
                                    {{ $item['stock'] }}
                                </td>

                                <td class="px-4 py-4">
                                    <input
                                        type="number"
                                        min="1"
                                        max="{{ $item['stock'] }}"
                                        wire:model.live.debounce.250ms="items.{{ $index }}.quantity"
                                        class="mx-auto block w-24 rounded-lg border border-zinc-300 px-3 py-2 text-center dark:border-zinc-600 dark:bg-zinc-800 dark:text-white"
                                    >

                                    @error("items.{$index}.quantity")
                                        <p class="mt-1 text-xs text-red-600">
                                            {{ $message }}
                                        </p>
                                    @enderror
                                </td>

                                <td class="px-4 py-4">
                                    <input
                                        type="number"
                                        min="0"
                                        step="0.01"
                                        inputmode="decimal"
                                        wire:model.live.debounce.250ms="items.{{ $index }}.unit_price"
                                        class="ms-auto block w-32 rounded-lg border border-zinc-300 px-3 py-2 text-right dark:border-zinc-600 dark:bg-zinc-800 dark:text-white"
                                    >

                                    @error("items.{$index}.unit_price")
                                        <p class="mt-1 text-xs text-red-600">
                                            {{ $message }}
                                        </p>
                                    @enderror
                                </td>

                                <td class="px-4 py-4 text-right font-semibold text-zinc-900 dark:text-white">
                                    ${{ number_format(
                                        (int) $item['quantity']
                                            * (float) $item['unit_price'],
                                        2
                                    ) }}
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
                                    colspan="6"
                                    class="px-4 py-10 text-center text-sm text-zinc-500"
                                >
                                    Escanea o selecciona una refacción para comenzar.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Notas y resumen --}}
        <div class="grid gap-6 lg:grid-cols-5">
            <div class="lg:col-span-3">
                <label
                    for="sale-notes"
                    class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300"
                >
                    Notas
                </label>

                <textarea
                    id="sale-notes"
                    wire:model="notes"
                    rows="4"
                    maxlength="5000"
                    placeholder="Información adicional de la venta"
                    class="w-full resize-none rounded-lg border border-zinc-300 px-3 py-2 dark:border-zinc-600 dark:bg-zinc-800 dark:text-white"
                ></textarea>
            </div>

            <div class="space-y-3 rounded-xl bg-zinc-100 p-5 dark:bg-zinc-800 lg:col-span-2">
                <div class="flex items-center justify-between">
                    <label for="sale-tax-rate" class="text-sm text-zinc-600 dark:text-zinc-400">
                        IVA
                    </label>

                    <div class="flex items-center gap-2">
                        <input
                            id="sale-tax-rate"
                            type="number"
                            min="0"
                            max="100"
                            step="0.01"
                            wire:model.live.debounce.250ms="tax_rate"
                            class="w-20 rounded-lg border border-zinc-300 px-2 py-1 text-right dark:border-zinc-600 dark:bg-zinc-900 dark:text-white"
                        >

                        <span>%</span>
                    </div>
                </div>

                <div class="flex justify-between text-sm">
                    <span class="text-zinc-500">Subtotal</span>
                    <span>${{ number_format($this->subtotal, 2) }}</span>
                </div>

                <div class="flex justify-between text-sm">
                    <span class="text-zinc-500">IVA</span>
                    <span>${{ number_format($this->tax, 2) }}</span>
                </div>

                <div class="border-t border-zinc-300 pt-3 dark:border-zinc-600">
                    <div class="flex justify-between text-xl font-bold">
                        <span>Total</span>
                        <span class="text-green-600">
                            ${{ number_format($this->total, 2) }}
                        </span>
                    </div>
                </div>
            </div>
        </div>

        {{-- Botones --}}
        <div class="flex flex-wrap gap-3">
            <button
                type="submit"
                wire:loading.attr="disabled"
                wire:target="save"
                class="rounded-lg bg-blue-600 px-5 py-2.5 font-semibold text-white hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-60"
            >
                <span wire:loading.remove wire:target="save">
                    Registrar venta
                </span>

                <span wire:loading wire:target="save">
                    Registrando...
                </span>
            </button>

            <button
                type="button"
                wire:click="resetForm"
                class="rounded-lg border border-zinc-400 px-5 py-2.5 font-semibold text-zinc-700 dark:text-zinc-200"
            >
                Limpiar
            </button>
        </div>
    </form>

    {{-- Historial --}}
    <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
        <div class="border-b border-zinc-200 p-5 dark:border-zinc-700">
            <h2 class="text-lg font-semibold text-zinc-900 dark:text-white">
                Historial de ventas
            </h2>
        </div>

        <div class="grid gap-3 border-b border-zinc-200 p-4 dark:border-zinc-700 md:grid-cols-2">
            <input
                type="search"
                wire:model.live.debounce.300ms="salesSearch"
                placeholder="Buscar folio, cliente o refacción..."
                class="rounded-lg border border-zinc-300 px-3 py-2 dark:border-zinc-600 dark:bg-zinc-800 dark:text-white"
            >

            <select
                wire:model.live="statusFilter"
                class="rounded-lg border border-zinc-300 px-3 py-2 dark:border-zinc-600 dark:bg-zinc-800 dark:text-white"
            >
                <option value="all">Todos los estados</option>
                <option value="{{ Sale::STATUS_COMPLETED }}">Completadas</option>
                <option value="{{ Sale::STATUS_CANCELLED }}">Canceladas</option>
                <option value="{{ Sale::STATUS_PENDING }}">Pendientes</option>
            </select>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full">
                <thead class="bg-zinc-50 dark:bg-zinc-800">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase">
                            Venta
                        </th>

                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase">
                            Cliente
                        </th>

                        <th class="px-4 py-3 text-center text-xs font-semibold uppercase">
                            Partidas
                        </th>

                        <th class="px-4 py-3 text-right text-xs font-semibold uppercase">
                            Total
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
                    @forelse ($this->sales as $sale)
                        <tr wire:key="sale-{{ $sale->id }}">
                            <td class="px-4 py-4">
                                <p class="font-semibold text-zinc-900 dark:text-white">
                                    {{ $sale->folio }}
                                </p>

                                <p class="mt-1 text-xs text-zinc-500">
                                    {{ $sale->sale_date?->format('d/m/Y H:i') }}
                                </p>
                            </td>

                            <td class="px-4 py-4">
                                {{ $sale->customer->name }}
                            </td>

                            <td class="px-4 py-4 text-center">
                                {{ $sale->items_count }}
                            </td>

                            <td class="px-4 py-4 text-right font-semibold">
                                ${{ number_format((float) $sale->total, 2) }}
                            </td>

                            <td class="px-4 py-4 text-center">
                                <span @class([
                                    'inline-flex rounded-full px-2.5 py-1 text-xs font-semibold',
                                    'bg-green-100 text-green-700' =>
                                        $sale->status === Sale::STATUS_COMPLETED,
                                    'bg-red-100 text-red-700' =>
                                        $sale->status === Sale::STATUS_CANCELLED,
                                    'bg-yellow-100 text-yellow-700' =>
                                        $sale->status === Sale::STATUS_PENDING,
                                ])>
                                    {{ $sale->statusLabel() }}
                                </span>
                            </td>

                            <td class="px-4 py-4">
                                <div class="flex justify-end gap-2">
                                    <button
                                        type="button"
                                        wire:click="showDetail({{ $sale->id }})"
                                        class="rounded-lg border border-blue-500 px-3 py-2 text-sm font-semibold text-blue-600"
                                    >
                                        Ver
                                    </button>

                                    <a
                                        href="{{ route('sales.receipt', ['sale' => $sale->id]) }}"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        class="inline-flex items-center justify-center rounded-lg border border-emerald-500 px-3 py-2 text-sm font-semibold text-emerald-600 transition hover:bg-emerald-500 hover:text-white"
                                    >
                                        Comprobante
                                    </a>

                                    @if ($sale->canBeCancelled())
                                        <button
                                            type="button"
                                            wire:click="cancelSale({{ $sale->id }})"
                                            wire:confirm="¿Confirmas la cancelación? Las existencias serán devueltas."
                                            class="rounded-lg border border-red-500 px-3 py-2 text-sm font-semibold text-red-600"
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
                                No se encontraron ventas.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="border-t border-zinc-200 p-4 dark:border-zinc-700">
            {{ $this->sales->links() }}
        </div>
    </div>

    {{-- Detalle --}}
    @if ($this->saleDetail)
        <div class="overflow-hidden rounded-xl border border-blue-500 bg-white dark:bg-zinc-900">
            <div class="flex items-start justify-between border-b border-zinc-200 p-5 dark:border-zinc-700">
                <div>
                    <h2 class="text-xl font-bold">
                        {{ $this->saleDetail->folio }}
                    </h2>

                    <p class="mt-1 text-sm text-zinc-500">
                        {{ $this->saleDetail->customer->name }}
                    </p>
                </div>

                <div class="flex flex-wrap items-center justify-end gap-2">
                    <a
                        href="{{ route('sales.receipt', ['sale' => $this->saleDetail->id]) }}"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="inline-flex items-center justify-center rounded-lg border border-emerald-500 px-3 py-2 text-sm font-semibold text-emerald-600 transition hover:bg-emerald-500 hover:text-white"
                    >
                        Comprobante
                    </a>

                    <button
                        type="button"
                        wire:click="closeDetail"
                        class="rounded-lg border border-zinc-400 px-3 py-2 text-sm font-semibold text-zinc-700 dark:text-zinc-200"
                    >
                        Cerrar
                    </button>
                </div>
            </div>

            <div class="grid gap-4 border-b border-zinc-200 p-5 dark:border-zinc-700 md:grid-cols-4">
                <div>
                    <p class="text-xs uppercase text-zinc-500">Fecha</p>
                    <p class="font-semibold">
                        {{ $this->saleDetail->sale_date?->format('d/m/Y H:i') }}
                    </p>
                </div>

                <div>
                    <p class="text-xs uppercase text-zinc-500">Vendedor</p>
                    <p class="font-semibold">
                        {{ $this->saleDetail->user->name }}
                    </p>
                </div>

                <div>
                    <p class="text-xs uppercase text-zinc-500">Pago</p>
                    <p class="font-semibold">
                        {{ $this->saleDetail->paymentMethodLabel() }}
                    </p>
                </div>

                <div>
                    <p class="text-xs uppercase text-zinc-500">Estado</p>
                    <p class="font-semibold">
                        {{ $this->saleDetail->statusLabel() }}
                    </p>
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-full">
                    <thead class="bg-zinc-50 dark:bg-zinc-800">
                        <tr>
                            <th class="px-5 py-3 text-left text-xs uppercase">
                                Refacción
                            </th>

                            <th class="px-5 py-3 text-center text-xs uppercase">
                                Cantidad
                            </th>

                            <th class="px-5 py-3 text-right text-xs uppercase">
                                Precio
                            </th>

                            <th class="px-5 py-3 text-right text-xs uppercase">
                                Importe
                            </th>
                        </tr>
                    </thead>

                    <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                        @foreach ($this->saleDetail->items as $item)
                            <tr>
                                <td class="px-5 py-4">
                                    {{ $item->sparePart->name }}

                                    <p class="text-xs text-zinc-500">
                                        {{ $item->sparePart->sku }}
                                    </p>
                                </td>

                                <td class="px-5 py-4 text-center">
                                    {{ $item->quantity }}
                                </td>

                                <td class="px-5 py-4 text-right">
                                    ${{ number_format((float) $item->unit_price, 2) }}
                                </td>

                                <td class="px-5 py-4 text-right font-semibold">
                                    ${{ number_format((float) $item->subtotal, 2) }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="flex justify-end p-5">
                <div class="w-full max-w-sm space-y-2">
                    <div class="flex justify-between">
                        <span>Subtotal</span>
                        <span>
                            ${{ number_format((float) $this->saleDetail->subtotal, 2) }}
                        </span>
                    </div>

                    <div class="flex justify-between">
                        <span>
                            IVA ({{ number_format((float) $this->saleDetail->tax_rate, 2) }}%)
                        </span>

                        <span>
                            ${{ number_format((float) $this->saleDetail->tax, 2) }}
                        </span>
                    </div>

                    <div class="flex justify-between border-t pt-2 text-xl font-bold">
                        <span>Total</span>

                        <span class="text-green-600">
                            ${{ number_format((float) $this->saleDetail->total, 2) }}
                        </span>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>