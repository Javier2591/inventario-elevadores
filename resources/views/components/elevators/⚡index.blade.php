<?php

use App\Models\Elevator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    // Elevador que se está editando.
    public ?int $editingElevatorId = null;

    // Datos del formulario.
    public string $code = '';
    public string $client_name = '';
    public string $building_name = '';
    public string $address = '';
    public string $brand = '';
    public string $model = '';
    public string $serial_number = '';
    public string $stops = '';
    public string $capacity_kg = '';
    public string $installation_date = '';
    public string $last_service_date = '';
    public string $status = Elevator::STATUS_ACTIVE;
    public string $notes = '';

    // Filtros del catálogo.
    public string $search = '';
    public string $statusFilter = 'all';

    /**
     * Reglas de validación.
     */
    protected function rules(): array
    {
        return [
            'code' => [
                'required',
                'string',
                'min:2',
                'max:50',
                'regex:/^[A-Z0-9._-]+$/',
                Rule::unique('elevators', 'code')
                    ->ignore($this->editingElevatorId),
            ],
            'client_name' => [
                'required',
                'string',
                'min:2',
                'max:150',
            ],
            'building_name' => [
                'required',
                'string',
                'min:2',
                'max:150',
            ],
            'address' => [
                'required',
                'string',
                'min:5',
                'max:2000',
            ],
            'brand' => [
                'nullable',
                'string',
                'max:100',
            ],
            'model' => [
                'nullable',
                'string',
                'max:100',
            ],
            'serial_number' => [
                'nullable',
                'string',
                'max:100',
                Rule::unique('elevators', 'serial_number')
                    ->ignore($this->editingElevatorId),
            ],
            'stops' => [
                'nullable',
                'integer',
                'min:1',
                'max:500',
            ],
            'capacity_kg' => [
                'nullable',
                'integer',
                'min:1',
                'max:65535',
            ],
            'installation_date' => [
                'nullable',
                'date',
                'before_or_equal:today',
            ],
            'last_service_date' => [
                'nullable',
                'date',
                'before_or_equal:today',
                Rule::when(
                    $this->installation_date !== '',
                    ['after_or_equal:installation_date']
                ),
            ],
            'status' => [
                'required',
                Rule::in([
                    Elevator::STATUS_ACTIVE,
                    Elevator::STATUS_OUT_OF_SERVICE,
                    Elevator::STATUS_INACTIVE,
                ]),
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
            'code.required' => 'Escribe el código del elevador.',
            'code.min' => 'El código debe contener al menos 2 caracteres.',
            'code.max' => 'El código no puede superar los 50 caracteres.',
            'code.regex' => 'El código solamente puede contener letras, números, puntos, guiones y guion bajo.',
            'code.unique' => 'Ya existe un elevador con este código.',

            'client_name.required' => 'Escribe el nombre del cliente.',
            'client_name.min' => 'El nombre del cliente debe contener al menos 2 caracteres.',
            'client_name.max' => 'El nombre del cliente no puede superar los 150 caracteres.',

            'building_name.required' => 'Escribe el nombre del edificio.',
            'building_name.min' => 'El nombre del edificio debe contener al menos 2 caracteres.',
            'building_name.max' => 'El nombre del edificio no puede superar los 150 caracteres.',

            'address.required' => 'Escribe la dirección donde está instalado el elevador.',
            'address.min' => 'La dirección debe contener al menos 5 caracteres.',
            'address.max' => 'La dirección no puede superar los 2000 caracteres.',

            'brand.max' => 'La marca no puede superar los 100 caracteres.',
            'model.max' => 'El modelo no puede superar los 100 caracteres.',

            'serial_number.max' => 'El número de serie no puede superar los 100 caracteres.',
            'serial_number.unique' => 'Ya existe un elevador con este número de serie.',

            'stops.integer' => 'Las paradas deben expresarse con un número entero.',
            'stops.min' => 'El elevador debe tener por lo menos una parada.',
            'stops.max' => 'El número de paradas supera el límite permitido.',

            'capacity_kg.integer' => 'La capacidad debe expresarse con un número entero.',
            'capacity_kg.min' => 'La capacidad debe ser mayor que cero.',
            'capacity_kg.max' => 'La capacidad supera el límite permitido.',

            'installation_date.date' => 'La fecha de instalación no es válida.',
            'installation_date.before_or_equal' => 'La fecha de instalación no puede ser posterior al día de hoy.',

            'last_service_date.date' => 'La fecha del último servicio no es válida.',
            'last_service_date.before_or_equal' => 'La fecha del último servicio no puede ser posterior al día de hoy.',
            'last_service_date.after_or_equal' => 'El último servicio no puede ser anterior a la instalación.',

            'status.required' => 'Selecciona el estado del elevador.',
            'status.in' => 'El estado seleccionado no es válido.',

            'notes.max' => 'Las notas no pueden superar los 2000 caracteres.',
        ];
    }

    /**
     * Obtiene el catálogo aplicando búsqueda y estado.
     */
    #[Computed]
    public function elevators()
    {
        return Elevator::query()
            ->search($this->search)
            ->when(
                $this->statusFilter !== 'all',
                fn ($query) => $query->where(
                    'status',
                    $this->statusFilter
                )
            )
            ->orderBy('client_name')
            ->orderBy('building_name')
            ->orderBy('code')
            ->paginate(10);
    }

    /**
     * Crea o actualiza un elevador.
     */
    public function save(): void
    {
        $this->normalizeFields();

        $validated = $this->validate();

        $data = [
            'code' => $validated['code'],
            'client_name' => $validated['client_name'],
            'building_name' => $validated['building_name'],
            'address' => $validated['address'],
            'brand' => $validated['brand'] !== ''
                ? $validated['brand']
                : null,
            'model' => $validated['model'] !== ''
                ? $validated['model']
                : null,
            'serial_number' => $validated['serial_number'] !== ''
                ? $validated['serial_number']
                : null,
            'stops' => $validated['stops'] !== ''
                ? (int) $validated['stops']
                : null,
            'capacity_kg' => $validated['capacity_kg'] !== ''
                ? (int) $validated['capacity_kg']
                : null,
            'installation_date' => $validated['installation_date'] !== ''
                ? $validated['installation_date']
                : null,
            'last_service_date' => $validated['last_service_date'] !== ''
                ? $validated['last_service_date']
                : null,
            'status' => $validated['status'],
            'notes' => $validated['notes'] !== ''
                ? $validated['notes']
                : null,
        ];

        if ($this->editingElevatorId !== null) {
            $elevator = Elevator::query()
                ->findOrFail($this->editingElevatorId);

            $elevator->update($data);

            $message = 'Elevador actualizado correctamente.';
        } else {
            Elevator::create($data);

            $message = 'Elevador registrado correctamente.';
        }

        $this->resetForm();
        $this->resetPage();

        unset($this->elevators);

        session()->flash('success', $message);
    }

    /**
     * Carga el elevador seleccionado en el formulario.
     */
    public function edit(int $elevatorId): void
    {
        $elevator = Elevator::query()
            ->findOrFail($elevatorId);

        $this->editingElevatorId = $elevator->id;
        $this->code = $elevator->code;
        $this->client_name = $elevator->client_name;
        $this->building_name = $elevator->building_name;
        $this->address = $elevator->address;
        $this->brand = $elevator->brand ?? '';
        $this->model = $elevator->model ?? '';
        $this->serial_number = $elevator->serial_number ?? '';
        $this->stops = $elevator->stops !== null
            ? (string) $elevator->stops
            : '';
        $this->capacity_kg = $elevator->capacity_kg !== null
            ? (string) $elevator->capacity_kg
            : '';
        $this->installation_date = $elevator->installation_date?->format('Y-m-d') ?? '';
        $this->last_service_date = $elevator->last_service_date?->format('Y-m-d') ?? '';
        $this->status = $elevator->status;
        $this->notes = $elevator->notes ?? '';

        $this->resetValidation();
    }

    /**
     * Alterna entre activo y fuera de servicio.
     */
    public function toggleOperationalStatus(int $elevatorId): void
    {
        $elevator = Elevator::query()
            ->findOrFail($elevatorId);

        $newStatus = $elevator->isActive()
            ? Elevator::STATUS_OUT_OF_SERVICE
            : Elevator::STATUS_ACTIVE;

        $elevator->update([
            'status' => $newStatus,
        ]);

        if ($this->editingElevatorId === $elevator->id) {
            $this->resetForm();
        }

        unset($this->elevators);

        session()->flash(
            'success',
            $newStatus === Elevator::STATUS_ACTIVE
                ? 'Elevador marcado como activo.'
                : 'Elevador marcado fuera de servicio.'
        );
    }

    /**
     * Limpia el formulario.
     */
    public function resetForm(): void
    {
        $this->reset([
            'editingElevatorId',
            'code',
            'client_name',
            'building_name',
            'address',
            'brand',
            'model',
            'serial_number',
            'stops',
            'capacity_kg',
            'installation_date',
            'last_service_date',
            'notes',
        ]);

        $this->status = Elevator::STATUS_ACTIVE;

        $this->resetValidation();
    }

    /**
     * Normaliza los textos antes de validarlos.
     */
    private function normalizeFields(): void
    {
        $this->code = Str::upper(
            preg_replace(
                '/\s+/',
                '-',
                Str::squish($this->code)
            ) ?? ''
        );

        $this->client_name = Str::squish($this->client_name);
        $this->building_name = Str::squish($this->building_name);
        $this->address = Str::squish($this->address);
        $this->brand = Str::squish($this->brand);
        $this->model = Str::squish($this->model);
        $this->serial_number = Str::upper(
            Str::squish($this->serial_number)
        );
        $this->notes = trim($this->notes);
    }

    /**
     * Regresa a la primera página al cambiar filtros.
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

        unset($this->elevators);
    }
};

?>

<div class="mx-auto w-full max-w-7xl space-y-6 p-4 sm:p-6">
    {{-- Encabezado --}}
    <div>
        <h1 class="text-2xl font-bold text-zinc-900 dark:text-white">
            Elevadores
        </h1>

        <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">
            Registra y administra los equipos atendidos.
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
                    {{ $editingElevatorId ? 'Editar elevador' : 'Nuevo elevador' }}
                </h2>

                <p class="mt-1 text-sm text-zinc-500">
                    Los campos con asterisco son obligatorios.
                </p>
            </div>

            {{-- Código --}}
            <div>
                <label
                    for="code"
                    class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300"
                >
                    Código del elevador *
                </label>

                <input
                    id="code"
                    type="text"
                    wire:model="code"
                    maxlength="50"
                    placeholder="Ej. ELEV-001"
                    class="w-full uppercase rounded-lg border border-zinc-300 px-3 py-2 outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-200 dark:border-zinc-600 dark:bg-zinc-800 dark:text-white"
                >

                @error('code')
                    <p class="mt-1 text-sm text-red-600">
                        {{ $message }}
                    </p>
                @enderror
            </div>

            {{-- Cliente --}}
            <div>
                <label
                    for="client_name"
                    class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300"
                >
                    Cliente *
                </label>

                <input
                    id="client_name"
                    type="text"
                    wire:model="client_name"
                    maxlength="150"
                    placeholder="Ej. Condominio Reforma"
                    class="w-full rounded-lg border border-zinc-300 px-3 py-2 outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-200 dark:border-zinc-600 dark:bg-zinc-800 dark:text-white"
                >

                @error('client_name')
                    <p class="mt-1 text-sm text-red-600">
                        {{ $message }}
                    </p>
                @enderror
            </div>

            {{-- Edificio --}}
            <div>
                <label
                    for="building_name"
                    class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300"
                >
                    Edificio o inmueble *
                </label>

                <input
                    id="building_name"
                    type="text"
                    wire:model="building_name"
                    maxlength="150"
                    placeholder="Ej. Torre Reforma"
                    class="w-full rounded-lg border border-zinc-300 px-3 py-2 outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-200 dark:border-zinc-600 dark:bg-zinc-800 dark:text-white"
                >

                @error('building_name')
                    <p class="mt-1 text-sm text-red-600">
                        {{ $message }}
                    </p>
                @enderror
            </div>

            {{-- Dirección --}}
            <div>
                <label
                    for="address"
                    class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300"
                >
                    Dirección *
                </label>

                <textarea
                    id="address"
                    wire:model="address"
                    rows="3"
                    maxlength="2000"
                    placeholder="Dirección donde está instalado el equipo"
                    class="w-full resize-none rounded-lg border border-zinc-300 px-3 py-2 outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-200 dark:border-zinc-600 dark:bg-zinc-800 dark:text-white"
                ></textarea>

                @error('address')
                    <p class="mt-1 text-sm text-red-600">
                        {{ $message }}
                    </p>
                @enderror
            </div>

            {{-- Marca y modelo --}}
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label
                        for="brand"
                        class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300"
                    >
                        Marca
                    </label>

                    <input
                        id="brand"
                        type="text"
                        wire:model="brand"
                        maxlength="100"
                        placeholder="Ej. Schindler"
                        class="w-full rounded-lg border border-zinc-300 px-3 py-2 outline-none dark:border-zinc-600 dark:bg-zinc-800 dark:text-white"
                    >

                    @error('brand')
                        <p class="mt-1 text-sm text-red-600">
                            {{ $message }}
                        </p>
                    @enderror
                </div>

                <div>
                    <label
                        for="model"
                        class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300"
                    >
                        Modelo
                    </label>

                    <input
                        id="model"
                        type="text"
                        wire:model="model"
                        maxlength="100"
                        placeholder="Ej. 3300"
                        class="w-full rounded-lg border border-zinc-300 px-3 py-2 outline-none dark:border-zinc-600 dark:bg-zinc-800 dark:text-white"
                    >

                    @error('model')
                        <p class="mt-1 text-sm text-red-600">
                            {{ $message }}
                        </p>
                    @enderror
                </div>
            </div>

            {{-- Número de serie --}}
            <div>
                <label
                    for="serial_number"
                    class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300"
                >
                    Número de serie
                </label>

                <input
                    id="serial_number"
                    type="text"
                    wire:model="serial_number"
                    maxlength="100"
                    placeholder="Ej. SCH-2026-001"
                    class="w-full uppercase rounded-lg border border-zinc-300 px-3 py-2 outline-none dark:border-zinc-600 dark:bg-zinc-800 dark:text-white"
                >

                @error('serial_number')
                    <p class="mt-1 text-sm text-red-600">
                        {{ $message }}
                    </p>
                @enderror
            </div>

            {{-- Paradas y capacidad --}}
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label
                        for="stops"
                        class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300"
                    >
                        Número de paradas
                    </label>

                    <input
                        id="stops"
                        type="number"
                        wire:model="stops"
                        min="1"
                        max="500"
                        step="1"
                        placeholder="Ej. 10"
                        class="w-full rounded-lg border border-zinc-300 px-3 py-2 outline-none dark:border-zinc-600 dark:bg-zinc-800 dark:text-white"
                    >

                    @error('stops')
                        <p class="mt-1 text-sm text-red-600">
                            {{ $message }}
                        </p>
                    @enderror
                </div>

                <div>
                    <label
                        for="capacity_kg"
                        class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300"
                    >
                        Capacidad en kg
                    </label>

                    <input
                        id="capacity_kg"
                        type="number"
                        wire:model="capacity_kg"
                        min="1"
                        max="65535"
                        step="1"
                        placeholder="Ej. 800"
                        class="w-full rounded-lg border border-zinc-300 px-3 py-2 outline-none dark:border-zinc-600 dark:bg-zinc-800 dark:text-white"
                    >

                    @error('capacity_kg')
                        <p class="mt-1 text-sm text-red-600">
                            {{ $message }}
                        </p>
                    @enderror
                </div>
            </div>

            {{-- Fechas --}}
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label
                        for="installation_date"
                        class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300"
                    >
                        Fecha de instalación
                    </label>

                    <input
                        id="installation_date"
                        type="date"
                        wire:model="installation_date"
                        max="{{ now()->toDateString() }}"
                        class="w-full rounded-lg border border-zinc-300 px-3 py-2 outline-none dark:border-zinc-600 dark:bg-zinc-800 dark:text-white"
                    >

                    @error('installation_date')
                        <p class="mt-1 text-sm text-red-600">
                            {{ $message }}
                        </p>
                    @enderror
                </div>

                <div>
                    <label
                        for="last_service_date"
                        class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300"
                    >
                        Último servicio
                    </label>

                    <input
                        id="last_service_date"
                        type="date"
                        wire:model="last_service_date"
                        max="{{ now()->toDateString() }}"
                        class="w-full rounded-lg border border-zinc-300 px-3 py-2 outline-none dark:border-zinc-600 dark:bg-zinc-800 dark:text-white"
                    >

                    @error('last_service_date')
                        <p class="mt-1 text-sm text-red-600">
                            {{ $message }}
                        </p>
                    @enderror
                </div>
            </div>

            {{-- Estado --}}
            <div>
                <label
                    for="status"
                    class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300"
                >
                    Estado *
                </label>

                <select
                    id="status"
                    wire:model="status"
                    class="w-full rounded-lg border border-zinc-300 px-3 py-2 outline-none dark:border-zinc-600 dark:bg-zinc-800 dark:text-white"
                >
                    <option value="active">Activo</option>
                    <option value="out_of_service">Fuera de servicio</option>
                    <option value="inactive">Inactivo</option>
                </select>

                @error('status')
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
                    placeholder="Información técnica o administrativa"
                    class="w-full resize-none rounded-lg border border-zinc-300 px-3 py-2 outline-none dark:border-zinc-600 dark:bg-zinc-800 dark:text-white"
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
                        {{ $editingElevatorId ? 'Actualizar' : 'Guardar' }}
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
                    {{ $editingElevatorId ? 'Cancelar edición' : 'Limpiar' }}
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
                    placeholder="Buscar por código, cliente, edificio..."
                    class="rounded-lg border border-zinc-300 px-3 py-2 outline-none dark:border-zinc-600 dark:bg-zinc-800 dark:text-white"
                >

                <select
                    wire:model.live="statusFilter"
                    class="rounded-lg border border-zinc-300 px-3 py-2 outline-none dark:border-zinc-600 dark:bg-zinc-800 dark:text-white"
                >
                    <option value="all">Todos los estados</option>
                    <option value="active">Activos</option>
                    <option value="out_of_service">Fuera de servicio</option>
                    <option value="inactive">Inactivos</option>
                </select>
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-700">
                    <thead class="bg-zinc-50 dark:bg-zinc-800">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase text-zinc-600 dark:text-zinc-300">
                                Elevador
                            </th>

                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase text-zinc-600 dark:text-zinc-300">
                                Ubicación
                            </th>

                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase text-zinc-600 dark:text-zinc-300">
                                Datos técnicos
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
                        @forelse ($this->elevators as $elevator)
                            @php
                                $statusClasses = match ($elevator->status) {
                                    \App\Models\Elevator::STATUS_ACTIVE =>
                                        'bg-green-100 text-green-700',
                                    \App\Models\Elevator::STATUS_OUT_OF_SERVICE =>
                                        'bg-red-100 text-red-700',
                                    default =>
                                        'bg-zinc-200 text-zinc-700',
                                };
                            @endphp

                            <tr wire:key="elevator-{{ $elevator->id }}">
                                <td class="px-4 py-4">
                                    <p class="font-semibold text-zinc-900 dark:text-white">
                                        {{ $elevator->code }}
                                    </p>

                                    <p class="mt-1 text-sm text-zinc-700 dark:text-zinc-300">
                                        {{ $elevator->client_name }}
                                    </p>

                                    <p class="mt-1 text-xs text-zinc-500">
                                        Serie: {{ $elevator->serial_number ?: 'No registrada' }}
                                    </p>
                                </td>

                                <td class="px-4 py-4">
                                    <p class="font-medium text-zinc-900 dark:text-white">
                                        {{ $elevator->building_name }}
                                    </p>

                                    <p class="mt-1 max-w-xs text-xs text-zinc-500">
                                        {{ $elevator->address }}
                                    </p>
                                </td>

                                <td class="px-4 py-4 text-sm text-zinc-700 dark:text-zinc-300">
                                    <p>
                                        {{ $elevator->brand ?: 'Sin marca' }}
                                        {{ $elevator->model ?: '' }}
                                    </p>

                                    <p class="mt-1 text-xs text-zinc-500">
                                        {{ $elevator->stops ?: '—' }} paradas
                                        ·
                                        {{ $elevator->capacity_kg ?: '—' }} kg
                                    </p>
                                </td>

                                <td class="px-4 py-4 text-center">
                                    <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold {{ $statusClasses }}">
                                        {{ $elevator->statusLabel() }}
                                    </span>
                                </td>

                                <td class="px-4 py-4">
                                    <div class="flex justify-end gap-2">
                                        <button
                                            type="button"
                                            wire:click="edit({{ $elevator->id }})"
                                            class="rounded-lg border border-blue-400 px-3 py-2 text-sm font-semibold text-blue-600 hover:bg-blue-50 dark:hover:bg-blue-950"
                                        >
                                            Editar
                                        </button>

                                        <button
                                            type="button"
                                            wire:click="toggleOperationalStatus({{ $elevator->id }})"
                                            wire:confirm="¿Confirmas que deseas cambiar el estado operativo?"
                                            class="rounded-lg border border-zinc-400 px-3 py-2 text-sm font-semibold text-zinc-700 hover:bg-zinc-100 dark:text-zinc-200 dark:hover:bg-zinc-800"
                                        >
                                            {{ $elevator->isActive() ? 'Fuera de servicio' : 'Activar' }}
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-4 py-10 text-center text-sm text-zinc-500">
                                    No se encontraron elevadores.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="border-t border-zinc-200 p-4 dark:border-zinc-700">
                {{ $this->elevators->links() }}
            </div>
        </div>
    </div>
</div>