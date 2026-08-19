<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Página inicial
|--------------------------------------------------------------------------
|
| Todos los usuarios son enviados a /dashboard.
| Si no han iniciado sesión, el middleware auth los enviará al login.
|
*/

Route::redirect('/', '/dashboard')
    ->name('home');

/*
|--------------------------------------------------------------------------
| Rutas para cualquier usuario autenticado y activo
|--------------------------------------------------------------------------
|
| Estas rutas son compartidas por administradores y técnicos.
| El middleware active bloquea las cuentas desactivadas.
|
*/

Route::middleware([
    'auth',
    'active',
])->group(function (): void {
    /*
     * Distribuidor de Dashboard.
     *
     * Después del inicio de sesión, Laravel envía al usuario a
     * la pantalla correspondiente según su función.
     */
    Route::get('/dashboard', function () {
        $user = request()->user();

        abort_unless(
            $user !== null,
            401,
            'Debes iniciar sesión.'
        );

        if ($user->role === 'admin') {
            return redirect()->route('admin.dashboard');
        }

        if ($user->role === 'technician') {
            return redirect()->route('technician.dashboard');
        }

        abort(
            403,
            'Tu cuenta no tiene una función autorizada.'
        );
    })->name('dashboard');

    /*
     * Dashboard del técnico.
     *
     * El componente también verifica que el usuario sea técnico
     * y que su cuenta continúe activa.
     */
    Route::livewire(
        '/technician-dashboard',
        'technician-dashboard.index'
    )->name('technician.dashboard');

    /*
     * Órdenes asignadas al técnico autenticado.
     *
     * El componente filtra las órdenes mediante technician_id.
     */
    Route::livewire(
        '/my-service-orders',
        'my-service-orders.index'
    )->name('my-service-orders.index');
});

/*
|--------------------------------------------------------------------------
| Rutas administrativas
|--------------------------------------------------------------------------
|
| Todas estas rutas requieren:
|
| - Sesión iniciada.
| - Cuenta activa.
| - Función de administrador.
|
| El middleware admin es la protección real. Ocultar enlaces en el
| menú solamente mejora la interfaz, pero no sustituye la seguridad.
|
*/

Route::middleware([
    'auth',
    'active',
    'admin',
])->group(function (): void {
    /*
     * Dashboard administrativo del inventario.
     */
    Route::livewire(
        '/admin-dashboard',
        'dashboard.index'
    )->name('admin.dashboard');

    /*
     * Categorías de refacciones.
     */
    Route::livewire(
        '/categories',
        'categories.index'
    )->name('categories.index');

    /*
     * Proveedores del inventario.
     */
    Route::livewire(
        '/suppliers',
        'suppliers.index'
    )->name('suppliers.index');

    /*
     * Clientes utilizados en las ventas de refacciones.
     */
    Route::livewire(
        '/customers',
        'customers.index'
    )->name('customers.index');

    /*
     * Elevadores registrados.
     */
    Route::livewire(
        '/elevators',
        'elevators.index'
    )->name('elevators.index');

    /*
     * Órdenes de servicio administradas.
     */
    Route::livewire(
        '/service-orders',
        'service-orders.index'
    )->name('service-orders.index');

    /*
     * Refacciones disponibles en el inventario.
     */
    Route::livewire(
        '/spare-parts',
        'spare-parts.index'
    )->name('spare-parts.index');

    /*
     * Compras realizadas a proveedores.
     */
    Route::livewire(
        '/purchases',
        'purchases.index'
    )->name('purchases.index');

    /*
     * Entradas y salidas del inventario.
     */
    Route::livewire(
        '/inventory-movements',
        'inventory-movements.index'
    )->name('inventory-movements.index');

    /*
     * Administración de usuarios y técnicos.
     */
    Route::livewire(
        '/users',
        'users.index'
    )->name('users.index');
});

/*
|--------------------------------------------------------------------------
| Rutas de configuración de la cuenta
|--------------------------------------------------------------------------
|
| Incluye perfil, contraseña y apariencia. Estas rutas pertenecen al
| Starter Kit y son utilizadas por las pruebas de configuración.
|
*/

require __DIR__.'/settings.php';