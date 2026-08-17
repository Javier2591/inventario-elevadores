<?php

use App\Models\InventoryMovement;
use App\Models\SparePart;
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

    // Datos del formulario.
    public string $spare_part_id = '';
    public string $type = InventoryMovement::TYPE_ENTRY;
    public string $quantity = '1';
    public string $reason = '';
    public string $reference = '';
    public string $notes = '';

    // Filtros del historial.
    public string $search = '';
    public string $typeFilter = 'all';
    public string $sparePartFilter = '';

    /**
     * Reglas para registrar entradas y salidas.
     */
    protected function rules(): array
    {
        return [
            'spare_part_id' => [
                'required',
                'integer',
                Rule::exists('spare_parts', 'id')
                    ->where('is_active', true),
            ],
            'type' => [
                'required',
                Rule::in([
                    InventoryMovement::TYPE_ENTRY,
                    InventoryMovement::TYPE_EXIT,
                ]),
            ],
            'quantity' => [
                'required',
                'integer',
                'min:1',
                'max:999999999',
            ],
            'reason' => [
                'required',
                'string',
                'min:3',
                'max:150',
            ],
            'reference' => [
                'nullable',
                'string',
                'max:100',
            ],
            'notes' => [
                'nullable',
                'string',
                'max:2000',
            ],
        ];
    }

    /**
     * Mensajes de validación en español.
     */
    protected function messages(): array
    {
        return [
            'spare_part_id.required' => 'Selecciona una refacción.',
            'spare_part_id.exists' => 'La refacción seleccionada no existe o está inactiva.',

            'type.required' => 'Selecciona el tipo de movimiento.',
            'type.in' => 'El tipo de movimiento no es válido.',

            'quantity.required' => 'Escribe la cantidad.',
            'quantity.integer' => 'La cantidad debe ser un número entero.',
            'quantity.min' => 'La cantidad debe ser mayor que cero.',
            'quantity.max' => 'La cantidad supera el límite permitido.',

            'reason.required' => 'Escribe el motivo del movimiento.',
            'reason.min' => 'El motivo debe tener al menos 3 caracteres.',
            'reason.max' => 'El motivo no puede superar los 150 caracteres.',

            'reference.max' => 'La referencia no puede superar los 100 caracteres.',
            'notes.max' => 'Las notas no pueden superar los 2000 caracteres.',
        ];
    }

    /**
     * Obtiene las refacciones activas.
     */
    #[Computed]
    public function spareParts()
    {
        return SparePart::active()
            ->orderBy('name')
            ->get([
                'id',
                'sku',
                'name',
                'stock',
                'minimum_stock',
            ]);
    }

    /**
     * Obtiene la refacción seleccionada y su existencia actual.
     */
    #[Computed]
    public function selectedSparePart(): ?SparePart
    {
        if ($this->spare_part_id === '') {
            return null;
        }

        return SparePart::query()
            ->select([
                'id',
                'sku',
                'name',
                'stock',
                'minimum_stock',
                'is_active',
            ])
            ->find((int) $this->spare_part_id);
    }

    /**
     * Obtiene el historial de movimientos aplicando filtros.
     */
    #[Computed]
    public function movements()
    {
        $search = trim($this->search);

        return InventoryMovement::query()
            ->with([
                'sparePart:id,sku,name',
                'user:id,name',
            ])
            ->when($search !== '', function ($query) use ($search) {
                $term = '%' . $search . '%';

                $query->where(function ($query) use ($term) {
                    $query->where('reason', 'like', $term)
                        ->orWhere('reference', 'like', $term)
                        ->orWhere('notes', 'like', $term)
                        ->orWhereHas('sparePart', function ($query) use ($term) {
                            $query->where('sku', 'like', $term)
                                ->orWhere('name', 'like', $term);
                        })
                        ->orWhereHas('user', function ($query) use ($term) {
                            $query->where('name', 'like', $term);
                        });
                });
            })
            ->when($this->typeFilter === InventoryMovement::TYPE_ENTRY, function ($query) {
                $query->entries();
            })
            ->when($this->typeFilter === InventoryMovement::TYPE_EXIT, function ($query) {
                $query->exits();
            })
            ->when($this->sparePartFilter !== '', function ($query) {
                $query->where(
                    'spare_part_id',
                    (int) $this->sparePartFilter
                );
            })
            ->latest('id')
            ->paginate(10);
    }

    /**
     * Registra el movimiento y actualiza la existencia.
     */
    public function save(): void
    {
        // Normaliza los textos antes de validarlos.
        $this->reason = Str::squish($this->reason);
        $this->reference = Str::squish($this->reference);
        $this->notes = trim($this->notes);

        $validated = $this->validate();

        $quantity = (int) $validated['quantity'];
        $isEntry = $validated['type'] === InventoryMovement::TYPE_ENTRY;

        /*
         * La transacción garantiza que el movimiento y la actualización
         * de existencias se realicen juntos.
         */
        DB::transaction(function () use ($validated, $quantity, $isEntry): void {
            /*
             * lockForUpdate bloquea temporalmente la refacción para evitar
             * errores si dos usuarios registran movimientos simultáneamente.
             */
            $sparePart = SparePart::query()
                ->lockForUpdate()
                ->findOrFail((int) $validated['spare_part_id']);

            if (! $sparePart->is_active) {
                throw ValidationException::withMessages([
                    'spare_part_id' => 'No puedes registrar movimientos en una refacción inactiva.',
                ]);
            }

            $previousStock = (int) $sparePart->stock;

            if (! $isEntry && $quantity > $previousStock) {
                throw ValidationException::withMessages([
                    'quantity' => "Existencia insuficiente. Actualmente hay {$previousStock} unidades disponibles.",
                ]);
            }

            $newStock = $isEntry
                ? $previousStock + $quantity
                : $previousStock - $quantity;

            if ($newStock > 999999999) {
                throw ValidationException::withMessages([
                    'quantity' => 'La existencia resultante supera el límite permitido.',
                ]);
            }

            // Actualiza la existencia actual de la refacción.
            $sparePart->update([
                'stock' => $newStock,
            ]);

            // Guarda la evidencia permanente del movimiento.
            InventoryMovement::create([
                'spare_part_id' => $sparePart->id,
                'user_id' => auth()->id(),
                'type' => $validated['type'],
                'quantity' => $quantity,
                'previous_stock' => $previousStock,
                'new_stock' => $newStock,
                'reason' => $validated['reason'],
                'reference' => $validated['reference'] !== ''
                    ? $validated['reference']
                    : null,
                'notes' => $validated['notes'] !== ''
                    ? $validated['notes']
                    : null,
            ]);
        });

        $message = $isEntry
            ? 'Entrada registrada correctamente.'
            : 'Salida registrada correctamente.';

        $this->resetForm();
        $this->resetPage();

        // Obliga a Livewire a consultar nuevamente los datos.
        unset(
            $this->spareParts,
            $this->selectedSparePart,
            $this->movements
        );

        session()->flash('success', $message);
    }

    /**
     * Limpia solamente el formulario de movimientos.
     */
    public function resetForm(): void
    {
        $this->reset([
            'spare_part_id',
            'reason',
            'reference',
            'notes',
        ]);

        $this->type = InventoryMovement::TYPE_ENTRY;
        $this->quantity = '1';

        $this->resetValidation();

        unset($this->selectedSparePart);
    }

    /**
     * Regresa a la primera página al modificar filtros.
     */
    public function updatedSearch(): void
    {
        $this->refreshList();
    }

    public function updatedTypeFilter(): void
    {
        $this->refreshList();
    }

    public function updatedSparePartFilter(): void
    {
        $this->refreshList();
    }

    private function refreshList(): void
    {
        $this->resetPage();
        unset($this->movements);
    }
};
?>

