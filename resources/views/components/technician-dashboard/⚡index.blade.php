<?php

use App\Models\ServiceOrder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
|--------------------------------------------------------------------------
| Dashboard del técnico
|--------------------------------------------------------------------------
|
| El layout se asigna mediante un atributo de Livewire.
| Todas las consultas se limitan al técnico autenticado.
|
*/

new
#[Layout('layouts::app')]
#[Title('Dashboard del técnico')]
class extends Component
{
    /**
     * Verifica el acceso cuando se abre la página.
     */
    public function mount(): void
    {
        $this->authorizeTechnician();
    }

    /**
     * Consulta base de las órdenes del técnico autenticado.
     *
     * Este filtro evita que un técnico pueda consultar estadísticas
     * u órdenes pertenecientes a otro técnico.
     */
    private function assignedOrdersQuery(): Builder
    {
        return ServiceOrder::query()
            ->where('technician_id', Auth::id());
    }

    /**
     * Estadísticas generales del técnico.
     */
    #[Computed]
    public function metrics(): array
    {
        $query = $this->assignedOrdersQuery();

        return [
            'scheduled' => (clone $query)
                ->where('status', 'scheduled')
                ->count(),

            'in_progress' => (clone $query)
                ->where('status', 'in_progress')
                ->count(),

            'completed' => (clone $query)
                ->where('status', 'completed')
                ->count(),

            'total' => (clone $query)->count(),
        ];
    }

    /**
     * Órdenes programadas o actualmente en proceso.
     */
    #[Computed]
    public function activeOrders()
    {
        return $this->assignedOrdersQuery()
            ->with('elevator')
            ->whereIn('status', [
                'scheduled',
                'in_progress',
            ])
            ->orderByRaw(
                'CASE WHEN status = ? THEN 0 ELSE 1 END',
                ['in_progress']
            )
            ->orderBy('scheduled_at')
            ->limit(6)
            ->get();
    }

    /**
     * Últimas órdenes completadas por el técnico.
     */
    #[Computed]
    public function recentlyCompleted()
    {
        return $this->assignedOrdersQuery()
            ->with('elevator')
            ->where('status', 'completed')
            ->orderByDesc('completed_at')
            ->orderByDesc('updated_at')
            ->limit(5)
            ->get();
    }

    /**
     * Permite el acceso únicamente a técnicos activos.
     */
    private function authorizeTechnician(): void
    {
        $user = Auth::user();

        abort_unless(
            $user !== null
                && $user->role === 'technician'
                && (bool) $user->is_active,
            403,
            'Este Dashboard está disponible únicamente para técnicos activos.'
        );
    }

    /**
     * Traduce los estados guardados en la base de datos.
     */
    public function statusLabel(?string $status): string
    {
        return match ($status) {
            'scheduled' => 'Programada',
            'in_progress' => 'En proceso',
            'completed' => 'Completada',
            'cancelled' => 'Cancelada',
            default => 'Sin estado',
        };
    }

    /**
     * Colores correspondientes a cada estado.
     */
    public function statusClasses(?string $status): string
    {
        return match ($status) {
            'scheduled' =>
                'bg-blue-100 text-blue-700 dark:bg-blue-950 dark:text-blue-300',

            'in_progress' =>
                'bg-amber-100 text-amber-700 dark:bg-amber-950 dark:text-amber-300',

            'completed' =>
                'bg-green-100 text-green-700 dark:bg-green-950 dark:text-green-300',

            'cancelled' =>
                'bg-red-100 text-red-700 dark:bg-red-950 dark:text-red-300',

            default =>
                'bg-zinc-100 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300',
        };
    }

    /**
     * Traduce el tipo de servicio.
     */
    public function serviceTypeLabel(?string $type): string
    {
        return match ($type) {
            'preventive' => 'Preventivo',
            'corrective' => 'Correctivo',
            'inspection' => 'Inspección',
            'installation' => 'Instalación',
            default => 'Servicio',
        };
    }

    /**
     * Traduce la prioridad.
     */
    public function priorityLabel(?string $priority): string
    {
        return match ($priority) {
            'low' => 'Baja',
            'medium' => 'Media',
            'high' => 'Alta',
            'urgent' => 'Urgente',
            default => 'Sin prioridad',
        };
    }

    /**
     * Colores correspondientes a cada prioridad.
     */
    public function priorityClasses(?string $priority): string
    {
        return match ($priority) {
            'low' => 'text-green-500',
            'medium' => 'text-blue-500',
            'high' => 'text-orange-500',
            'urgent' => 'font-semibold text-red-500',
            default => 'text-zinc-500',
        };
    }

    /**
     * Formatea fechas de manera segura.
     */
    public function formatDate(mixed $date): string
    {
        if (blank($date)) {
            return 'Sin fecha';
        }

        try {
            return Carbon::parse($date)->format('d/m/Y H:i');
        } catch (\Throwable) {
            return 'Fecha no disponible';
        }
    }

