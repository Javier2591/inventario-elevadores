<!DOCTYPE html>
<html
    lang="{{ str_replace('_', '-', app()->getLocale()) }}"
    class="dark"
>
    <head>
        @include('partials.head')
    </head>

    <body class="min-h-screen bg-white antialiased dark:bg-zinc-800">
        @php
            /*
            |--------------------------------------------------------------------------
            | Usuario autenticado y función
            |--------------------------------------------------------------------------
            |
            | La visualización del menú cambia según la función del usuario.
            | Los middleware continúan siendo la protección real de las rutas.
            |
            */

            $user = auth()->user();

            $isAdmin = $user?->role === 'admin';
            $isTechnician = $user?->role === 'technician';

            $userName = $user?->name ?? 'Usuario';
            $userEmail = $user?->email ?? '';
            $userInitials = $user?->initials() ?? 'U';

            $roleLabel = match ($user?->role) {
                'admin' => 'Administrador',
                'technician' => 'Técnico',
                default => 'Usuario',
            };

            /*
             * Determina las rutas de Dashboard disponibles.
             *
             * Los fallbacks evitan un RouteNotFoundException mientras
             * Laravel actualiza o limpia la caché de rutas.
             */
            $technicianDashboardRoute =
                \Illuminate\Support\Facades\Route::has('technician.dashboard')
                    ? 'technician.dashboard'
                    : 'dashboard';

            $adminDashboardRoute =
                \Illuminate\Support\Facades\Route::has('admin.dashboard')
                    ? 'admin.dashboard'
                    : 'dashboard';

            /*
             * Selecciona el Dashboard correspondiente al usuario.
             */
            $dashboardRoute = $isTechnician
                ? $technicianDashboardRoute
                : $adminDashboardRoute;
        @endphp

        <flux:sidebar
            sticky
            stashable
            class="border-e border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900"
        >
            {{-- Botón para cerrar el menú en dispositivos móviles --}}
            <flux:sidebar.toggle
                class="lg:hidden"
                icon="x-mark"
            />

            {{-- Logo y nombre de la aplicación --}}
            <a
                href="{{ route($dashboardRoute) }}"
                class="me-5 flex items-center space-x-2 rtl:space-x-reverse"
                wire:navigate
            >
                <x-app-logo />
            </a>

            {{-- Navegación principal --}}
            <flux:navlist variant="outline">
                <flux:navlist.group
                    :heading="__('Plataforma')"
                    class="grid"
                >
                    {{--
                        Dashboard dinámico:
                        - Administrador: Dashboard del inventario.
                        - Técnico: Dashboard de órdenes asignadas.
                    --}}
                    <flux:navlist.item
                        icon="home"
                        :href="route($dashboardRoute)"
                        :current="request()->routeIs($dashboardRoute)"
                        wire:navigate
                    >
                        Dashboard
                    </flux:navlist.item>

                    {{--
                        Menú exclusivo para técnicos.
                    --}}
                    @if ($isTechnician)
                        @if (\Illuminate\Support\Facades\Route::has('my-service-orders.index'))
                            <flux:navlist.item
                                icon="clipboard-document-list"
                                :href="route('my-service-orders.index')"
                                :current="request()->routeIs('my-service-orders.*')"
                                wire:navigate
                            >
                                Mis órdenes
                            </flux:navlist.item>
                        @endif
                    @endif

                    {{--
                        Menú exclusivo para administradores.
                    --}}
                    @if ($isAdmin)
                        {{-- Categorías --}}
                        @if (\Illuminate\Support\Facades\Route::has('categories.index'))
                            <flux:navlist.item
                                icon="tag"
                                :href="route('categories.index')"
                                :current="request()->routeIs('categories.*')"
                                wire:navigate
                            >
                                Categorías
                            </flux:navlist.item>
                        @endif

                        {{-- Proveedores --}}
                        @if (\Illuminate\Support\Facades\Route::has('suppliers.index'))
                            <flux:navlist.item
                                icon="truck"
                                :href="route('suppliers.index')"
                                :current="request()->routeIs('suppliers.*')"
                                wire:navigate
                            >
                                Proveedores
                            </flux:navlist.item>
                        @endif

                        {{-- Clientes --}}
                        @if (\Illuminate\Support\Facades\Route::has('customers.index'))
                            <flux:navlist.item
                                icon="users"
                                :href="route('customers.index')"
                                :current="request()->routeIs('customers.*')"
                                wire:navigate
                            >
                                Clientes
                            </flux:navlist.item>
                        @endif

                        {{-- Elevadores --}}
                        @if (\Illuminate\Support\Facades\Route::has('elevators.index'))
                            <flux:navlist.item
                                icon="building-office"
                                :href="route('elevators.index')"
                                :current="request()->routeIs('elevators.*')"
                                wire:navigate
                            >
                                Elevadores
                            </flux:navlist.item>
                        @endif

                        {{-- Órdenes de servicio --}}
                        @if (\Illuminate\Support\Facades\Route::has('service-orders.index'))
                            <flux:navlist.item
                                icon="clipboard-document-check"
                                :href="route('service-orders.index')"
                                :current="request()->routeIs('service-orders.*')"
                                wire:navigate
                            >
                                Órdenes de servicio
                            </flux:navlist.item>
                        @endif

                        {{-- Refacciones --}}
                        @if (\Illuminate\Support\Facades\Route::has('spare-parts.index'))
                            <flux:navlist.item
                                icon="wrench-screwdriver"
                                :href="route('spare-parts.index')"
                                :current="request()->routeIs('spare-parts.*')"
                                wire:navigate
                            >
                                Refacciones
                            </flux:navlist.item>
                        @endif

                        {{-- Compras --}}
                        @if (\Illuminate\Support\Facades\Route::has('purchases.index'))
                            <flux:navlist.item
                                icon="shopping-cart"
                                :href="route('purchases.index')"
                                :current="request()->routeIs('purchases.*')"
                                wire:navigate
                            >
                                Compras
                            </flux:navlist.item>
                        @endif

                        {{-- Movimientos --}}
                        @if (\Illuminate\Support\Facades\Route::has('inventory-movements.index'))
                            <flux:navlist.item
                                icon="arrows-right-left"
                                :href="route('inventory-movements.index')"
                                :current="request()->routeIs('inventory-movements.*')"
                                wire:navigate
                            >
                                Movimientos
                            </flux:navlist.item>
                        @endif

                        {{-- Usuarios y técnicos --}}
                        @if (\Illuminate\Support\Facades\Route::has('users.index'))
                            <flux:navlist.item
                                icon="users"
                                :href="route('users.index')"
                                :current="request()->routeIs('users.*')"
                                wire:navigate
                            >
                                Usuarios y técnicos
                            </flux:navlist.item>
                        @endif
                    @endif
                </flux:navlist.group>
            </flux:navlist>

            {{-- Empuja los elementos inferiores hacia abajo --}}
            <flux:spacer />

            {{--
                Enlaces técnicos del proyecto.
                Únicamente son visibles para administradores.
            --}}
            @if ($isAdmin)
                <flux:navlist variant="outline">
                    <flux:navlist.item
                        icon="folder-git-2"
                        href="https://github.com/Javier2591/inventario-elevadores"
                        target="_blank"
                    >
                        Repositorio
                    </flux:navlist.item>

                    <flux:navlist.item
                        icon="book-open-text"
                        href="https://laravel.com/docs"
                        target="_blank"
                    >
                        Documentación
                    </flux:navlist.item>
                </flux:navlist>
            @endif

            {{--
                Menú del usuario para computadoras.
            --}}
            <flux:dropdown
                class="hidden lg:block"
                position="bottom"
                align="start"
            >
                <flux:profile
                    :name="$userName"
                    :initials="$userInitials"
                    icon:trailing="chevrons-up-down"
                />

                <flux:menu class="w-[240px]">
                    {{-- Información del usuario autenticado --}}
                    <flux:menu.radio.group>
                        <div class="p-0 text-sm font-normal">
                            <div class="flex items-center gap-2 px-1 py-1.5 text-start text-sm">
                                <span class="relative flex size-8 shrink-0 overflow-hidden rounded-lg">
                                    <span
                                        class="flex size-full items-center justify-center rounded-lg bg-neutral-200 text-black dark:bg-neutral-700 dark:text-white"
                                    >
                                        {{ $userInitials }}
                                    </span>
                                </span>

                                <div class="grid min-w-0 flex-1 text-start text-sm leading-tight">
                                    <span class="truncate font-semibold">
                                        {{ $userName }}
                                    </span>

                                    <span class="truncate text-xs text-zinc-500">
                                        {{ $userEmail }}
                                    </span>

                                    <span class="mt-1 truncate text-xs font-medium text-blue-500">
                                        {{ $roleLabel }}
                                    </span>
                                </div>
                            </div>
                        </div>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    {{-- Cierre de sesión protegido mediante CSRF --}}
                    <form
                        method="POST"
                        action="{{ route('logout') }}"
                        class="w-full"
                    >
                        @csrf

                        <flux:menu.item
                            as="button"
                            type="submit"
                            icon="arrow-right-start-on-rectangle"
                            class="w-full"
                        >
                            Cerrar sesión
                        </flux:menu.item>
                    </form>
                </flux:menu>
            </flux:dropdown>
        </flux:sidebar>

        {{--
            Encabezado para teléfonos y tabletas.
        --}}
        <flux:header class="lg:hidden">
            {{-- Botón para abrir el sidebar --}}
            <flux:sidebar.toggle
                class="lg:hidden"
                icon="bars-2"
                inset="left"
            />

            <flux:spacer />

            <flux:dropdown
                position="top"
                align="end"
            >
                <flux:profile
                    :initials="$userInitials"
                    icon-trailing="chevron-down"
                />

                <flux:menu class="w-[240px]">
                    {{-- Información del usuario en dispositivos móviles --}}
                    <flux:menu.radio.group>
                        <div class="p-0 text-sm font-normal">
                            <div class="flex items-center gap-2 px-1 py-1.5 text-start text-sm">
                                <span class="relative flex size-8 shrink-0 overflow-hidden rounded-lg">
                                    <span
                                        class="flex size-full items-center justify-center rounded-lg bg-neutral-200 text-black dark:bg-neutral-700 dark:text-white"
                                    >
                                        {{ $userInitials }}
                                    </span>
                                </span>

                                <div class="grid min-w-0 flex-1 text-start text-sm leading-tight">
                                    <span class="truncate font-semibold">
                                        {{ $userName }}
                                    </span>

                                    <span class="truncate text-xs text-zinc-500">
                                        {{ $userEmail }}
                                    </span>

                                    <span class="mt-1 truncate text-xs font-medium text-blue-500">
                                        {{ $roleLabel }}
                                    </span>
                                </div>
                            </div>
                        </div>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    {{-- Cierre de sesión móvil --}}
                    <form
                        method="POST"
                        action="{{ route('logout') }}"
                        class="w-full"
                    >
                        @csrf

                        <flux:menu.item
                            as="button"
                            type="submit"
                            icon="arrow-right-start-on-rectangle"
                            class="w-full"
                        >
                            Cerrar sesión
                        </flux:menu.item>
                    </form>
                </flux:menu>
            </flux:dropdown>
        </flux:header>

        {{--
            Contenido principal de la página actual.
        --}}
        {{ $slot }}

        @fluxScripts
    </body>
</html>