<div class="mx-auto w-full max-w-7xl space-y-6 p-4 sm:p-6">
    {{-- Encabezado --}}
    <div>
        <h1 class="text-2xl font-bold text-zinc-900 dark:text-white">
            Movimientos de inventario
        </h1>

        <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">
            Registra entradas y salidas y consulta el historial del inventario.
        </p>
    </div>

    {{-- Mensaje de confirmación --}}
    @if (session('success'))
        <div class="rounded-lg border border-green-300 bg-green-50 p-4 text-sm text-green-800 dark:border-green-700 dark:bg-green-950 dark:text-green-200">
            {{ session('success') }}
        </div>
    @endif

    <div class="grid gap-6 xl:grid-cols-5">
        {{-- Formulario --}}
        <form
            wire:submit="save"
            class="space-y-5 rounded-xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900 xl:col-span-2"
        >
            <div>
                <h2 class="text-lg font-semibold text-zinc-900 dark:text-white">
                    Nuevo movimiento
                </h2>

                <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                    La existencia se actualizará automáticamente.
                </p>
            </div>

            {{-- Refacción --}}
            <div>
                <label
                    for="spare_part_id"
                    class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300"
                >
                    Refacción
                </label>

                <select
                    id="spare_part_id"
                    wire:model.live="spare_part_id"
                    class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-zinc-900 outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-200 dark:border-zinc-600 dark:bg-zinc-800 dark:text-white"
                >
                    <option value="">Selecciona una refacción</option>

                    @foreach ($this->spareParts as $sparePart)
                        <option value="{{ $sparePart->id }}">
                            {{ $sparePart->sku }} — {{ $sparePart->name }}
                            ({{ $sparePart->stock }} disponibles)
                        </option>
                    @endforeach
                </select>

                @error('spare_part_id')
                    <p class="mt-1 text-sm text-red-600">
                        {{ $message }}
                    </p>
                @enderror
            </div>

            {{-- Información de existencia --}}
            @if ($this->selectedSparePart)
                @php
                    $selectedSparePart = $this->selectedSparePart;
                    $movementQuantity = max(0, (int) $quantity);

                    $projectedStock = $type === \App\Models\InventoryMovement::TYPE_ENTRY
                        ? $selectedSparePart->stock + $movementQuantity
                        : $selectedSparePart->stock - $movementQuantity;
                @endphp

                <div class="rounded-lg border border-blue-200 bg-blue-50 p-4 dark:border-blue-800 dark:bg-blue-950">
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <p class="text-xs font-medium uppercase text-blue-700 dark:text-blue-300">
                                Existencia actual
                            </p>

                            <p class="mt-1 text-xl font-bold text-blue-900 dark:text-blue-100">
                                {{ $selectedSparePart->stock }}
                            </p>
                        </div>

                        <div>
                            <p class="text-xs font-medium uppercase text-blue-700 dark:text-blue-300">
                                Existencia resultante
                            </p>

                            <p class="mt-1 text-xl font-bold {{ $projectedStock < 0 ? 'text-red-600' : 'text-blue-900 dark:text-blue-100' }}">
                                {{ $projectedStock }}
                            </p>
                        </div>
                    </div>
                </div>
            @endif

            {{-- Tipo de movimiento --}}
            <fieldset>
                <legend class="mb-2 text-sm font-medium text-zinc-700 dark:text-zinc-300">
                    Tipo de movimiento
                </legend>

                <div class="grid grid-cols-2 gap-3">
                    <label class="flex cursor-pointer items-center gap-3 rounded-lg border p-3 {{ $type === 'entry' ? 'border-green-500 bg-green-50 dark:bg-green-950' : 'border-zinc-300 dark:border-zinc-600' }}">
                        <input
                            type="radio"
                            wire:model.live="type"
                            value="entry"
                            class="text-green-600"
                        >

                        <span class="text-sm font-semibold text-green-700 dark:text-green-300">
                            Entrada
                        </span>
                    </label>

                    <label class="flex cursor-pointer items-center gap-3 rounded-lg border p-3 {{ $type === 'exit' ? 'border-red-500 bg-red-50 dark:bg-red-950' : 'border-zinc-300 dark:border-zinc-600' }}">
                        <input
                            type="radio"
                            wire:model.live="type"
                            value="exit"
                            class="text-red-600"
                        >

                        <span class="text-sm font-semibold text-red-700 dark:text-red-300">
                            Salida
                        </span>
                    </label>
                </div>

                @error('type')
                    <p class="mt-1 text-sm text-red-600">
                        {{ $message }}
                    </p>
                @enderror
            </fieldset>

            {{-- Cantidad --}}
            <div>
                <label
                    for="quantity"
                    class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300"
                >
                    Cantidad
                </label>

                <input
                    id="quantity"
                    type="number"
                    wire:model.live.debounce.200ms="quantity"
                    min="1"
                    step="1"
                    class="w-full rounded-lg border border-zinc-300 px-3 py-2 outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-200 dark:border-zinc-600 dark:bg-zinc-800 dark:text-white"
                >

                @error('quantity')
                    <p class="mt-1 text-sm text-red-600">
                        {{ $message }}
                    </p>
                @enderror
            </div>

            {{-- Motivo --}}
            <div>
                <label
                    for="reason"
                    class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300"
                >
                    Motivo
                </label>

                <input
                    id="reason"
                    type="text"
                    wire:model="reason"
                    list="movement-reasons"
                    maxlength="150"
                    placeholder="Ej. Compra de inventario"
                    class="w-full rounded-lg border border-zinc-300 px-3 py-2 outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-200 dark:border-zinc-600 dark:bg-zinc-800 dark:text-white"
                >

                <datalist id="movement-reasons">
                    <option value="Compra de inventario">
                    <option value="Entrega a técnico">
                    <option value="Instalación en elevador">
                    <option value="Devolución de material">
                    <option value="Ajuste de inventario">
                    <option value="Pieza dañada">
                </datalist>

                @error('reason')
                    <p class="mt-1 text-sm text-red-600">
                        {{ $message }}
                    </p>
                @enderror
            </div>

            {{-- Referencia --}}
            <div>
                <label
                    for="reference"
                    class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300"
                >
                    Referencia o folio
                </label>

                <input
                    id="reference"
                    type="text"
                    wire:model="reference"
                    maxlength="100"
                    placeholder="Ej. FAC-001 u OT-025"
                    class="w-full rounded-lg border border-zinc-300 px-3 py-2 outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-200 dark:border-zinc-600 dark:bg-zinc-800 dark:text-white"
                >

                @error('reference')
                    <p class="mt-1 text-sm text-red-600">
                        {{ $message }}
                    </p>
                @enderror
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
                    placeholder="Información adicional del movimiento"
                    class="w-full resize-none rounded-lg border border-zinc-300 px-3 py-2 outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-200 dark:border-zinc-600 dark:bg-zinc-800 dark:text-white"
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
                    class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-60"
                >
                    <span wire:loading.remove wire:target="save">
                        Registrar movimiento
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

        {{-- Historial --}}
        <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-900 xl:col-span-3">
            {{-- Filtros --}}
            <div class="grid gap-3 border-b border-zinc-200 p-4 dark:border-zinc-700 md:grid-cols-3">
                <input
                    type="search"
                    wire:model.live.debounce.300ms="search"
                    placeholder="Buscar por pieza, motivo o folio..."
                    class="rounded-lg border border-zinc-300 px-3 py-2 outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-200 dark:border-zinc-600 dark:bg-zinc-800 dark:text-white"
                >

                <select
                    wire:model.live="sparePartFilter"
                    class="rounded-lg border border-zinc-300 px-3 py-2 outline-none dark:border-zinc-600 dark:bg-zinc-800 dark:text-white"
                >
                    <option value="">Todas las refacciones</option>

                    @foreach ($this->spareParts as $sparePart)
                        <option value="{{ $sparePart->id }}">
                            {{ $sparePart->sku }} — {{ $sparePart->name }}
                        </option>
                    @endforeach
                </select>

                <select
                    wire:model.live="typeFilter"
                    class="rounded-lg border border-zinc-300 px-3 py-2 outline-none dark:border-zinc-600 dark:bg-zinc-800 dark:text-white"
                >
                    <option value="all">Todos los movimientos</option>
                    <option value="entry">Entradas</option>
                    <option value="exit">Salidas</option>
                </select>
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-700">
                    <thead class="bg-zinc-50 dark:bg-zinc-800">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase text-zinc-600 dark:text-zinc-300">
                                Fecha
                            </th>

                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase text-zinc-600 dark:text-zinc-300">
                                Refacción
                            </th>

                            <th class="px-4 py-3 text-center text-xs font-semibold uppercase text-zinc-600 dark:text-zinc-300">
                                Movimiento
                            </th>

                            <th class="px-4 py-3 text-center text-xs font-semibold uppercase text-zinc-600 dark:text-zinc-300">
                                Existencias
                            </th>

                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase text-zinc-600 dark:text-zinc-300">
                                Detalles
                            </th>
                        </tr>
                    </thead>

                    <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                        @forelse ($this->movements as $movement)
                            <tr wire:key="movement-{{ $movement->id }}">
                                <td class="whitespace-nowrap px-4 py-4 text-sm text-zinc-600 dark:text-zinc-400">
                                    {{ $movement->created_at->format('d/m/Y') }}

                                    <p class="text-xs">
                                        {{ $movement->created_at->format('H:i') }}
                                    </p>
                                </td>

                                <td class="px-4 py-4">
                                    <p class="font-medium text-zinc-900 dark:text-white">
                                        {{ $movement->sparePart->name }}
                                    </p>

                                    <p class="mt-1 text-xs text-zinc-500">
                                        {{ $movement->sparePart->sku }}
                                    </p>
                                </td>

                                <td class="px-4 py-4 text-center">
                                    <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold {{ $movement->isEntry() ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700' }}">
                                        {{ $movement->isEntry() ? 'Entrada' : 'Salida' }}
                                    </span>

                                    <p class="mt-1 font-bold {{ $movement->isEntry() ? 'text-green-600' : 'text-red-600' }}">
                                        {{ $movement->isEntry() ? '+' : '-' }}{{ $movement->quantity }}
                                    </p>
                                </td>

                                <td class="whitespace-nowrap px-4 py-4 text-center text-sm text-zinc-700 dark:text-zinc-300">
                                    {{ $movement->previous_stock }}
                                    <span class="mx-1 text-zinc-400">→</span>
                                    <strong>{{ $movement->new_stock }}</strong>
                                </td>

                                <td class="px-4 py-4 text-sm text-zinc-700 dark:text-zinc-300">
                                    <p class="font-medium">
                                        {{ $movement->reason }}
                                    </p>

                                    @if ($movement->reference)
                                        <p class="mt-1 text-xs text-zinc-500">
                                            Folio: {{ $movement->reference }}
                                        </p>
                                    @endif

                                    <p class="mt-1 text-xs text-zinc-500">
                                        Registró: {{ $movement->user->name }}
                                    </p>

                                    @if ($movement->notes)
                                        <p class="mt-1 text-xs text-zinc-500">
                                            {{ $movement->notes }}
                                        </p>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-4 py-10 text-center text-sm text-zinc-500">
                                    Todavía no hay movimientos registrados.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="border-t border-zinc-200 p-4 dark:border-zinc-700">
                {{ $this->movements->links() }}
            </div>
        </div>
    </div>
</div>