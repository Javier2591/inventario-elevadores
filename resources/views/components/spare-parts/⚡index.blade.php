<?php

use App\Models\Category;
use App\Models\SparePart;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    // Datos del formulario.
    public string $category_id = '';
    public string $sku = '';
    public string $name = '';
    public string $description = '';
    public string $brand = '';
    public string $stock = '0';
    public string $minimum_stock = '0';
    public string $location = '';
    public string $cost = '0.00';
    public bool $is_active = true;

    // Filtros del listado.
    public string $search = '';
    public string $categoryFilter = '';
    public string $statusFilter = 'all';

    /**
     * Evita que el identificador se modifique desde el navegador.
     */
    #[Locked]
    public ?int $editingSparePartId = null;

    /**
     * Reglas para registrar y actualizar refacciones.
     */
    protected function rules(): array
    {
        $skuRule = Rule::unique('spare_parts', 'sku');

        if ($this->editingSparePartId !== null) {
            $skuRule->ignore($this->editingSparePartId);
        }

        return [
            'category_id' => [
                'required',
                'integer',
                'exists:categories,id',
            ],
            'sku' => [
                'required',
                'string',
                'max:50',
                'regex:/^[A-Z0-9._-]+$/',
                $skuRule,
            ],
            'name' => [
                'required',
                'string',
                'min:3',
                'max:150',
            ],
            'description' => [
                'nullable',
                'string',
                'max:2000',
            ],
            'brand' => [
                'nullable',
                'string',
                'max:100',
            ],
            'stock' => [
                'required',
                'integer',
                'min:0',
                'max:999999999',
            ],
            'minimum_stock' => [
                'required',
                'integer',
                'min:0',
                'max:999999999',
            ],
            'location' => [
                'nullable',
                'string',
                'max:100',
            ],
            'cost' => [
                'required',
                'numeric',
                'decimal:0,2',
                'min:0',
                'max:9999999999.99',
            ],
            'is_active' => [
                'boolean',
            ],
        ];
    }

    /**
     * Mensajes de validación en español.
     */
    protected function messages(): array
    {
        return [
            'category_id.required' => 'Selecciona una categoría.',
            'category_id.exists' => 'La categoría seleccionada no existe.',

            'sku.required' => 'Escribe el código o SKU.',
            'sku.max' => 'El SKU no puede superar los 50 caracteres.',
            'sku.regex' => 'El SKU solamente puede contener letras, números, puntos, guiones y guiones bajos.',
            'sku.unique' => 'Ya existe una refacción con ese SKU.',

            'name.required' => 'Escribe el nombre de la refacción.',
            'name.min' => 'El nombre debe tener al menos 3 caracteres.',
            'name.max' => 'El nombre no puede superar los 150 caracteres.',

            'description.max' => 'La descripción no puede superar los 2000 caracteres.',
            'brand.max' => 'La marca no puede superar los 100 caracteres.',

            'stock.required' => 'Escribe la existencia actual.',
            'stock.integer' => 'La existencia debe ser un número entero.',
            'stock.min' => 'La existencia no puede ser negativa.',

            'minimum_stock.required' => 'Escribe la existencia mínima.',
            'minimum_stock.integer' => 'La existencia mínima debe ser un número entero.',
            'minimum_stock.min' => 'La existencia mínima no puede ser negativa.',

            'location.max' => 'La ubicación no puede superar los 100 caracteres.',

            'cost.required' => 'Escribe el costo de la refacción.',
            'cost.numeric' => 'El costo debe ser una cantidad numérica.',
            'cost.decimal' => 'El costo puede tener un máximo de dos decimales.',
            'cost.min' => 'El costo no puede ser negativo.',
        ];
    }

    /**
     * Obtiene las categorías activas para el formulario y los filtros.
     */
    #[Computed]
    public function categories()
    {
        return Category::active()
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    /**
     * Obtiene las refacciones aplicando búsqueda, filtros y paginación.
     */
    #[Computed]
    public function spareParts()
    {
        return SparePart::query()
            ->with('category:id,name')
            ->when($this->search !== '', function ($query) {
                $search = '%' . trim($this->search) . '%';

                $query->where(function ($query) use ($search) {
                    $query->where('sku', 'like', $search)
                        ->orWhere('name', 'like', $search)
                        ->orWhere('brand', 'like', $search)
                        ->orWhere('location', 'like', $search)
                        ->orWhereHas('category', function ($query) use ($search) {
                            $query->where('name', 'like', $search);
                        });
                });
            })
            ->when($this->categoryFilter !== '', function ($query) {
                $query->where('category_id', $this->categoryFilter);
            })
            ->when($this->statusFilter === 'active', function ($query) {
                $query->where('is_active', true);
            })
            ->when($this->statusFilter === 'inactive', function ($query) {
                $query->where('is_active', false);
            })
            ->when($this->statusFilter === 'low_stock', function ($query) {
                $query->where('minimum_stock', '>', 0)
                    ->whereColumn('stock', '<=', 'minimum_stock');
            })
            ->orderBy('name')
            ->paginate(10);
    }

    /**
     * Registra una refacción nueva o actualiza una existente.
     */
    public function save(): void
    {
        // Normaliza textos antes de validarlos.
        $this->sku = Str::upper(trim($this->sku));
        $this->name = Str::squish($this->name);
        $this->description = trim($this->description);
        $this->brand = Str::squish($this->brand);
        $this->location = Str::squish($this->location);

        $validated = $this->validate();

        // Convierte los campos al tipo que necesita la base de datos.
        $validated['category_id'] = (int) $validated['category_id'];
        $validated['stock'] = (int) $validated['stock'];
        $validated['minimum_stock'] = (int) $validated['minimum_stock'];
        $validated['cost'] = number_format((float) $validated['cost'], 2, '.', '');

        // Los textos vacíos se guardan como NULL.
        foreach (['description', 'brand', 'location'] as $field) {
            $validated[$field] = $validated[$field] !== ''
                ? $validated[$field]
                : null;
        }

        $wasEditing = $this->editingSparePartId !== null;

        $sparePart = $wasEditing
            ? SparePart::findOrFail($this->editingSparePartId)
            : new SparePart();

        $sparePart->fill($validated);
        $sparePart->save();

        $this->resetForm();

        // Obliga a Livewire a consultar nuevamente la tabla.
        unset($this->spareParts);

        session()->flash(
            'success',
            $wasEditing
                ? 'Refacción actualizada correctamente.'
                : 'Refacción registrada correctamente.'
        );
    }

    /**
     * Carga una refacción en el formulario para editarla.
     */
    public function edit(int $sparePartId): void
    {
        $sparePart = SparePart::findOrFail($sparePartId);

        $this->editingSparePartId = $sparePart->id;
        $this->category_id = (string) $sparePart->category_id;
        $this->sku = $sparePart->sku;
        $this->name = $sparePart->name;
        $this->description = $sparePart->description ?? '';
        $this->brand = $sparePart->brand ?? '';
        $this->stock = (string) $sparePart->stock;
        $this->minimum_stock = (string) $sparePart->minimum_stock;
        $this->location = $sparePart->location ?? '';
        $this->cost = (string) $sparePart->cost;
        $this->is_active = $sparePart->is_active;

        $this->resetValidation();
    }

    /**
     * Activa o desactiva una refacción sin borrar su historial.
     */
    public function toggleStatus(int $sparePartId): void
    {
        $sparePart = SparePart::findOrFail($sparePartId);
        $newStatus = ! $sparePart->is_active;

        $sparePart->update([
            'is_active' => $newStatus,
        ]);

        if ($this->editingSparePartId === $sparePart->id) {
            $this->is_active = $newStatus;
        }

        unset($this->spareParts);

        session()->flash(
            'success',
            $newStatus
                ? 'Refacción activada correctamente.'
                : 'Refacción desactivada correctamente.'
        );
    }

    /**
     * Limpia el formulario y regresa al modo de registro.
     */
    public function resetForm(): void
    {
        $this->reset([
            'category_id',
            'sku',
            'name',
            'description',
            'brand',
            'location',
            'editingSparePartId',
        ]);

        $this->stock = '0';
        $this->minimum_stock = '0';
        $this->cost = '0.00';
        $this->is_active = true;

        $this->resetValidation();
    }

    /**
     * Regresa a la primera página cuando cambia algún filtro.
     */
    public function updatedSearch(): void
    {
        $this->refreshList();
    }

    public function updatedCategoryFilter(): void
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
        unset($this->spareParts);
    }
};
?>