    /**
     * Obtiene el nombre del edificio del elevador.
     *
     * Se contemplan ambos nombres de campo para mantener
     * compatibilidad con la estructura existente.
     */
    public function elevatorBuilding(ServiceOrder $order): string
    {
        return $order->elevator?->building_name
            ?? $order->elevator?->building
            ?? 'Edificio no registrado';
    }
};

?>

<div
    wire:poll.5s.visible
    class="flex h-full w-full flex-1 flex-col gap-6 rounded-xl"
>

    {{-- Encabezado principal --}}
    <section class="flex flex-col justify-between gap-4 sm:flex-row sm:items-center">
        <div>
            <h1 class="text-3xl font-bold text-zinc-900 dark:text-white">
                Dashboard del técnico
            </h1>

            <p class="mt-1 text-zinc-600 dark:text-zinc-400">
                Bienvenido, {{ auth()->user()->name }}.
                Consulta y atiende los servicios que tienes asignados.
            </p>
        </div>

        <a
            href="{{ route('my-service-orders.index') }}"
            wire:navigate
            class="inline-flex items-center justify-center rounded-lg bg-blue-600 px-5 py-3 font-semibold text-white transition hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 dark:focus:ring-offset-zinc-900"
        >
            Ver todas mis órdenes
        </a>
    </section>

    {{-- Tarjetas de estadísticas --}}
    <section class="grid grid-cols-1 gap-5 sm:grid-cols-2 xl:grid-cols-4">

        {{-- Órdenes programadas --}}
        <article class="rounded-2xl border border-blue-800 bg-blue-950 p-6 shadow-sm">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <p class="text-base font-medium text-blue-300">
                        Órdenes programadas
                    </p>

                    <p class="mt-3 text-4xl font-bold text-white">
                        {{ $this->metrics['scheduled'] }}
                    </p>

                    <p class="mt-2 text-sm text-blue-300">
                        Servicios pendientes por iniciar
                    </p>
                </div>

                <div class="rounded-xl bg-blue-900 p-3 text-blue-200">
                    <flux:icon
                        name="calendar-days"
                        class="size-7"
                    />
                </div>
            </div>
        </article>

        {{-- Órdenes en proceso --}}
        <article class="rounded-2xl border border-amber-800 bg-amber-950 p-6 shadow-sm">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <p class="text-base font-medium text-amber-300">
                        En proceso
                    </p>

                    <p class="mt-3 text-4xl font-bold text-white">
                        {{ $this->metrics['in_progress'] }}
                    </p>

                    <p class="mt-2 text-sm text-amber-300">
                        Servicios actualmente abiertos
                    </p>
                </div>

                <div class="rounded-xl bg-amber-900 p-3 text-amber-200">
                    <flux:icon
                        name="wrench-screwdriver"
                        class="size-7"
                    />
                </div>
            </div>
        </article>

        {{-- Órdenes completadas --}}
        <article class="rounded-2xl border border-green-800 bg-green-950 p-6 shadow-sm">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <p class="text-base font-medium text-green-300">
                        Órdenes completadas
                    </p>

                    <p class="mt-3 text-4xl font-bold text-white">
                        {{ $this->metrics['completed'] }}
                    </p>

                    <p class="mt-2 text-sm text-green-300">
                        Servicios finalizados
                    </p>
                </div>

                <div class="rounded-xl bg-green-900 p-3 text-green-200">
                    <flux:icon
                        name="check-circle"
                        class="size-7"
                    />
                </div>
            </div>
        </article>

        {{-- Total de órdenes --}}
        <article class="rounded-2xl border border-zinc-700 bg-zinc-900 p-6 shadow-sm">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <p class="text-base font-medium text-zinc-300">
                        Total asignadas
                    </p>

                    <p class="mt-3 text-4xl font-bold text-white">
                        {{ $this->metrics['total'] }}
                    </p>

                    <p class="mt-2 text-sm text-zinc-400">
                        Historial completo de órdenes
                    </p>
                </div>

                <div class="rounded-xl bg-zinc-800 p-3 text-zinc-200">
                    <flux:icon
                        name="clipboard-document-list"
                        class="size-7"
                    />
                </div>
            </div>
        </article>
    </section>

    {{-- Paneles inferiores --}}
    <section class="grid grid-cols-1 gap-6 xl:grid-cols-5">

        {{-- Próximas órdenes --}}
        <article class="overflow-hidden rounded-2xl border border-zinc-700 bg-zinc-950 xl:col-span-3">
            <header class="flex items-center justify-between border-b border-zinc-700 px-6 py-5">
                <div>
                    <h2 class="text-xl font-semibold text-white">
                        Próximas órdenes
                    </h2>

                    <p class="mt-1 text-sm text-zinc-400">
                        Servicios programados o actualmente en proceso.
                    </p>
                </div>

                <a
                    href="{{ route('my-service-orders.index') }}"
                    wire:navigate
                    class="font-semibold text-blue-500 hover:text-blue-400"
                >
                    Ver todas
                </a>
            </header>

            @if ($this->activeOrders->isEmpty())
                <div class="flex min-h-72 flex-col items-center justify-center px-6 py-12 text-center">
                    <div class="rounded-full bg-green-950 p-4 text-green-400">
                        <flux:icon
                            name="check-circle"
                            class="size-9"
                        />
                    </div>

                    <p class="mt-4 text-lg font-semibold text-green-400">
                        No tienes servicios pendientes
                    </p>

                    <p class="mt-1 text-zinc-500">
                        Tus órdenes programadas aparecerán en esta sección.
                    </p>
                </div>
            @else
                <div class="divide-y divide-zinc-800">
                    @foreach ($this->activeOrders as $order)
                        <div
                            wire:key="active-order-{{ $order->id }}"
                            class="grid gap-4 px-6 py-5 transition hover:bg-zinc-900 lg:grid-cols-[1fr_1fr_auto]"
                        >
                            {{-- Folio, estado y fecha --}}
                            <div>
                                <div class="flex flex-wrap items-center gap-3">
                                    <p class="font-semibold text-white">
                                        {{ $order->folio }}
                                    </p>

                                    <span
                                        class="inline-flex rounded-full px-3 py-1 text-xs font-semibold {{ $this->statusClasses($order->status) }}"
                                    >
                                        {{ $this->statusLabel($order->status) }}
                                    </span>
                                </div>

                                <p class="mt-2 text-sm text-zinc-400">
                                    {{ $this->formatDate($order->scheduled_at) }}
                                </p>
                            </div>

                            {{-- Elevador y tipo de servicio --}}
                            <div>
                                <p class="font-semibold text-white">
                                    {{ $order->elevator?->code ?? 'Sin elevador' }}
                                </p>

                                <p class="mt-1 text-sm text-zinc-400">
                                    {{ $this->elevatorBuilding($order) }}
                                </p>

                                <p class="mt-2 text-sm text-zinc-300">
                                    {{ $this->serviceTypeLabel($order->service_type) }}
                                </p>

                                <p class="text-sm {{ $this->priorityClasses($order->priority) }}">
                                    Prioridad:
                                    {{ $this->priorityLabel($order->priority) }}
                                </p>
                            </div>

                            {{-- Acción de la orden --}}
                            <div class="flex items-center lg:justify-end">
                                <a
                                    href="{{ route('my-service-orders.index') }}"
                                    wire:navigate
                                    class="inline-flex rounded-lg border border-blue-500 px-4 py-2 font-semibold text-blue-500 transition hover:bg-blue-600 hover:text-white"
                                >
                                    {{ $order->status === 'in_progress' ? 'Continuar' : 'Atender' }}
                                </a>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </article>

        {{-- Historial reciente --}}
        <article class="overflow-hidden rounded-2xl border border-zinc-700 bg-zinc-950 xl:col-span-2">
            <header class="border-b border-zinc-700 px-6 py-5">
                <h2 class="text-xl font-semibold text-white">
                    Servicios recientes
                </h2>

                <p class="mt-1 text-sm text-zinc-400">
                    Últimas órdenes que has completado.
                </p>
            </header>

            @if ($this->recentlyCompleted->isEmpty())
                <div class="flex min-h-72 flex-col items-center justify-center px-6 py-12 text-center">
                    <div class="rounded-full bg-zinc-900 p-4 text-zinc-400">
                        <flux:icon
                            name="clipboard-document-check"
                            class="size-9"
                        />
                    </div>

                    <p class="mt-4 font-semibold text-zinc-300">
                        Sin servicios completados
                    </p>

                    <p class="mt-1 text-sm text-zinc-500">
                        Aquí aparecerá tu historial reciente.
                    </p>
                </div>
            @else
                <div class="divide-y divide-zinc-800">
                    @foreach ($this->recentlyCompleted as $order)
                        <div
                            wire:key="completed-order-{{ $order->id }}"
                            class="px-6 py-5 transition hover:bg-zinc-900"
                        >
                            <div class="flex items-start justify-between gap-4">
                                <div>
                                    <p class="font-semibold text-white">
                                        {{ $order->folio }}
                                    </p>

                                    <p class="mt-1 text-sm text-zinc-400">
                                        {{ $order->elevator?->code ?? 'Sin elevador' }}
                                        ·
                                        {{ $this->serviceTypeLabel($order->service_type) }}
                                    </p>

                                    <p class="mt-2 text-xs text-zinc-500">
                                        Completada:

                                        {{
                                            $this->formatDate(
                                                $order->completed_at
                                                    ?? $order->updated_at
                                            )
                                        }}
                                    </p>
                                </div>

                                <span class="inline-flex rounded-full bg-green-100 px-3 py-1 text-xs font-semibold text-green-700 dark:bg-green-950 dark:text-green-300">
                                    Completada
                                </span>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </article>
    </section>
</div>