<?php

use App\Http\Controllers\SaleReceiptController;
use Illuminate\Support\Facades\Route;


/*
|--------------------------------------------------------------------------
| Página inicial
|--------------------------------------------------------------------------
|
| La raíz del sistema redirige al distribuidor principal.
| Si no existe una sesión, el middleware auth enviará al login.
|
*/

Route::redirect('/', '/dashboard')
    ->name('home');

/*
|--------------------------------------------------------------------------
| Rutas para usuarios autenticados y activos
|--------------------------------------------------------------------------
|
| Estas rutas pueden ser solicitadas por administradores y técnicos.
| Los componentes de técnico también validan internamente la función
| del usuario conectado.
|
*/

Route::middleware([
    'auth',
    'active',
])->group(function (): void {
    /*
     * Distribuidor principal del Dashboard.
     *
     * Envía a cada usuario a la pantalla correspondiente según
     * su función dentro del sistema.
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
     * Dashboard exclusivo del técnico.
     */
    Route::livewire(
        '/technician-dashboard',
        'technician-dashboard.index'
    )->name('technician.dashboard');

    /*
     * Órdenes asignadas al técnico conectado.
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
| Requieren:
|
| - Sesión iniciada.
| - Usuario activo.
| - Función de administrador.
|
| El middleware es la protección real. Ocultar los enlaces en el
| sidebar solamente mejora la interfaz, pero no sustituye la seguridad.
|
*/

Route::middleware([
    'auth',
    'active',
    'admin',
])->group(function (): void {
    /*
     * Dashboard administrativo.
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
     * Proveedores.
     */
    Route::livewire(
        '/suppliers',
        'suppliers.index'
    )->name('suppliers.index');

    /*
     * Clientes.
     */
    Route::livewire(
        '/customers',
        'customers.index'
    )->name('customers.index');

    /*
     * Ventas de refacciones.
     */
    Route::livewire(
        '/sales',
        'sales.index'
    )->name('sales.index');
    /*
 * Comprobante imprimible de una venta.
 */
    Route::get(
    '/sales/{sale}/receipt',
    SaleReceiptController::class
    )
    ->whereNumber('sale')
    ->name('sales.receipt');
    /*
     * Elevadores registrados.
     */
    Route::livewire(
        '/elevators',
        'elevators.index'
    )->name('elevators.index');

    /*
     * Órdenes de servicio.
     */
    Route::livewire(
        '/service-orders',
        'service-orders.index'
    )->name('service-orders.index');

    /*
     * Refacciones disponibles.
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
| Configuración de la cuenta
|--------------------------------------------------------------------------
|
| Incluye perfil, contraseña, apariencia y seguridad. Estas rutas
| pertenecen al Starter Kit y también son utilizadas por las pruebas.
|
*/

require __DIR__.'/settings.php';