<div class="mx-auto w-full max-w-7xl space-y-6 p-4 sm:p-6">
    {{-- Encabezado --}}
    <div>
        <h1 class="text-2xl font-bold text-zinc-900 dark:text-white">
            Refacciones
        </h1>

        <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">
            Registra las piezas disponibles y controla sus existencias.
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
            <h2 class="text-lg font-semibold text-zinc-900 dark:text-white">
                {{ $editingSparePartId ? 'Editar refacción' : 'Nueva refacción' }}
            </h2>

            {{-- Categoría --}}
            <div>
                <label for="category_id" class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">
                    Categoría
                </label>

                <select
                    id="category_id"
                    wire:model="category_id"
                    class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-zinc-900 outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-200 dark:border-zinc-600 dark:bg-zinc-800 dark:text-white"
                >
                    <option value="">Selecciona una categoría</option>

                    @foreach ($this->categories as $category)
                        <option value="{{ $category->id }}">
                            {{ $category->name }}
                        </option>
                    @endforeach
                </select>

                @error('category_id')
                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                {{-- SKU --}}
                <div>
                    <label for="sku" class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">
                        Código o SKU
                    </label>

                    <input
                        id="sku"
                        type="text"
                        wire:model="sku"
                        maxlength="50"
                        placeholder="Ej. BOT-001"
                        class="w-full rounded-lg border border-zinc-300 px-3 py-2 uppercase outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-200 dark:border-zinc-600 dark:bg-zinc-800 dark:text-white"
                    >

                    @error('sku')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                {{-- Marca --}}
                <div>
                    <label for="brand" class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">
                        Marca
                    </label>

                    <input
                        id="brand"
                        type="text"
                        wire:model="brand"
                        maxlength="100"
                        placeholder="Ej. Schindler"
                        class="w-full rounded-lg border border-zinc-300 px-3 py-2 outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-200 dark:border-zinc-600 dark:bg-zinc-800 dark:text-white"
                    >

                    @error('brand')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>
            </div>

            {{-- Nombre --}}
            <div>
                <label for="name" class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">
                    Nombre de la refacción
                </label>

                <input
                    id="name"
                    type="text"
                    wire:model="name"
                    maxlength="150"
                    placeholder="Ej. Botón de llamada redondo"
                    class="w-full rounded-lg border border-zinc-300 px-3 py-2 outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-200 dark:border-zinc-600 dark:bg-zinc-800 dark:text-white"
                >

                @error('name')
                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>

            {{-- Descripción --}}
            <div>
                <label for="description" class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">
                    Descripción
                </label>

                <textarea
                    id="description"
                    wire:model="description"
                    rows="3"
                    maxlength="2000"
                    placeholder="Características o compatibilidad de la pieza"
                    class="w-full resize-none rounded-lg border border-zinc-300 px-3 py-2 outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-200 dark:border-zinc-600 dark:bg-zinc-800 dark:text-white"
                ></textarea>

                @error('description')
                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                {{-- Existencia --}}
                <div>
                    <label for="stock" class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">
                        Existencia actual
                    </label>

                    <input
                        id="stock"
                        type="number"
                        wire:model="stock"
                        min="0"
                        step="1"
                        class="w-full rounded-lg border border-zinc-300 px-3 py-2 outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-200 dark:border-zinc-600 dark:bg-zinc-800 dark:text-white"
                    >

                    @error('stock')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                {{-- Existencia mínima --}}
                <div>
                    <label for="minimum_stock" class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">
                        Existencia mínima
                    </label>

                    <input
                        id="minimum_stock"
                        type="number"
                        wire:model="minimum_stock"
                        min="0"
                        step="1"
                        class="w-full rounded-lg border border-zinc-300 px-3 py-2 outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-200 dark:border-zinc-600 dark:bg-zinc-800 dark:text-white"
                    >

                    @error('minimum_stock')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                {{-- Ubicación --}}
                <div>
                    <label for="location" class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">
                        Ubicación
                    </label>

                    <input
                        id="location"
                        type="text"
                        wire:model="location"
                        maxlength="100"
                        placeholder="Ej. Estante A-2"
                        class="w-full rounded-lg border border-zinc-300 px-3 py-2 outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-200 dark:border-zinc-600 dark:bg-zinc-800 dark:text-white"
                    >

                    @error('location')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                {{-- Costo --}}
                <div>
                    <label for="cost" class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">
                        Costo unitario
                    </label>

                    <input
                        id="cost"
                        type="number"
                        wire:model="cost"
                        min="0"
                        step="0.01"
                        class="w-full rounded-lg border border-zinc-300 px-3 py-2 outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-200 dark:border-zinc-600 dark:bg-zinc-800 dark:text-white"
                    >

                    @error('cost')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>
            </div>

            {{-- Estado --}}
            <label class="flex items-center gap-3">
                <input
                    type="checkbox"
                    wire:model="is_active"
                    class="h-4 w-4 rounded border-zinc-300 text-blue-600"
                >

                <span class="text-sm text-zinc-700 dark:text-zinc-300">
                    Refacción activa
                </span>
            </label>

            {{-- Botones --}}
            <div class="flex flex-wrap gap-3">
                <button
                    type="submit"
                    wire:loading.attr="disabled"
                    wire:target="save"
                    class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-60"
                >
                    <span wire:loading.remove wire:target="save">
                        {{ $editingSparePartId ? 'Actualizar' : 'Guardar' }}
                    </span>

                    <span wire:loading wire:target="save">
                        Guardando...
                    </span>
                </button>

                @if ($editingSparePartId)
                    <button
                        type="button"
                        wire:click="resetForm"
                        class="rounded-lg border border-zinc-300 px-4 py-2 text-sm font-semibold text-zinc-700 hover:bg-zinc-100 dark:border-zinc-600 dark:text-zinc-200 dark:hover:bg-zinc-800"
                    >
                        Cancelar
                    </button>
                @endif
            </div>
        </form>

        {{-- Listado de refacciones --}}
        <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-900 xl:col-span-3">
            {{-- Búsqueda y filtros --}}
            <div class="grid gap-3 border-b border-zinc-200 p-4 dark:border-zinc-700 md:grid-cols-3">
                <input
                    type="search"
                    wire:model.live.debounce.300ms="search"
                    placeholder="Buscar por SKU, nombre o marca..."
                    class="rounded-lg border border-zinc-300 px-3 py-2 outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-200 dark:border-zinc-600 dark:bg-zinc-800 dark:text-white"
                >

                <select
                    wire:model.change.live="categoryFilter"
                    class="rounded-lg border border-zinc-300 px-3 py-2 outline-none dark:border-zinc-600 dark:bg-zinc-800 dark:text-white"
                >
                    <option value="">Todas las categorías</option>

                    @foreach ($this->categories as $category)
                        <option value="{{ $category->id }}">
                            {{ $category->name }}
                        </option>
                    @endforeach
                </select>

                <select
                    wire:model.change.live="statusFilter"
                    class="rounded-lg border border-zinc-300 px-3 py-2 outline-none dark:border-zinc-600 dark:bg-zinc-800 dark:text-white"
                >
                    <option value="all">Todos los estados</option>
                    <option value="active">Activas</option>
                    <option value="inactive">Inactivas</option>
                    <option value="low_stock">Existencia baja</option>
                </select>
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-700">
                    <thead class="bg-zinc-50 dark:bg-zinc-800">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase text-zinc-600 dark:text-zinc-300">
                                Refacción
                            </th>

                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase text-zinc-600 dark:text-zinc-300">
                                Categoría
                            </th>

                            <th class="px-4 py-3 text-center text-xs font-semibold uppercase text-zinc-600 dark:text-zinc-300">
                                Existencia
                            </th>

                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase text-zinc-600 dark:text-zinc-300">
                                Costo
                            </th>

                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase text-zinc-600 dark:text-zinc-300">
                                Acciones
                            </th>
                        </tr>
                    </thead>

                    <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                        @forelse ($this->spareParts as $sparePart)
                            @php
                                $hasLowStock = $sparePart->minimum_stock > 0
                                    && $sparePart->stock <= $sparePart->minimum_stock;
                            @endphp

                            <tr wire:key="spare-part-{{ $sparePart->id }}">
                                <td class="px-4 py-4">
                                    <p class="font-medium text-zinc-900 dark:text-white">
                                        {{ $sparePart->name }}
                                    </p>

                                    <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                                        SKU: {{ $sparePart->sku }}
                                        @if ($sparePart->brand)
                                            · {{ $sparePart->brand }}
                                        @endif
                                    </p>

                                    <span class="mt-2 inline-flex rounded-full px-2 py-0.5 text-xs font-semibold {{ $sparePart->is_active ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700' }}">
                                        {{ $sparePart->is_active ? 'Activa' : 'Inactiva' }}
                                    </span>
                                </td>

                                <td class="px-4 py-4 text-sm text-zinc-700 dark:text-zinc-300">
                                    {{ $sparePart->category->name }}
                                </td>

                                <td class="px-4 py-4 text-center">
                                    <p class="font-semibold {{ $hasLowStock ? 'text-red-600' : 'text-zinc-900 dark:text-white' }}">
                                        {{ $sparePart->stock }}
                                    </p>

                                    <p class="text-xs text-zinc-500">
                                        Mínimo: {{ $sparePart->minimum_stock }}
                                    </p>

                                    @if ($hasLowStock)
                                        <span class="mt-1 inline-flex rounded-full bg-red-100 px-2 py-0.5 text-xs font-semibold text-red-700">
                                            Existencia baja
                                        </span>
                                    @endif
                                </td>

                                <td class="px-4 py-4 text-right font-medium text-zinc-900 dark:text-white">
                                    ${{ number_format((float) $sparePart->cost, 2) }}
                                </td>

                                <td class="px-4 py-4">
                                    <div class="flex justify-end gap-2">
                                        <button
                                            type="button"
                                            wire:click="edit({{ $sparePart->id }})"
                                            class="rounded-md border border-blue-300 px-3 py-1.5 text-sm font-medium text-blue-700 hover:bg-blue-50"
                                        >
                                            Editar
                                        </button>

                                        <button
                                            type="button"
                                            wire:click="toggleStatus({{ $sparePart->id }})"
                                            wire:confirm="{{ $sparePart->is_active ? '¿Deseas desactivar esta refacción?' : '¿Deseas activar esta refacción?' }}"
                                            class="rounded-md border border-zinc-300 px-3 py-1.5 text-sm font-medium text-zinc-700 hover:bg-zinc-100 dark:border-zinc-600 dark:text-zinc-300"
                                        >
                                            {{ $sparePart->is_active ? 'Desactivar' : 'Activar' }}
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-4 py-10 text-center text-sm text-zinc-500">
                                    No se encontraron refacciones.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="border-t border-zinc-200 p-4 dark:border-zinc-700">
                {{ $this->spareParts->links() }}
            </div>
        </div>
    </div>
</div>