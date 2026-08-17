<?php

use App\Models\InventoryMovement;
use App\Models\SparePart;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    /**
     * Indicadores generales del inventario.
     */
    #[Computed]
    public function metrics(): array
    {
        $inventoryValue = SparePart::query()
            ->active()
            ->selectRaw('COALESCE(SUM(stock * cost), 0) AS total')
            ->value('total');

        return [
            'total_parts' => SparePart::query()
                ->active()
                ->count(),

            'total_units' => (int) SparePart::query()
                ->active()
                ->sum('stock'),

            'low_stock' => SparePart::query()
                ->active()
                ->lowStock()
                ->count(),

            'inventory_value' => (float) $inventoryValue,
        ];
    }

    /**
     * Refacciones que necesitan reposición.
     */
    #[Computed]
    public function lowStockParts()
    {
        return SparePart::query()
            ->with('category:id,name')
            ->active()
            ->lowStock()
            ->orderBy('stock')
            ->limit(6)
            ->get([
                'id',
                'category_id',
                'sku',
                'name',
                'stock',
                'minimum_stock',
            ]);
    }

    /**
     * Últimos movimientos registrados.
     */
    #[Computed]
    public function recentMovements()
    {
        return InventoryMovement::query()
            ->with([
                'sparePart:id,sku,name',
                'user:id,name',
            ])
            ->latest('id')
            ->limit(8)
            ->get();
    }
};

?>

