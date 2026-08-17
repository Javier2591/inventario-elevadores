<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    /**
     * Prepara un administrador autenticado antes de cada prueba.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'name' => 'Administrador principal',
            'email' => 'administrador@example.com',
            'phone' => '5511111111',
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
            'password' => Hash::make(
                'AdminPassword123!'
            ),
        ]);

        $this->actingAs($this->admin);
    }

    /**
     * Comprueba que un administrador pueda crear
     * una cuenta de técnico activa.
     */
    public function test_admin_can_create_active_technician(): void
    {
        $component = Livewire::test('users.index')
            ->set(
                'name',
                '  Mario   Rodríguez  '
            )
            ->set(
                'email',
                'MARIO.RODRIGUEZ@EXAMPLE.COM'
            )
            ->set(
                'phone',
                ' 55 2222 3333 '
            )
            ->set(
                'role',
                User::ROLE_TECHNICIAN
            )
            ->set(
                'is_active',
                true
            )
            ->set(
                'password',
                'Password123!'
            )
            ->set(
                'password_confirmation',
                'Password123!'
            )
            ->call('save');

        $component->assertHasNoErrors();

        $technician = User::query()
            ->where(
                'email',
                'mario.rodriguez@example.com'
            )
            ->firstOrFail();

        /*
         * Los textos deben quedar normalizados.
         */
        $this->assertSame(
            'Mario Rodríguez',
            $technician->name
        );

        $this->assertSame(
            'mario.rodriguez@example.com',
            $technician->email
        );

        $this->assertSame(
            '55 2222 3333',
            $technician->phone
        );

        $this->assertSame(
            User::ROLE_TECHNICIAN,
            $technician->role
        );

        $this->assertTrue(
            $technician->is_active
        );

        /*
         * La contraseña debe estar cifrada.
         */
        $this->assertNotSame(
            'Password123!',
            $technician->password
        );

        $this->assertTrue(
            Hash::check(
                'Password123!',
                $technician->password
            )
        );
    }

    /**
     * Comprueba que no puedan registrarse usuarios
     * con correos electrónicos repetidos.
     */
    public function test_duplicate_email_is_rejected(): void
    {
        $existingUser = $this->createTechnician([
            'email' => 'tecnico@example.com',
        ]);

        $initialUserCount = User::query()->count();

        $component = Livewire::test('users.index')
            ->set(
                'name',
                'Otro técnico'
            )
            ->set(
                'email',
                'TECNICO@EXAMPLE.COM'
            )
            ->set(
                'phone',
                '5533334444'
            )
            ->set(
                'role',
                User::ROLE_TECHNICIAN
            )
            ->set(
                'is_active',
                true
            )
            ->set(
                'password',
                'Password123!'
            )
            ->set(
                'password_confirmation',
                'Password123!'
            )
            ->call('save');

        $component->assertHasErrors([
            'email',
        ]);

        /*
         * No debe crearse un usuario adicional.
         */
        $this->assertSame(
            $initialUserCount,
            User::query()->count()
        );

        $this->assertSame(
            'tecnico@example.com',
            $existingUser->fresh()->email
        );
    }

    /**
     * Comprueba que dejar la contraseña vacía al editar
     * conserve la contraseña actual.
     */
    public function test_editing_user_without_password_preserves_current_password(): void
    {
        $technician = $this->createTechnician([
            'name' => 'Técnico original',
            'email' => 'original@example.com',
            'phone' => '5544445555',
            'password' => Hash::make(
                'OriginalPassword123!'
            ),
        ]);

        $originalPasswordHash =
            $technician->password;

        $component = Livewire::test('users.index')
            ->call(
                'edit',
                $technician->id
            )
            ->assertSet(
                'editingUserId',
                $technician->id
            )
            ->set(
                'name',
                '  Técnico   actualizado  '
            )
            ->set(
                'email',
                'ACTUALIZADO@EXAMPLE.COM'
            )
            ->set(
                'phone',
                ''
            )
            ->set(
                'password',
                ''
            )
            ->set(
                'password_confirmation',
                ''
            )
            ->call('save');

        $component->assertHasNoErrors();

        $updatedTechnician =
            $technician->fresh();

        $this->assertSame(
            'Técnico actualizado',
            $updatedTechnician->name
        );

        $this->assertSame(
            'actualizado@example.com',
            $updatedTechnician->email
        );

        $this->assertNull(
            $updatedTechnician->phone
        );

        /*
         * El hash debe conservarse sin modificaciones.
         */
        $this->assertSame(
            $originalPasswordHash,
            $updatedTechnician->password
        );

        $this->assertTrue(
            Hash::check(
                'OriginalPassword123!',
                $updatedTechnician->password
            )
        );
    }

    /**
     * Comprueba que el administrador pueda cambiar
     * la contraseña de otro usuario.
     */
    public function test_admin_can_update_another_users_password(): void
    {
        $technician = $this->createTechnician([
            'password' => Hash::make(
                'OldPassword123!'
            ),
        ]);

        $component = Livewire::test('users.index')
            ->call(
                'edit',
                $technician->id
            )
            ->set(
                'password',
                'NewPassword123!'
            )
            ->set(
                'password_confirmation',
                'NewPassword123!'
            )
            ->call('save');

        $component->assertHasNoErrors();

        $updatedTechnician =
            $technician->fresh();

        $this->assertTrue(
            Hash::check(
                'NewPassword123!',
                $updatedTechnician->password
            )
        );

        $this->assertFalse(
            Hash::check(
                'OldPassword123!',
                $updatedTechnician->password
            )
        );
    }

    /**
     * Comprueba que el administrador autenticado no pueda
     * quitarse su propia función administrativa.
     */
    public function test_admin_cannot_remove_own_administrative_role(): void
    {
        $component = Livewire::test('users.index')
            ->call(
                'edit',
                $this->admin->id
            )
            ->set(
                'role',
                User::ROLE_TECHNICIAN
            )
            ->call('save');

        $component->assertHasErrors([
            'role',
        ]);

        $updatedAdmin =
            $this->admin->fresh();

        $this->assertSame(
            User::ROLE_ADMIN,
            $updatedAdmin->role
        );

        $this->assertTrue(
            $updatedAdmin->is_active
        );
    }

    /**
     * Comprueba que el administrador autenticado no pueda
     * desactivar su cuenta durante la edición.
     */
    public function test_admin_cannot_deactivate_own_account_during_edit(): void
    {
        $component = Livewire::test('users.index')
            ->call(
                'edit',
                $this->admin->id
            )
            ->set(
                'is_active',
                false
            )
            ->call('save');

        /*
         * La protección del componente coloca el error
         * en la clave role.
         */
        $component->assertHasErrors([
            'role',
        ]);

        $updatedAdmin =
            $this->admin->fresh();

        $this->assertTrue(
            $updatedAdmin->is_active
        );

        $this->assertSame(
            User::ROLE_ADMIN,
            $updatedAdmin->role
        );
    }

    /**
     * Comprueba que un administrador pueda bloquear
     * y reactivar la cuenta de otro usuario.
     */
    public function test_admin_can_toggle_another_users_status(): void
    {
        $technician = $this->createTechnician([
            'is_active' => true,
        ]);

        /*
         * Primer cambio: cuenta activa a bloqueada.
         */
        $component = Livewire::test('users.index')
            ->call(
                'toggleStatus',
                $technician->id
            );

        $component->assertHasNoErrors();

        $this->assertFalse(
            $technician->fresh()->is_active
        );

        /*
         * Segundo cambio: cuenta bloqueada a activa.
         */
        $component = Livewire::test('users.index')
            ->call(
                'toggleStatus',
                $technician->id
            );

        $component->assertHasNoErrors();

        $this->assertTrue(
            $technician->fresh()->is_active
        );
    }

    /**
     * Comprueba que el administrador no pueda bloquear
     * su propia cuenta mediante la acción rápida.
     */
    public function test_admin_cannot_toggle_own_status(): void
    {
        $component = Livewire::test('users.index')
            ->call(
                'toggleStatus',
                $this->admin->id
            );

        $component->assertHasErrors([
            'user_action',
        ]);

        $this->assertTrue(
            $this->admin->fresh()->is_active
        );
    }

    /**
     * Comprueba que un técnico no pueda acceder
     * al componente administrativo de usuarios.
     */
    public function test_technician_cannot_access_user_management_component(): void
    {
        $technician =
            $this->createTechnician();

        $this->actingAs($technician);

        /*
         * Livewire captura abort(403) y lo convierte
         * en una respuesta HTTP con estado 403.
         */
        Livewire::test('users.index')
            ->assertStatus(403);
    }

    /**
     * Crea un técnico con valores válidos.
     *
     * @param array<string, mixed> $attributes
     */
    private function createTechnician(
        array $attributes = []
    ): User {
        return User::factory()->create(
            array_merge([
                'name' => 'Técnico de prueba',
                'email' => fake()
                    ->unique()
                    ->safeEmail(),
                'phone' => '5555555555',
                'role' => User::ROLE_TECHNICIAN,
                'is_active' => true,
                'password' => Hash::make(
                    'Password123!'
                ),
            ], $attributes)
        );
    }
}