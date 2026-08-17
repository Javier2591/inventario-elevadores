<?php

use App\Models\Category;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;
use Illuminate\Support\Str;

new class extends Component
{
    use WithPagination;

    public string $name = '';
    public string $slug = '';
    public string $description = '';
    public bool $is_active = true;
    public string $search = '';

    /**
     * Evita que el identificador pueda manipularse desde el navegador.
     */
    #[Locked]
    public ?int $editingCategoryId = null;

    /**
     * Reglas para crear y actualizar categorías.
     */
    protected function rules(): array
    {
        $nameRule = Rule::unique('categories', 'name');
        $slugRule = Rule::unique('categories', 'slug');

        if ($this->editingCategoryId !== null) {
            $nameRule->ignore($this->editingCategoryId);
            $slugRule->ignore($this->editingCategoryId);
        }

        return [
            'name' => ['required', 'string', 'min:3', 'max:100', $nameRule],
            'slug' => ['required', 'string', 'max:120', $slugRule],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['boolean'],
        ];
    }

    /**
     * Mensajes de validación mostrados al usuario.
     */
    protected function messages(): array
    {
        return [
            'name.required' => 'Escribe el nombre de la categoría.',
            'name.min' => 'El nombre debe tener al menos 3 caracteres.',
            'name.max' => 'El nombre no puede superar los 100 caracteres.',
            'name.unique' => 'Ya existe una categoría con ese nombre.',
            'slug.required' => 'El nombre debe contener letras o números.',
            'slug.unique' => 'Ese nombre genera un identificador que ya está ocupado.',
            'description.max' => 'La descripción no puede superar los 1000 caracteres.',
        ];
    }

    /**
     * Obtiene las categorías filtradas y paginadas.
     */
    #[Computed]
    public function categories()
    {
        return Category::query()
            ->when($this->search !== '', function ($query) {
                $query->where(function ($query) {
                    $query->where('name', 'like', '%' . $this->search . '%')
                        ->orWhere('description', 'like', '%' . $this->search . '%');
                });
            })
            ->orderBy('name')
            ->paginate(10);
    }

    /**
     * Guarda una categoría nueva o actualiza una existente.
     */
    public function save(): void
    {
        // Normaliza el texto antes de validarlo.
        $this->name = Str::squish($this->name);
        $this->slug = Str::slug($this->name);
        $this->description = trim($this->description);

        $validated = $this->validate();

        $validated['description'] = $validated['description'] !== ''
            ? $validated['description']
            : null;

        $wasEditing = $this->editingCategoryId !== null;

        $category = $wasEditing
            ? Category::findOrFail($this->editingCategoryId)
            : new Category();

        $category->fill($validated);
        $category->save();

        $this->resetForm();

        // Limpia el resultado calculado para actualizar la tabla.
        unset($this->categories);

        session()->flash(
            'success',
            $wasEditing
                ? 'Categoría actualizada correctamente.'
                : 'Categoría registrada correctamente.'
        );
    }

    /**
     * Carga una categoría en el formulario.
     */
    public function edit(int $categoryId): void
    {
        $category = Category::findOrFail($categoryId);

        $this->editingCategoryId = $category->id;
        $this->name = $category->name;
        $this->slug = $category->slug;
        $this->description = $category->description ?? '';
        $this->is_active = $category->is_active;

        $this->resetValidation();
    }

    /**
     * Activa o desactiva sin borrar información histórica.
     */
    public function toggleStatus(int $categoryId): void
    {
        $category = Category::findOrFail($categoryId);
        $newStatus = ! $category->is_active;

        $category->update([
            'is_active' => $newStatus,
        ]);

        if ($this->editingCategoryId === $category->id) {
            $this->is_active = $newStatus;
        }

        unset($this->categories);

        session()->flash(
            'success',
            $newStatus
                ? 'Categoría activada correctamente.'
                : 'Categoría desactivada correctamente.'
        );
    }

    /**
     * Reinicia el formulario.
     */
    public function resetForm(): void
    {
        $this->reset([
            'name',
            'slug',
            'description',
            'editingCategoryId',
        ]);

        $this->is_active = true;
        $this->resetValidation();
    }

    /**
     * Regresa a la primera página cuando cambia la búsqueda.
     */
    public function updatedSearch(): void
    {
        $this->resetPage();
        unset($this->categories);
    }
};
?>

