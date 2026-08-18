<x-layouts::app.sidebar :title="$title ?? null">
    <flux:main>
        {{-- Contenido principal de cada pantalla --}}
        {{ $slot }}

        {{-- Footer general de la aplicación --}}
        <x-app-footer version="1.0.0" />
    </flux:main>
</x-layouts::app.sidebar>