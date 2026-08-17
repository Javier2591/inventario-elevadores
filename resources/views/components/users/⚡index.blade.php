<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    // Usuario que se está editando.
    public ?int $editingUserId = null;

    // Datos del formulario.
    public string $name = '';
    public string $email = '';
    public string $phone = '';
    public string $role = User::ROLE_TECHNICIAN;
    public bool $is_active = true;
    public string $password = '';
    public string $password_confirmation = '';

    // Filtros.
    public string $search = '';
    public string $roleFilter = 'all';
    public string $statusFilter = 'all';

    /**
     * Solo los administradores pueden utilizar esta pantalla.
     */
    public function mount(): void
    {
        abort_unless(
            auth()->user()?->isAdmin(),
            403
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
            'email' => [
                'required',
                'string',
                'lowercase',
                'email',
                'max:255',
                Rule::unique('users', 'email')
                    ->ignore($this->editingUserId),
            ],
            'phone' => [
                'nullable',
                'string',
                'min:7',
                'max:25',
                'regex:/^[0-9+\s().-]+$/',
            ],
            'role' => [
                'required',
                Rule::in([
                    User::ROLE_ADMIN,
                    User::ROLE_TECHNICIAN,
                ]),
            ],
            'is_active' => [
                'boolean',
            ],
            'password' => [
                'nullable',
                Rule::requiredIf(
                    $this->editingUserId === null
                ),
                'string',
                'min:8',
                'max:255',
                'confirmed',
            ],
            'password_confirmation' => [
                'nullable',
                'string',
                'max:255',
            ],
        ];
    }

    /**
     * Mensajes de validación en español.
     */
    protected function messages(): array
    {
        return [
            'name.required' => 'Escribe el nombre del usuario.',
            'name.min' => 'El nombre debe contener al menos 2 caracteres.',
            'name.max' => 'El nombre no puede superar los 150 caracteres.',

            'email.required' => 'Escribe el correo electrónico.',
            'email.email' => 'Escribe un correo electrónico válido.',
            'email.max' => 'El correo no puede superar los 255 caracteres.',
            'email.unique' => 'Ya existe un usuario con este correo.',

            'phone.min' => 'El teléfono debe contener al menos 7 caracteres.',
            'phone.max' => 'El teléfono no puede superar los 25 caracteres.',
            'phone.regex' => 'El teléfono contiene caracteres no válidos.',

            'role.required' => 'Selecciona una función.',
            'role.in' => 'La función seleccionada no es válida.',

            'password.required' => 'Escribe una contraseña.',
            'password.min' => 'La contraseña debe contener al menos 8 caracteres.',
            'password.max' => 'La contraseña no puede superar los 255 caracteres.',
            'password.confirmed' => 'La confirmación de la contraseña no coincide.',
        ];
    }

    /**
     * Obtiene los usuarios aplicando filtros.
     */
    #[Computed]
    public function users()
    {
        $search = trim($this->search);

        return User::query()
            ->withCount('serviceOrdersAssigned')
            ->when($search !== '', function ($query) use ($search) {
                $term = '%' . $search . '%';

                $query->where(function ($query) use ($term) {
                    $query
                        ->where('name', 'like', $term)
                        ->orWhere('email', 'like', $term)
                        ->orWhere('phone', 'like', $term);
                });
            })
            ->when(
                $this->roleFilter !== 'all',
                fn ($query) => $query->where(
                    'role',
                    $this->roleFilter
                )
            )
            ->when(
                $this->statusFilter === 'active',
                fn ($query) => $query->where(
                    'is_active',
                    true
                )
            )
            ->when(
                $this->statusFilter === 'inactive',
                fn ($query) => $query->where(
                    'is_active',
                    false
                )
            )
            ->orderBy('name')
            ->paginate(10);
    }

    /**
     * Crea o actualiza un usuario.
     */
    public function save(): void
    {
        $this->authorizeAdmin();
        $this->normalizeFields();

        $validated = $this->validate();

        $data = [
            'name' => $validated['name'],
            'email' => $validated['email'],
            'phone' => $validated['phone'] !== ''
                ? $validated['phone']
                : null,
            'role' => $validated['role'],
            'is_active' => (bool) $validated['is_active'],
        ];

        if ($validated['password'] !== '') {
            $data['password'] = Hash::make(
                $validated['password']
            );
        }

        if ($this->editingUserId !== null) {
            $user = User::query()
                ->findOrFail($this->editingUserId);

            /*
             * El administrador autenticado no puede quitarse
             * a sí mismo el acceso ni cambiar su propia función.
             */
            if (
                $user->is(auth()->user()) &&
                (
                    $validated['role'] !== User::ROLE_ADMIN ||
                    ! $validated['is_active']
                )
            ) {
                throw ValidationException::withMessages([
                    'role' => 'No puedes quitar tu propio acceso administrativo.',
                ]);
            }

            $this->ensureAnotherAdministratorExists(
                $user,
                $validated['role'],
                (bool) $validated['is_active']
            );

            $user->update($data);

            $message = 'Usuario actualizado correctamente.';
        } else {
            User::create($data);

            $message = 'Usuario registrado correctamente.';
        }

        $this->resetForm();
        $this->resetPage();

        unset($this->users);

        session()->flash('success', $message);
    }

    /**
     * Carga un usuario en el formulario.
     */
    public function edit(int $userId): void
    {
        $this->authorizeAdmin();

        $user = User::query()
            ->findOrFail($userId);

        $this->editingUserId = $user->id;
        $this->name = $user->name;
        $this->email = $user->email;
        $this->phone = $user->phone ?? '';
        $this->role = $user->role;
        $this->is_active = (bool) $user->is_active;
        $this->password = '';
        $this->password_confirmation = '';

        $this->resetValidation();
    }

    /**
     * Activa o bloquea un usuario.
     */
    public function toggleStatus(int $userId): void
    {
        $this->authorizeAdmin();

        $user = User::query()
            ->findOrFail($userId);

        if ($user->is(auth()->user())) {
            throw ValidationException::withMessages([
                'user_action' => 'No puedes bloquear tu propia cuenta.',
            ]);
        }

        $newStatus = ! $user->is_active;

        $this->ensureAnotherAdministratorExists(
            $user,
            $user->role,
            $newStatus
        );

        $user->update([
            'is_active' => $newStatus,
        ]);

        if ($this->editingUserId === $user->id) {
            $this->resetForm();
        }

        unset($this->users);

        session()->flash(
            'success',
            $newStatus
                ? 'Usuario activado correctamente.'
                : 'Usuario bloqueado correctamente.'
        );
    }

    /**
     * Limpia el formulario.
     */
    public function resetForm(): void
    {
        $this->reset([
            'editingUserId',
            'name',
            'email',
            'phone',
            'password',
            'password_confirmation',
        ]);

        $this->role = User::ROLE_TECHNICIAN;
        $this->is_active = true;

        $this->resetValidation();
    }

    /**
     * Evita dejar el sistema sin administradores activos.
     */
    private function ensureAnotherAdministratorExists(
        User $user,
        string $newRole,
        bool $newStatus
    ): void {
        if (! $user->isAdmin()) {
            return;
        }

        $removesAdministrativeAccess =
            $newRole !== User::ROLE_ADMIN ||
            ! $newStatus;

        if (! $removesAdministrativeAccess) {
            return;
        }

        $anotherAdministratorExists = User::query()
            ->admins()
            ->active()
            ->whereKeyNot($user->id)
            ->exists();

        if (! $anotherAdministratorExists) {
            throw ValidationException::withMessages([
                'role' => 'Debe existir por lo menos un administrador activo.',
            ]);
        }
    }

    /**
     * Comprueba nuevamente el permiso antes de modificar datos.
     */
    private function authorizeAdmin(): void
    {
        abort_unless(
            auth()->user()?->isAdmin(),
            403
        );
    }

    /**
     * Normaliza los datos antes de validarlos.
     */
    private function normalizeFields(): void
    {
        $this->name = Str::squish($this->name);
        $this->email = Str::lower(
            trim($this->email)
        );
        $this->phone = Str::squish($this->phone);
    }

    /**
     * Actualiza la lista al modificar filtros.
     */
    public function updatedSearch(): void
    {
        $this->refreshList();
    }

    public function updatedRoleFilter(): void
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

        unset($this->users);
    }
};

