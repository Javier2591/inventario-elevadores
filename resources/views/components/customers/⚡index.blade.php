<?php

use App\Models\Customer;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    /**
     * Cliente que está siendo editado.
     */
    public ?int $editingCustomerId = null;

    /**
     * Campos del formulario.
     */
    public string $name = '';
    public string $tax_id = '';
    public string $phone = '';
    public string $email = '';
    public string $address = '';
    public string $notes = '';
    public bool $is_active = true;

    /**
     * Filtros del catálogo.
     */
    public string $search = '';
    public string $statusFilter = 'all';

    /**
     * Impide que usuarios no administrativos carguen
     * directamente el componente Livewire.
     */
    public function mount(): void
    {
        $user = auth()->user();

        abort_unless(
            $user instanceof User
                && $user->isAdmin()
                && $user->canAccessSystem(),
            403,
            'No tienes permisos para administrar clientes.'
        );
    }

    /**
     * Reglas del formulario.
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
                Rule::unique('customers', 'tax_id')
                    ->ignore($this->editingCustomerId),
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
     * Mensajes de validación.
     */
    protected function messages(): array
    {
        return [
            'name.required' =>
                'Escribe el nombre o razón social del cliente.',
            'name.min' =>
                'El nombre debe contener al menos 2 caracteres.',
            'name.max' =>
                'El nombre no puede superar los 150 caracteres.',

            'tax_id.min' =>
                'El RFC debe contener 12 o 13 caracteres.',
            'tax_id.max' =>
                'El RFC debe contener 12 o 13 caracteres.',
            'tax_id.regex' =>
                'El formato del RFC no es válido.',
            'tax_id.unique' =>
                'Ya existe un cliente registrado con este RFC.',

            'phone.min' =>
                'El teléfono debe contener al menos 7 caracteres.',
            'phone.max' =>
                'El teléfono no puede superar los 25 caracteres.',
            'phone.regex' =>
                'El teléfono contiene caracteres no válidos.',

            'email.email' =>
                'Escribe un correo electrónico válido.',
            'email.max' =>
                'El correo no puede superar los 150 caracteres.',

            'address.max' =>
                'La dirección no puede superar los 2000 caracteres.',
            'notes.max' =>
                'Las notas no pueden superar los 2000 caracteres.',
        ];
    }

    /**
     * Clientes disponibles con filtros y conteo de ventas.
     */
    #[Computed]
    public function customers()
    {
        return Customer::query()
            ->withCount('sales')
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
     * Crea o actualiza un cliente.
     */
    public function save(): void
    {
        $this->authorizeAdministrator();
        $this->normalizeFields();

        $validated = $this->validate();

        $data = [
            'name' => $validated['name'],
            'tax_id' => $validated['tax_id'] !== ''
                ? $validated['tax_id']
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

        if ($this->editingCustomerId !== null) {
            $customer = Customer::query()
                ->findOrFail($this->editingCustomerId);

            $customer->update($data);

            $message = 'Cliente actualizado correctamente.';
        } else {
            $data['created_by'] = auth()->id();

            Customer::query()->create($data);

            $message = 'Cliente registrado correctamente.';
        }

        $this->resetForm();
        $this->resetPage();

        unset($this->customers);

        session()->flash('success', $message);
    }

    /**
     * Carga los datos de un cliente en el formulario.
     */
    public function edit(int $customerId): void
    {
        $this->authorizeAdministrator();

        $customer = Customer::query()
            ->findOrFail($customerId);

        $this->editingCustomerId = $customer->id;
        $this->name = $customer->name;
        $this->tax_id = $customer->tax_id ?? '';
        $this->phone = $customer->phone ?? '';
        $this->email = $customer->email ?? '';
        $this->address = $customer->address ?? '';
        $this->notes = $customer->notes ?? '';
        $this->is_active = (bool) $customer->is_active;

        $this->resetValidation();
    }

    /**
     * Activa o desactiva un cliente.
     */
    public function toggleStatus(int $customerId): void
    {
        $this->authorizeAdministrator();

        $customer = Customer::query()
            ->findOrFail($customerId);

        $customer->update([
            'is_active' => ! $customer->is_active,
        ]);

        if ($this->editingCustomerId === $customer->id) {
            $this->resetForm();
        }

        unset($this->customers);

        session()->flash(
            'success',
            $customer->fresh()->is_active
                ? 'Cliente activado correctamente.'
                : 'Cliente desactivado correctamente.'
        );
    }

    /**
     * Limpia el formulario.
     */
    public function resetForm(): void
    {
        $this->reset([
            'editingCustomerId',
            'name',
            'tax_id',
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
     * Verifica nuevamente los permisos antes de modificar información.
     */
    private function authorizeAdministrator(): void
    {
        $user = auth()->user();

        abort_unless(
            $user instanceof User
                && $user->isAdmin()
                && $user->canAccessSystem(),
            403,
            'No tienes permisos para administrar clientes.'
        );
    }

    /**
     * Regresa a la primera página al cambiar la búsqueda.
     */
    public function updatedSearch(): void
    {
        $this->refreshList();
    }

    /**
     * Regresa a la primera página al cambiar el estado.
     */
    public function updatedStatusFilter(): void
    {
        $this->refreshList();
    }

    private function refreshList(): void
    {
        $this->resetPage();

        unset($this->customers);
    }
};

?>

<div class="mx-auto w-full max-w-7xl space-y-6 p-4 sm:p-6">
    {{-- Encabezado --}}
    <div>
        <h1 class="text-2xl font-bold text-zinc-900 dark:text-white">
            Clientes
        </h1>

        <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">
            Registra y administra los clientes para las ventas de refacciones.
        </p>
    </div>

    {{-- Confirmación --}}
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
                    {{ $editingCustomerId ? 'Editar cliente' : 'Nuevo cliente' }}
                </h2>

                <p class="mt-1 text-sm text-zinc-500">
                    El nombre es obligatorio. Los demás datos son opcionales.
                </p>
            </div>

            {{-- Nombre --}}
            <div>
                <label
                    for="customer-name"
                    class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300"
                >
                    Nombre o razón social *
                </label>

                <input
                    id="customer-name"
                    type="text"
                    wire:model="name"
                    maxlength="150"
                    autocomplete="name"
                    placeholder="Ej. Condominio Torre Reforma"
                    class="w-full rounded-lg border border-zinc-300 px-3 py-2 outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-200 dark:border-zinc-600 dark:bg-zinc-800 dark:text-white"
                >

                @error('name')
                    <p class="mt-1 text-sm text-red-600">
                        {{ $message }}
                    </p>
                @enderror
            </div>

            {{-- RFC --}}
            <div>
                <label
                    for="customer-tax-id"
                    class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300"
                >
                    RFC
                </label>

                <input
                    id="customer-tax-id"
                    type="text"
                    wire:model="tax_id"
                    maxlength="13"
                    placeholder="Ej. XAXX010101000"
                    class="w-full rounded-lg border border-zinc-300 px-3 py-2 uppercase outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-200 dark:border-zinc-600 dark:bg-zinc-800 dark:text-white"
                >

                @error('tax_id')
                    <p class="mt-1 text-sm text-red-600">
                        {{ $message }}
                    </p>
                @enderror
            </div>

            {{-- Teléfono y correo --}}
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label
                        for="customer-phone"
                        class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300"
                    >
                        Teléfono
                    </label>

                    <input
                        id="customer-phone"
                        type="tel"
                        wire:model="phone"
                        maxlength="25"
                        autocomplete="tel"
                        inputmode="tel"
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
                        for="customer-email"
                        class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300"
                    >
                        Correo electrónico
                    </label>

                    <input
                        id="customer-email"
                        type="email"
                        wire:model="email"
                        maxlength="150"
                        autocomplete="email"
                        placeholder="cliente@empresa.com"
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
                    for="customer-address"
                    class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300"
                >
                    Dirección
                </label>

                <textarea
                    id="customer-address"
                    wire:model="address"
                    rows="3"
                    maxlength="2000"
                    autocomplete="street-address"
                    placeholder="Dirección fiscal o de entrega"
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
                    for="customer-notes"
                    class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300"
                >
                    Notas
                </label>

                <textarea
                    id="customer-notes"
                    wire:model="notes"
                    rows="3"
                    maxlength="2000"
                    placeholder="Condiciones comerciales o información adicional"
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
                    Cliente activo
                </span>
            </label>

            {{-- Acciones --}}
            <div class="flex flex-wrap gap-3">
                <button
                    type="submit"
                    wire:loading.attr="disabled"
                    wire:target="save"
                    class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-60"
                >
                    <span wire:loading.remove wire:target="save">
                        {{ $editingCustomerId ? 'Actualizar' : 'Guardar' }}
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
                    {{ $editingCustomerId ? 'Cancelar edición' : 'Limpiar' }}
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
                    placeholder="Buscar nombre, RFC, teléfono o correo..."
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
                                Cliente
                            </th>

                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase text-zinc-600 dark:text-zinc-300">
                                Contacto
                            </th>

                            <th class="px-4 py-3 text-center text-xs font-semibold uppercase text-zinc-600 dark:text-zinc-300">
                                Ventas
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
                        @forelse ($this->customers as $customer)
                            <tr wire:key="customer-{{ $customer->id }}">
                                <td class="px-4 py-4">
                                    <p class="font-medium text-zinc-900 dark:text-white">
                                        {{ $customer->name }}
                                    </p>

                                    <p class="mt-1 text-xs text-zinc-500">
                                        RFC: {{ $customer->tax_id ?: 'No registrado' }}
                                    </p>
                                </td>

                                <td class="px-4 py-4 text-sm text-zinc-700 dark:text-zinc-300">
                                    <p>
                                        {{ $customer->phone ?: 'Sin teléfono' }}
                                    </p>

                                    @if ($customer->email)
                                        <p class="mt-1 text-xs text-zinc-500">
                                            {{ $customer->email }}
                                        </p>
                                    @endif
                                </td>

                                <td class="px-4 py-4 text-center">
                                    <span class="font-semibold text-zinc-900 dark:text-white">
                                        {{ $customer->sales_count }}
                                    </span>

                                    <p class="mt-1 text-xs text-zinc-500">
                                        registradas
                                    </p>
                                </td>

                                <td class="px-4 py-4 text-center">
                                    <span @class([
                                        'inline-flex rounded-full px-2.5 py-1 text-xs font-semibold',
                                        'bg-green-100 text-green-700' => $customer->is_active,
                                        'bg-zinc-200 text-zinc-700' => ! $customer->is_active,
                                    ])>
                                        {{ $customer->is_active ? 'Activo' : 'Inactivo' }}
                                    </span>
                                </td>

                                <td class="px-4 py-4">
                                    <div class="flex justify-end gap-2">
                                        <button
                                            type="button"
                                            wire:click="edit({{ $customer->id }})"
                                            class="rounded-lg border border-blue-400 px-3 py-2 text-sm font-semibold text-blue-600 hover:bg-blue-50 dark:hover:bg-blue-950"
                                        >
                                            Editar
                                        </button>

                                        <button
                                            type="button"
                                            wire:click="toggleStatus({{ $customer->id }})"
                                            wire:confirm="¿Confirmas que deseas cambiar el estado de este cliente?"
                                            class="rounded-lg border border-zinc-400 px-3 py-2 text-sm font-semibold text-zinc-700 hover:bg-zinc-100 dark:text-zinc-200 dark:hover:bg-zinc-800"
                                        >
                                            {{ $customer->is_active ? 'Desactivar' : 'Activar' }}
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td
                                    colspan="5"
                                    class="px-4 py-10 text-center text-sm text-zinc-500"
                                >
                                    No se encontraron clientes.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="border-t border-zinc-200 p-4 dark:border-zinc-700">
                {{ $this->customers->links() }}
            </div>
        </div>
    </div>
</div>