@props([
    'version' => '1.0.0',
])

<footer
    {{ $attributes->class([
        'mt-12 border-t border-zinc-700/70 pt-6',
    ]) }}
>
    <div
        class="flex flex-col gap-4 pb-2 text-sm text-zinc-500
               sm:flex-row sm:items-center sm:justify-between"
    >
        {{-- Identidad del sistema --}}
        <div class="flex items-center gap-3">
            <div
                class="flex size-10 shrink-0 items-center justify-center
                       overflow-hidden rounded-lg bg-white
                       ring-1 ring-zinc-700"
            >
                <img
                    src="{{ asset('images/logo-empresa.jpeg') }}"
                    alt="Logotipo de la empresa"
                    class="size-full object-contain"
                    loading="lazy"
                >
            </div>

            <div>
                <p class="font-medium text-zinc-200">
                    Inventario de Elevadores
                </p>

                <p class="text-xs text-zinc-500">
                    © {{ now()->year }} Todos los derechos reservados.
                </p>
            </div>
        </div>

        {{-- Información técnica no sensible --}}
        <div
            class="flex flex-wrap items-center gap-x-3 gap-y-1
                   text-xs text-zinc-500"
        >
            <span>Sistema interno de gestión</span>

            <span
                aria-hidden="true"
                class="hidden text-zinc-700 sm:inline"
            >
                •
            </span>

            <span>
                Versión {{ $version }}
            </span>
        </div>
    </div>
</footer>