?>

<div class="mx-auto w-full max-w-7xl space-y-6 p-4 sm:p-6">
    {{-- Encabezado --}}
    <div>
        <h1 class="text-2xl font-bold text-zinc-900 dark:text-white">
            Usuarios y técnicos
        </h1>

        <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">
            Administra cuentas, funciones y accesos al sistema.
        </p>
    </div>

    {{-- Mensajes --}}
    @if (session('success'))
        <div class="rounded-lg border border-green-300 bg-green-50 p-4 text-sm text-green-800 dark:border-green-700 dark:bg-green-950 dark:text-green-200">
            {{ session('success') }}
        </div>
    @endif

    @error('user_action')
        <div class="rounded-lg border border-red-300 bg-red-50 p-4 text-sm text-red-800 dark:border-red-700 dark:bg-red-950 dark:text-red-200">
            {{ $message }}
        </div>
    @enderror

    <div class="grid gap-6 xl:grid-cols-5">
        {{-- Formulario --}}
        <form
            wire:submit="save"
            class="space-y-5 rounded-xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900 xl:col-span-2"
        >
            <div>
                <h2 class="text-lg font-semibold text-zinc-900 dark:text-white">
                    {{ $editingUserId ? 'Editar usuario' : 'Nuevo usuario' }}
                </h2>

                <p class="mt-1 text-sm text-zinc-500">
                    {{ $editingUserId
                        ? 'Deja la contraseña vacía para conservar la actual.'
                        : 'Todos los campos con asterisco son obligatorios.' }}
                </p>
            </div>

            {{-- Nombre --}}
            <div>
                <label
                    for="name"
                    class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300"
                >
                    Nombre completo *
                </label>

                <input
                    id="name"
                    type="text"
                    wire:model="name"
                    maxlength="150"
                    autocomplete="name"
                    placeholder="Ej. Carlos Hernández"
                    class="w-full rounded-lg border border-zinc-300 px-3 py-2 outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-200 dark:border-zinc-600 dark:bg-zinc-800 dark:text-white"
                >

                @error('name')
                    <p class="mt-1 text-sm text-red-600">
                        {{ $message }}
                    </p>
                @enderror
            </div>

            {{-- Correo --}}
            <div>
                <label
                    for="email"
                    class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300"
                >
                    Correo electrónico *
                </label>

                <input
                    id="email"
                    type="email"
                    wire:model="email"
                    maxlength="255"
                    autocomplete="email"
                    placeholder="tecnico@empresa.com"
                    class="w-full rounded-lg border border-zinc-300 px-3 py-2 outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-200 dark:border-zinc-600 dark:bg-zinc-800 dark:text-white"
                >

                @error('email')
                    <p class="mt-1 text-sm text-red-600">
                        {{ $message }}
                    </p>
                @enderror
            </div>

            {{-- Teléfono --}}
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
                    autocomplete="tel"
                    placeholder="Ej. 55 1234 5678"
                    class="w-full rounded-lg border border-zinc-300 px-3 py-2 outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-200 dark:border-zinc-600 dark:bg-zinc-800 dark:text-white"
                >

                @error('phone')
                    <p class="mt-1 text-sm text-red-600">
                        {{ $message }}
                    </p>
                @enderror
            </div>

            {{-- Función --}}
            <div>
                <label
                    for="role"
                    class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300"
                >
                    Función *
                </label>

                <select
                    id="role"
                    wire:model="role"
                    class="w-full rounded-lg border border-zinc-300 px-3 py-2 outline-none dark:border-zinc-600 dark:bg-zinc-800 dark:text-white"
                >
                    <option value="technician">Técnico</option>
                    <option value="admin">Administrador</option>
                </select>

                @error('role')
                    <p class="mt-1 text-sm text-red-600">
                        {{ $message }}
                    </p>
                @enderror
            </div>

            {{-- Contraseñas --}}
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label
                        for="password"
                        class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300"
                    >
                        Contraseña {{ $editingUserId ? '' : '*' }}
                    </label>

                    <input
                        id="password"
                        type="password"
                        wire:model="password"
                        maxlength="255"
                        autocomplete="new-password"
                        class="w-full rounded-lg border border-zinc-300 px-3 py-2 outline-none dark:border-zinc-600 dark:bg-zinc-800 dark:text-white"
                    >

                    @error('password')
                        <p class="mt-1 text-sm text-red-600">
                            {{ $message }}
                        </p>
                    @enderror
                </div>

                <div>
                    <label
                        for="password_confirmation"
                        class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300"
                    >
                        Confirmar contraseña
                    </label>

                    <input
                        id="password_confirmation"
                        type="password"
                        wire:model="password_confirmation"
                        maxlength="255"
                        autocomplete="new-password"
                        class="w-full rounded-lg border border-zinc-300 px-3 py-2 outline-none dark:border-zinc-600 dark:bg-zinc-800 dark:text-white"
                    >
                </div>
            </div>

            {{-- Estado --}}
            <label class="flex cursor-pointer items-center gap-3">
                <input
                    type="checkbox"
                    wire:model="is_active"
                    class="rounded border-zinc-300 text-blue-600 focus:ring-blue-500"
                >

                <span class="text-sm font-medium text-zinc-700 dark:text-zinc-300">
                    Usuario activo
                </span>
            </label>

            {{-- Botones --}}
            <div class="flex flex-wrap gap-3">
                <button
                    type="submit"
                    wire:loading.attr="disabled"
                    wire:target="save"
                    class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700 disabled:opacity-60"
                >
                    <span wire:loading.remove wire:target="save">
                        {{ $editingUserId ? 'Actualizar' : 'Guardar' }}
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
                    {{ $editingUserId ? 'Cancelar edición' : 'Limpiar' }}
                </button>
            </div>
        </form>

        {{-- Catálogo --}}
        <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-900 xl:col-span-3">
            {{-- Filtros --}}
            <div class="grid gap-3 border-b border-zinc-200 p-4 dark:border-zinc-700 md:grid-cols-3">
                <input
                    type="search"
                    wire:model.live.debounce.300ms="search"
                    placeholder="Buscar nombre, correo o teléfono..."
                    class="rounded-lg border border-zinc-300 px-3 py-2 outline-none dark:border-zinc-600 dark:bg-zinc-800 dark:text-white"
                >

                <select
                    wire:model.live="roleFilter"
                    class="rounded-lg border border-zinc-300 px-3 py-2 outline-none dark:border-zinc-600 dark:bg-zinc-800 dark:text-white"
                >
                    <option value="all">Todas las funciones</option>
                    <option value="admin">Administradores</option>
                    <option value="technician">Técnicos</option>
                </select>

                <select
                    wire:model.live="statusFilter"
                    class="rounded-lg border border-zinc-300 px-3 py-2 outline-none dark:border-zinc-600 dark:bg-zinc-800 dark:text-white"
                >
                    <option value="all">Todos los estados</option>
                    <option value="active">Activos</option>
                    <option value="inactive">Bloqueados</option>
                </select>
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-700">
                    <thead class="bg-zinc-50 dark:bg-zinc-800">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase text-zinc-600 dark:text-zinc-300">
                                Usuario
                            </th>

                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase text-zinc-600 dark:text-zinc-300">
                                Función
                            </th>

                            <th class="px-4 py-3 text-center text-xs font-semibold uppercase text-zinc-600 dark:text-zinc-300">
                                Órdenes
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
                        @forelse ($this->users as $user)
                            <tr wire:key="user-{{ $user->id }}">
                                <td class="px-4 py-4">
                                    <div class="flex items-center gap-3">
                                        <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-blue-100 text-sm font-bold text-blue-700">
                                            {{ $user->initials() }}
                                        </div>

                                        <div class="min-w-0">
                                            <p class="font-medium text-zinc-900 dark:text-white">
                                                {{ $user->name }}

                                                @if ($user->is(auth()->user()))
                                                    <span class="text-xs text-blue-600">
                                                        (Tú)
                                                    </span>
                                                @endif
                                            </p>

                                            <p class="mt-1 truncate text-xs text-zinc-500">
                                                {{ $user->email }}
                                            </p>

                                            @if ($user->phone)
                                                <p class="mt-1 text-xs text-zinc-500">
                                                    {{ $user->phone }}
                                                </p>
                                            @endif
                                        </div>
                                    </div>
                                </td>

                                <td class="px-4 py-4">
                                    <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold {{ $user->isAdmin() ? 'bg-purple-100 text-purple-700' : 'bg-blue-100 text-blue-700' }}">
                                        {{ $user->roleLabel() }}
                                    </span>
                                </td>

                                <td class="px-4 py-4 text-center">
                                    <span class="font-semibold text-zinc-900 dark:text-white">
                                        {{ $user->service_orders_assigned_count }}
                                    </span>

                                    <p class="mt-1 text-xs text-zinc-500">
                                        asignadas
                                    </p>
                                </td>

                                <td class="px-4 py-4 text-center">
                                    <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold {{ $user->is_active ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700' }}">
                                        {{ $user->is_active ? 'Activo' : 'Bloqueado' }}
                                    </span>
                                </td>

                                <td class="px-4 py-4">
                                    <div class="flex justify-end gap-2">
                                        <button
                                            type="button"
                                            wire:click="edit({{ $user->id }})"
                                            class="rounded-lg border border-blue-400 px-3 py-2 text-sm font-semibold text-blue-600 hover:bg-blue-50 dark:hover:bg-blue-950"
                                        >
                                            Editar
                                        </button>

                                        @if (! $user->is(auth()->user()))
                                            <button
                                                type="button"
                                                wire:click="toggleStatus({{ $user->id }})"
                                                wire:confirm="¿Confirmas que deseas cambiar el acceso de este usuario?"
                                                class="rounded-lg border border-zinc-400 px-3 py-2 text-sm font-semibold text-zinc-700 hover:bg-zinc-100 dark:text-zinc-200 dark:hover:bg-zinc-800"
                                            >
                                                {{ $user->is_active ? 'Bloquear' : 'Activar' }}
                                            </button>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-4 py-10 text-center text-sm text-zinc-500">
                                    No se encontraron usuarios.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="border-t border-zinc-200 p-4 dark:border-zinc-700">
                {{ $this->users->links() }}
            </div>
        </div>
    </div>
</div>