<div class="mx-auto w-full max-w-7xl space-y-6 p-4 sm:p-6">
    {{-- Encabezado --}}
    <div>
        <h1 class="text-2xl font-bold text-zinc-900 dark:text-white">
            Categorías de refacciones
        </h1>

        <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">
            Registra y organiza las categorías utilizadas en el inventario.
        </p>
    </div>

    {{-- Mensaje de confirmación --}}
    @if (session('success'))
        <div class="rounded-lg border border-green-300 bg-green-50 p-4 text-sm text-green-800 dark:border-green-700 dark:bg-green-950 dark:text-green-200">
            {{ session('success') }}
        </div>
    @endif

    <div class="grid gap-6 lg:grid-cols-3">
        {{-- Formulario --}}
        <form
            wire:submit="save"
            class="rounded-xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900"
        >
            <h2 class="mb-5 text-lg font-semibold text-zinc-900 dark:text-white">
                {{ $editingCategoryId ? 'Editar categoría' : 'Nueva categoría' }}
            </h2>

            <div class="space-y-5">
                <div>
                    <label for="name" class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">
                        Nombre
                    </label>

                    <input
                        id="name"
                        type="text"
                        wire:model="name"
                        maxlength="100"
                        placeholder="Ej. Tarjetas electrónicas"
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-zinc-900 outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-200 dark:border-zinc-600 dark:bg-zinc-800 dark:text-white"
                    >

                    @error('name')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror

                    @error('slug')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="description" class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">
                        Descripción
                    </label>

                    <textarea
                        id="description"
                        wire:model="description"
                        rows="4"
                        maxlength="1000"
                        placeholder="Describe qué refacciones pertenecen a esta categoría"
                        class="w-full resize-none rounded-lg border border-zinc-300 bg-white px-3 py-2 text-zinc-900 outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-200 dark:border-zinc-600 dark:bg-zinc-800 dark:text-white"
                    ></textarea>

                    @error('description')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <label class="flex items-center gap-3">
                    <input
                        type="checkbox"
                        wire:model="is_active"
                        class="h-4 w-4 rounded border-zinc-300 text-blue-600"
                    >

                    <span class="text-sm text-zinc-700 dark:text-zinc-300">
                        Categoría activa
                    </span>
                </label>

                <div class="flex flex-wrap gap-3">
                    <button
                        type="submit"
                        wire:loading.attr="disabled"
                        wire:target="save"
                        class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-60"
                    >
                        <span wire:loading.remove wire:target="save">
                            {{ $editingCategoryId ? 'Actualizar' : 'Guardar' }}
                        </span>

                        <span wire:loading wire:target="save">
                            Guardando...
                        </span>
                    </button>

                    @if ($editingCategoryId)
                        <button
                            type="button"
                            wire:click="resetForm"
                            class="rounded-lg border border-zinc-300 px-4 py-2 text-sm font-semibold text-zinc-700 hover:bg-zinc-100 dark:border-zinc-600 dark:text-zinc-200 dark:hover:bg-zinc-800"
                        >
                            Cancelar
                        </button>
                    @endif
                </div>
            </div>
        </form>

        {{-- Listado --}}
        <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-900 lg:col-span-2">
            <div class="border-b border-zinc-200 p-4 dark:border-zinc-700">
                <input
                    type="search"
                    wire:model.live.debounce.300ms="search"
                    placeholder="Buscar categoría..."
                    class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-zinc-900 outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-200 dark:border-zinc-600 dark:bg-zinc-800 dark:text-white"
                >
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-700">
                    <thead class="bg-zinc-50 dark:bg-zinc-800">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase text-zinc-600 dark:text-zinc-300">
                                Categoría
                            </th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase text-zinc-600 dark:text-zinc-300">
                                Estado
                            </th>
                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase text-zinc-600 dark:text-zinc-300">
                                Acciones
                            </th>
                        </tr>
                    </thead>

                    <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                        @forelse ($this->categories as $category)
                            <tr wire:key="category-{{ $category->id }}">
                                <td class="px-4 py-4">
                                    <p class="font-medium text-zinc-900 dark:text-white">
                                        {{ $category->name }}
                                    </p>

                                    <p class="mt-1 max-w-md text-sm text-zinc-500 dark:text-zinc-400">
                                        {{ $category->description ?: 'Sin descripción' }}
                                    </p>
                                </td>

                                <td class="px-4 py-4">
                                    <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold {{ $category->is_active ? 'bg-green-100 text-green-700 dark:bg-green-950 dark:text-green-300' : 'bg-red-100 text-red-700 dark:bg-red-950 dark:text-red-300' }}">
                                        {{ $category->is_active ? 'Activa' : 'Inactiva' }}
                                    </span>
                                </td>

                                <td class="px-4 py-4">
                                    <div class="flex justify-end gap-2">
                                        <button
                                            type="button"
                                            wire:click="edit({{ $category->id }})"
                                            class="rounded-md border border-blue-300 px-3 py-1.5 text-sm font-medium text-blue-700 hover:bg-blue-50 dark:border-blue-700 dark:text-blue-300 dark:hover:bg-blue-950"
                                        >
                                            Editar
                                        </button>

                                        <button
                                            type="button"
                                            wire:click="toggleStatus({{ $category->id }})"
                                            wire:confirm="{{ $category->is_active ? '¿Deseas desactivar esta categoría?' : '¿Deseas activar esta categoría?' }}"
                                            class="rounded-md border border-zinc-300 px-3 py-1.5 text-sm font-medium text-zinc-700 hover:bg-zinc-100 dark:border-zinc-600 dark:text-zinc-300 dark:hover:bg-zinc-800"
                                        >
                                            {{ $category->is_active ? 'Desactivar' : 'Activar' }}
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="3" class="px-4 py-10 text-center text-sm text-zinc-500">
                                    No se encontraron categorías.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="border-t border-zinc-200 p-4 dark:border-zinc-700">
                {{ $this->categories->links() }}
            </div>
        </div>
    </div>
</div>