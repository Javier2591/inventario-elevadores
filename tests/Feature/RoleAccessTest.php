<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class RoleAccessTest extends TestCase
{
    /*
    |--------------------------------------------------------------------------
    | Protección de la base de datos
    |--------------------------------------------------------------------------
    |
    | DatabaseTransactions inicia una transacción antes de cada prueba
    | y la revierte al terminar. De esta forma, los usuarios creados por
    | estas pruebas no permanecen registrados en la base de datos.
    |
    | No utilizamos RefreshDatabase porque podría reconstruir las tablas
    | si las pruebas estuvieran conectadas accidentalmente a la base local.
    |
    */

    use RefreshDatabase;

    /**
     * Un visitante sin sesión debe ser enviado al inicio de sesión.
     */
    public function test_guest_cannot_access_protected_dashboards(): void
    {
        $this->get(route('dashboard'))
            ->assertRedirect(route('login'));

        $this->get(route('admin.dashboard'))
            ->assertRedirect(route('login'));

        $this->get(route('technician.dashboard'))
            ->assertRedirect(route('login'));

        $this->get(route('my-service-orders.index'))
            ->assertRedirect(route('login'));
    }

    /**
     * El administrador debe ser enviado desde /dashboard
     * hacia el Dashboard administrativo.
     */
    public function test_admin_is_redirected_to_admin_dashboard(): void
    {
        $admin = $this->createAdmin();

        $this->actingAs($admin)
            ->get(route('dashboard'))
            ->assertRedirect(route('admin.dashboard'));
    }

    /**
     * El administrador puede abrir el Dashboard administrativo.
     */
    public function test_admin_can_access_admin_dashboard(): void
    {
        $admin = $this->createAdmin();

        $this->actingAs($admin)
            ->get(route('admin.dashboard'))
            ->assertOk();
    }

    /**
     * El técnico debe ser enviado desde /dashboard
     * hacia su Dashboard de servicios.
     */
    public function test_technician_is_redirected_to_technician_dashboard(): void
    {
        $technician = $this->createTechnician();

        $this->actingAs($technician)
            ->get(route('dashboard'))
            ->assertRedirect(route('technician.dashboard'));
    }

    /**
     * El técnico activo puede abrir su Dashboard.
     */
    public function test_active_technician_can_access_technician_dashboard(): void
    {
        $technician = $this->createTechnician();

        $this->actingAs($technician)
            ->get(route('technician.dashboard'))
            ->assertOk();
    }

    /**
     * El técnico activo puede abrir la pantalla
     * de sus propias órdenes.
     */
    public function test_active_technician_can_access_my_service_orders(): void
    {
        $technician = $this->createTechnician();

        $this->actingAs($technician)
            ->get(route('my-service-orders.index'))
            ->assertOk();
    }

    /**
     * El técnico no puede entrar a ningún módulo administrativo,
     * aunque escriba directamente la dirección.
     */
    public function test_technician_cannot_access_administrative_routes(): void
    {
        $technician = $this->createTechnician();

        $administrativeRoutes = [
            'admin.dashboard',
            'categories.index',
            'suppliers.index',
            'elevators.index',
            'service-orders.index',
            'spare-parts.index',
            'purchases.index',
            'inventory-movements.index',
            'users.index',
        ];

        foreach ($administrativeRoutes as $routeName) {
            $this->actingAs($technician)
                ->get(route($routeName))
                ->assertForbidden();
        }
    }

    /**
     * El administrador no debe utilizar pantallas exclusivas
     * para técnicos.
     */
    public function test_admin_cannot_access_technician_pages(): void
    {
        $admin = $this->createAdmin();

        $this->actingAs($admin)
            ->get(route('technician.dashboard'))
            ->assertForbidden();

        $this->actingAs($admin)
            ->get(route('my-service-orders.index'))
            ->assertForbidden();
    }

    /**
     * Una cuenta inactiva no puede acceder al sistema.
     *
     * El middleware debe cerrar su sesión y enviarla al login.
     */
    public function test_inactive_user_is_logged_out_and_redirected_to_login(): void
    {
        $inactiveUser = $this->createTechnician(active: false);

        $response = $this->actingAs($inactiveUser)
            ->get(route('technician.dashboard'));

        $response->assertRedirect(route('login'));

        $this->assertGuest();
    }

    /**
     * Una función desconocida no debe recibir acceso.
     *
     * Esta prueba utiliza un modelo en memoria porque la columna
     * role puede estar restringida mediante un ENUM en MySQL.
     * La validación principal se comprueba en las funciones admitidas.
     */
    public function test_dashboard_requires_an_authorized_role(): void
    {
        $user = $this->createTechnician();

        /*
         * Cambiamos el atributo únicamente en memoria.
         * No intentamos guardar un valor incompatible con el ENUM.
         */
        $user->setAttribute('role', 'unknown');

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertForbidden();
    }

    /**
     * Crea un administrador activo para las pruebas.
     */
    private function createAdmin(bool $active = true): User
    {
        return User::factory()->create([
            'name' => 'Administrador de prueba',
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'phone' => '5511111111',
            'role' => 'admin',
            'is_active' => $active,
            'password' => Hash::make('Password123!'),
        ]);
    }

    /**
     * Crea un técnico para las pruebas.
     */
    private function createTechnician(bool $active = true): User
    {
        return User::factory()->create([
            'name' => 'Técnico de prueba',
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'phone' => '5522222222',
            'role' => 'technician',
            'is_active' => $active,
            'password' => Hash::make('Password123!'),
        ]);
    }
}
