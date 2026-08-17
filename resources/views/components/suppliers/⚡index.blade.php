<?php

use App\Models\Supplier;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    // Proveedor que se está editando.
    public ?int $editingSupplierId = null;

    // Datos del formulario.
    public string $name = '';
    public string $tax_id = '';
    public string $contact_name = '';
    public string $phone = '';
    public string $email = '';
    public string $address = '';
    public string $notes = '';
    public bool $is_active = true;

    // Filtros del catálogo.
    public string $search = '';
    public string $statusFilter = 'all';

    /**
     * Reglas de validación del proveedor.
     */
    protected function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'min:2',
                'max:150',
            ],
            'tax_id' => [
                'nullable',
                'string',
                'min:12',
                'max:13',
                'regex:/^[A-ZÑ&]{3,4}[0-9]{6}[A-Z0-9]{3}$/u',
                Rule::unique('suppliers', 'tax_id')
                    ->ignore($this->editingSupplierId),
            ],
            'contact_name' => [
                'nullable',
                'string',
                'max:150',
            ],
            'phone' => [
                'nullable',
                'string',
                'min:7',
                'max:25',
                'regex:/^[0-9+\s().-]+$/',
            ],
            'email' => [
                'nullable',
                'email',
                'max:150',
            ],
            'address' => [
                'nullable',
                'string',
                'max:2000',
            ],
            'notes' => [
                'nullable',
                'string',
                'max:2000',
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
            'name.required' => 'Escribe el nombre o razón social.',
            'name.min' => 'El nombre debe contener al menos 2 caracteres.',
            'name.max' => 'El nombre no puede superar los 150 caracteres.',

            'tax_id.min' => 'El RFC debe contener 12 o 13 caracteres.',
            'tax_id.max' => 'El RFC debe contener 12 o 13 caracteres.',
            'tax_id.regex' => 'El formato del RFC no es válido.',
            'tax_id.unique' => 'Ya existe un proveedor con este RFC.',

            'contact_name.max' => 'El contacto no puede superar los 150 caracteres.',

            'phone.min' => 'El teléfono debe contener al menos 7 caracteres.',
            'phone.max' => 'El teléfono no puede superar los 25 caracteres.',
            'phone.regex' => 'El teléfono contiene caracteres no válidos.',

            'email.email' => 'Escribe un correo electrónico válido.',
            'email.max' => 'El correo no puede superar los 150 caracteres.',

            'address.max' => 'La dirección no puede superar los 2000 caracteres.',
            'notes.max' => 'Las notas no pueden superar los 2000 caracteres.',
        ];
    }

    /**
     * Obtiene los proveedores aplicando búsqueda y filtros.
     */
    #[Computed]
    public function suppliers()
    {
        return Supplier::query()
            ->search($this->search)
            ->when(
                $this->statusFilter === 'active',
                fn ($query) => $query->where('is_active', true)
            )
            ->when(
                $this->statusFilter === 'inactive',
                fn ($query) => $query->where('is_active', false)
            )
            ->orderBy('name')
            ->paginate(10);
    }

    /**
     * Crea o actualiza un proveedor.
     */
    public function save(): void
    {
        $this->normalizeFields();

        $validated = $this->validate();

        $data = [
            'name' => $validated['name'],
            'tax_id' => $validated['tax_id'] !== ''
                ? $validated['tax_id']
                : null,
            'contact_name' => $validated['contact_name'] !== ''
                ? $validated['contact_name']
                : null,
            'phone' => $validated['phone'] !== ''
                ? $validated['phone']
                : null,
            'email' => $validated['email'] !== ''
                ? $validated['email']
                : null,
            'address' => $validated['address'] !== ''
                ? $validated['address']
                : null,
            'notes' => $validated['notes'] !== ''
                ? $validated['notes']
                : null,
            'is_active' => $validated['is_active'],
        ];

        if ($this->editingSupplierId !== null) {
            $supplier = Supplier::query()
                ->findOrFail($this->editingSupplierId);

            $supplier->update($data);

            $message = 'Proveedor actualizado correctamente.';
        } else {
            Supplier::create($data);

            $message = 'Proveedor registrado correctamente.';
        }

        $this->resetForm();
        $this->resetPage();

        unset($this->suppliers);

        session()->flash('success', $message);
    }

    /**
     * Carga un proveedor en el formulario.
     */
    public function edit(int $supplierId): void
    {
        $supplier = Supplier::query()
            ->findOrFail($supplierId);

        $this->editingSupplierId = $supplier->id;
        $this->name = $supplier->name;
        $this->tax_id = $supplier->tax_id ?? '';
        $this->contact_name = $supplier->contact_name ?? '';
        $this->phone = $supplier->phone ?? '';
        $this->email = $supplier->email ?? '';
        $this->address = $supplier->address ?? '';
        $this->notes = $supplier->notes ?? '';
        $this->is_active = (bool) $supplier->is_active;

        $this->resetValidation();
    }

    /**
     * Activa o desactiva un proveedor.
     */
    public function toggleStatus(int $supplierId): void
    {
        $supplier = Supplier::query()
            ->findOrFail($supplierId);

        $supplier->update([
            'is_active' => ! $supplier->is_active,
        ]);

        if ($this->editingSupplierId === $supplier->id) {
            $this->resetForm();
        }

        unset($this->suppliers);

        session()->flash(
            'success',
            $supplier->fresh()->is_active
                ? 'Proveedor activado correctamente.'
                : 'Proveedor desactivado correctamente.'
        );
    }

    /**
     * Limpia el formulario.
     */
    public function resetForm(): void
    {
        $this->reset([
            'editingSupplierId',
            'name',
            'tax_id',
            'contact_name',
            'phone',
            'email',
            'address',
            'notes',
        ]);

        $this->is_active = true;

        $this->resetValidation();
    }

    /**
     * Normaliza los datos antes de validarlos.
     */
    private function normalizeFields(): void
    {
        $this->name = Str::squish($this->name);
        $this->contact_name = Str::squish($this->contact_name);
        $this->phone = Str::squish($this->phone);
        $this->email = Str::lower(trim($this->email));
        $this->address = trim($this->address);
        $this->notes = trim($this->notes);

        $normalizedTaxId = preg_replace(
            '/[^A-Z0-9Ñ&]/u',
            '',
            Str::upper($this->tax_id)
        );

        $this->tax_id = $normalizedTaxId ?? '';
    }

    /**
     * Regresa a la primera página al cambiar los filtros.
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

        unset($this->suppliers);
    }
};

?>

<div class="mx-auto w-full max-w-7xl space-y-6 p-4 sm:p-6">
    {{-- Encabezado --}}
    <div>
        <h1 class="text-2xl font-bold text-zinc-900 dark:text-white">
            Proveedores
        </h1>

        <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">
            Registra y administra los proveedores del inventario.
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
                    {{ $editingSupplierId ? 'Editar proveedor' : 'Nuevo proveedor' }}
                </h2>

                <p class="mt-1 text-sm text-zinc-500">
                    Los campos con asterisco son obligatorios.
                </p>
            </div>

            {{-- Nombre --}}
            <div>
                <label
                    for="name"
                    class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300"
                >
                    Nombre o razón social *
                </label>

                <input
                    id="name"
                    type="text"
                    wire:model="name"
                    maxlength="150"
                    placeholder="Ej. Refacciones Industriales del Centro"
                    class="w-full rounded-lg border border-zinc-300 px-3 py-2 outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-200 dark:border-zinc-600 dark:bg-zinc-800 dark:text-white"
                >

                @error('name')
                    <p class="mt-1 text-sm text-red-600">
                        {{ $message }}
                    </p>
                @enderror
            </div>

            {{-- RFC y contacto --}}
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label
                        for="tax_id"
                        class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300"
                    >
                        RFC
                    </label>

                    <input
                        id="tax_id"
                        type="text"
                        wire:model="tax_id"
                        maxlength="13"
                        placeholder="Ej. XAXX010101000"
                        class="w-full uppercase rounded-lg border border-zinc-300 px-3 py-2 outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-200 dark:border-zinc-600 dark:bg-zinc-800 dark:text-white"
                    >

                    @error('tax_id')
                        <p class="mt-1 text-sm text-red-600">
                            {{ $message }}
                        </p>
                    @enderror
                </div>

                <div>
                    <label
                        for="contact_name"
                        class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300"
                    >
                        Persona de contacto
                    </label>

                    <input
                        id="contact_name"
                        type="text"
                        wire:model="contact_name"
                        maxlength="150"
                        placeholder="Ej. Carlos Hernández"
                        class="w-full rounded-lg border border-zinc-300 px-3 py-2 outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-200 dark:border-zinc-600 dark:bg-zinc-800 dark:text-white"
                    >

                    @error('contact_name')
                        <p class="mt-1 text-sm text-red-600">
                            {{ $message }}
                        </p>
                    @enderror
                </div>
            </div>

            {{-- Teléfono y correo --}}
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label
                        for="phone"
                        class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300"
                    >
                        Teléfono
                    </label>

                    <input
                        id="phone"
                        type="text"
                        wire:model="phone"
                        maxlength="25"
                        placeholder="Ej. 55 1234 5678"
                        class="w-full rounded-lg border border-zinc-300 px-3 py-2 outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-200 dark:border-zinc-600 dark:bg-zinc-800 dark:text-white"
                    >

                    @error('phone')
                        <p class="mt-1 text-sm text-red-600">
                            {{ $message }}
                        </p>
                    @enderror
                </div>

                <div>
                    <label
                        for="email"
                        class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300"
                    >
                        Correo electrónico
                    </label>

                    <input
                        id="email"
                        type="email"
                        wire:model="email"
                        maxlength="150"
                        placeholder="ventas@proveedor.com"
                        class="w-full rounded-lg border border-zinc-300 px-3 py-2 outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-200 dark:border-zinc-600 dark:bg-zinc-800 dark:text-white"
                    >

                    @error('email')
                        <p class="mt-1 text-sm text-red-600">
                            {{ $message }}
                        </p>
                    @enderror
                </div>
            </div>

            {{-- Dirección --}}
            <div>
                <label
                    for="address"
                    class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300"
                >
                    Dirección
                </label>

                <textarea
                    id="address"
                    wire:model="address"
                    rows="3"
                    maxlength="2000"
                    placeholder="Dirección fiscal o comercial"
                    class="w-full resize-none rounded-lg border border-zinc-300 px-3 py-2 outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-200 dark:border-zinc-600 dark:bg-zinc-800 dark:text-white"
                ></textarea>

                @error('address')
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
                    placeholder="Marcas que maneja, horarios o condiciones comerciales"
                    class="w-full resize-none rounded-lg border border-zinc-300 px-3 py-2 outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-200 dark:border-zinc-600 dark:bg-zinc-800 dark:text-white"
                ></textarea>

                @error('notes')
                    <p class="mt-1 text-sm text-red-600">
                        {{ $message }}
                    </p>
                @enderror
            </div>

            {{-- Estado --}}
            <label class="flex cursor-pointer items-center gap-3">
                <input
                    type="checkbox"
                    wire:model="is_active"
                    class="rounded border-zinc-300 text-blue-600 focus:ring-blue-500"
                >

                <span class="text-sm font-medium text-zinc-700 dark:text-zinc-300">
                    Proveedor activo
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
                        {{ $editingSupplierId ? 'Actualizar' : 'Guardar' }}
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
                    {{ $editingSupplierId ? 'Cancelar edición' : 'Limpiar' }}
                </button>
            </div>
        </form>

        {{-- Catálogo --}}
        <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-900 xl:col-span-3">
            {{-- Filtros --}}
            <div class="grid gap-3 border-b border-zinc-200 p-4 dark:border-zinc-700 md:grid-cols-2">
                <input
                    type="search"
                    wire:model.live.debounce.300ms="search"
                    placeholder="Buscar por nombre, RFC, contacto..."
                    class="rounded-lg border border-zinc-300 px-3 py-2 outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-200 dark:border-zinc-600 dark:bg-zinc-800 dark:text-white"
                >

                <select
                    wire:model.live="statusFilter"
                    class="rounded-lg border border-zinc-300 px-3 py-2 outline-none dark:border-zinc-600 dark:bg-zinc-800 dark:text-white"
                >
                    <option value="all">Todos los estados</option>
                    <option value="active">Activos</option>
                    <option value="inactive">Inactivos</option>
                </select>
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-700">
                    <thead class="bg-zinc-50 dark:bg-zinc-800">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase text-zinc-600 dark:text-zinc-300">
                                Proveedor
                            </th>

                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase text-zinc-600 dark:text-zinc-300">
                                Contacto
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
                        @forelse ($this->suppliers as $supplier)
                            <tr wire:key="supplier-{{ $supplier->id }}">
                                <td class="px-4 py-4">
                                    <p class="font-medium text-zinc-900 dark:text-white">
                                        {{ $supplier->name }}
                                    </p>

                                    <p class="mt-1 text-xs text-zinc-500">
                                        RFC: {{ $supplier->tax_id ?: 'No registrado' }}
                                    </p>
                                </td>

                                <td class="px-4 py-4 text-sm text-zinc-700 dark:text-zinc-300">
                                    <p>
                                        {{ $supplier->contact_name ?: 'Sin contacto' }}
                                    </p>

                                    @if ($supplier->phone)
                                        <p class="mt-1 text-xs text-zinc-500">
                                            {{ $supplier->phone }}
                                        </p>
                                    @endif

                                    @if ($supplier->email)
                                        <p class="mt-1 text-xs text-zinc-500">
                                            {{ $supplier->email }}
                                        </p>
                                    @endif
                                </td>

                                <td class="px-4 py-4 text-center">
                                    <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold {{ $supplier->is_active ? 'bg-green-100 text-green-700' : 'bg-zinc-200 text-zinc-700' }}">
                                        {{ $supplier->is_active ? 'Activo' : 'Inactivo' }}
                                    </span>
                                </td>

                                <td class="px-4 py-4">
                                    <div class="flex justify-end gap-2">
                                        <button
                                            type="button"
                                            wire:click="edit({{ $supplier->id }})"
                                            class="rounded-lg border border-blue-400 px-3 py-2 text-sm font-semibold text-blue-600 hover:bg-blue-50 dark:hover:bg-blue-950"
                                        >
                                            Editar
                                        </button>

                                        <button
                                            type="button"
                                            wire:click="toggleStatus({{ $supplier->id }})"
                                            wire:confirm="¿Confirmas que deseas cambiar el estado de este proveedor?"
                                            class="rounded-lg border border-zinc-400 px-3 py-2 text-sm font-semibold text-zinc-700 hover:bg-zinc-100 dark:text-zinc-200 dark:hover:bg-zinc-800"
                                        >
                                            {{ $supplier->is_active ? 'Desactivar' : 'Activar' }}
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="px-4 py-10 text-center text-sm text-zinc-500">
                                    No se encontraron proveedores.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="border-t border-zinc-200 p-4 dark:border-zinc-700">
                {{ $this->suppliers->links() }}
            </div>
        </div>
    </div>
</div>