<div class="mx-auto w-full max-w-7xl space-y-6 p-4 sm:p-6">
    @php
        $metrics = $this->metrics;
    @endphp

    {{-- Encabezado principal --}}
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="text-2xl font-bold text-zinc-900 dark:text-white">
                Dashboard
            </h1>

            <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">
                Resumen general del inventario de elevadores.
            </p>
        </div>

        <a
            href="{{ route('inventory-movements.index') }}"
            wire:navigate
            class="inline-flex items-center justify-center rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-blue-700"
        >
            Registrar movimiento
        </a>
    </div>

    {{-- Indicadores principales --}}
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        {{-- Refacciones registradas --}}
        <div class="rounded-xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <p class="text-sm font-medium text-zinc-500 dark:text-zinc-400">
                Refacciones activas
            </p>

            <p class="mt-3 text-3xl font-bold text-zinc-900 dark:text-white">
                {{ number_format($metrics['total_parts']) }}
            </p>

            <p class="mt-2 text-xs text-zinc-500">
                Diferentes piezas registradas
            </p>
        </div>

        {{-- Total de unidades --}}
        <div class="rounded-xl border border-blue-200 bg-blue-50 p-5 shadow-sm dark:border-blue-900 dark:bg-blue-950">
            <p class="text-sm font-medium text-blue-700 dark:text-blue-300">
                Unidades disponibles
            </p>

            <p class="mt-3 text-3xl font-bold text-blue-900 dark:text-blue-100">
                {{ number_format($metrics['total_units']) }}
            </p>

            <p class="mt-2 text-xs text-blue-600 dark:text-blue-400">
                Existencia total del almacén
            </p>
        </div>

        {{-- Existencias bajas --}}
        <div class="rounded-xl border border-red-200 bg-red-50 p-5 shadow-sm dark:border-red-900 dark:bg-red-950">
            <p class="text-sm font-medium text-red-700 dark:text-red-300">
                Existencias bajas
            </p>

            <p class="mt-3 text-3xl font-bold text-red-900 dark:text-red-100">
                {{ number_format($metrics['low_stock']) }}
            </p>

            <p class="mt-2 text-xs text-red-600 dark:text-red-400">
                Refacciones que requieren atención
            </p>
        </div>

        {{-- Valor del inventario --}}
        <div class="rounded-xl border border-green-200 bg-green-50 p-5 shadow-sm dark:border-green-900 dark:bg-green-950">
            <p class="text-sm font-medium text-green-700 dark:text-green-300">
                Valor del inventario
            </p>

            <p class="mt-3 text-3xl font-bold text-green-900 dark:text-green-100">
                ${{ number_format($metrics['inventory_value'], 2) }}
            </p>

            <p class="mt-2 text-xs text-green-600 dark:text-green-400">
                Costo aproximado en MXN
            </p>
        </div>
    </div>

    <div class="grid gap-6 xl:grid-cols-5">
        {{-- Refacciones con existencia baja --}}
        <section class="overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-900 xl:col-span-2">
            <div class="flex items-center justify-between border-b border-zinc-200 p-5 dark:border-zinc-700">
                <div>
                    <h2 class="font-semibold text-zinc-900 dark:text-white">
                        Existencias bajas
                    </h2>

                    <p class="mt-1 text-xs text-zinc-500">
                        Piezas que necesitan reposición
                    </p>
                </div>

                <a
                    href="{{ route('spare-parts.index') }}"
                    wire:navigate
                    class="text-sm font-semibold text-blue-600 hover:text-blue-700"
                >
                    Ver refacciones
                </a>
            </div>

            <div class="divide-y divide-zinc-200 dark:divide-zinc-700">
                @forelse ($this->lowStockParts as $sparePart)
                    <div
                        wire:key="low-stock-{{ $sparePart->id }}"
                        class="flex items-center justify-between gap-4 p-4"
                    >
                        <div class="min-w-0">
                            <p class="truncate font-medium text-zinc-900 dark:text-white">
                                {{ $sparePart->name }}
                            </p>

                            <p class="mt-1 text-xs text-zinc-500">
                                {{ $sparePart->sku }}

                                @if ($sparePart->category)
                                    · {{ $sparePart->category->name }}
                                @endif
                            </p>
                        </div>

                        <div class="shrink-0 text-right">
                            <p class="font-bold text-red-600">
                                {{ $sparePart->stock }}
                            </p>

                            <p class="text-xs text-zinc-500">
                                Mínimo: {{ $sparePart->minimum_stock }}
                            </p>
                        </div>
                    </div>
                @empty
                    <div class="p-8 text-center">
                        <p class="font-medium text-green-600 dark:text-green-400">
                            Inventario en buen estado
                        </p>

                        <p class="mt-1 text-sm text-zinc-500">
                            No hay refacciones con existencia baja.
                        </p>
                    </div>
                @endforelse
            </div>
        </section>

        {{-- Movimientos recientes --}}
        <section class="overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-900 xl:col-span-3">
            <div class="flex items-center justify-between border-b border-zinc-200 p-5 dark:border-zinc-700">
                <div>
                    <h2 class="font-semibold text-zinc-900 dark:text-white">
                        Movimientos recientes
                    </h2>

                    <p class="mt-1 text-xs text-zinc-500">
                        Últimas entradas y salidas registradas
                    </p>
                </div>

                <a
                    href="{{ route('inventory-movements.index') }}"
                    wire:navigate
                    class="text-sm font-semibold text-blue-600 hover:text-blue-700"
                >
                    Ver historial
                </a>
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-700">
                    <thead class="bg-zinc-50 dark:bg-zinc-800">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase text-zinc-600 dark:text-zinc-300">
                                Refacción
                            </th>

                            <th class="px-4 py-3 text-center text-xs font-semibold uppercase text-zinc-600 dark:text-zinc-300">
                                Movimiento
                            </th>

                            <th class="px-4 py-3 text-center text-xs font-semibold uppercase text-zinc-600 dark:text-zinc-300">
                                Existencia
                            </th>

                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase text-zinc-600 dark:text-zinc-300">
                                Fecha
                            </th>
                        </tr>
                    </thead>

                    <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                        @forelse ($this->recentMovements as $movement)
                            <tr wire:key="dashboard-movement-{{ $movement->id }}">
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

                                    <p class="mt-1 text-sm font-bold {{ $movement->isEntry() ? 'text-green-600' : 'text-red-600' }}">
                                        {{ $movement->isEntry() ? '+' : '-' }}{{ $movement->quantity }}
                                    </p>
                                </td>

                                <td class="whitespace-nowrap px-4 py-4 text-center text-sm text-zinc-700 dark:text-zinc-300">
                                    {{ $movement->previous_stock }}
                                    <span class="mx-1 text-zinc-400">→</span>
                                    <strong>{{ $movement->new_stock }}</strong>
                                </td>

                                <td class="whitespace-nowrap px-4 py-4 text-sm text-zinc-600 dark:text-zinc-400">
                                    {{ $movement->created_at->format('d/m/Y') }}

                                    <p class="text-xs">
                                        {{ $movement->created_at->format('H:i') }}
                                    </p>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="px-4 py-10 text-center text-sm text-zinc-500">
                                    Todavía no hay movimientos registrados.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</div>