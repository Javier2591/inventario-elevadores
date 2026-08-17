<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class SecurityTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Comprueba que la página de seguridad pueda mostrarse
     * cuando la contraseña ya fue confirmada.
     */
    public function test_security_settings_page_can_be_rendered(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->withSession([
                'auth.password_confirmed_at' => time(),
            ])
            ->get(route('security.edit'));

        $response->assertOk();
    }

    /**
     * Comprueba que se solicite la contraseña antes de
     * entrar a la configuración de seguridad.
     */
    public function test_security_settings_page_requires_password_confirmation_when_enabled(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->get(route('security.edit'));

        $response->assertRedirect(
            route('password.confirm')
        );
    }

    /**
     * Comprueba que la pantalla funcione cuando las
     * características de Fortify están deshabilitadas.
     */
    public function test_security_settings_page_renders_without_two_factor_when_feature_is_disabled(): void
    {
        config([
            'fortify.features' => [],
        ]);

        $user = User::factory()->create();

        $this->actingAs($user)
            ->withSession([
                'auth.password_confirmed_at' => time(),
            ])
            ->get(route('security.edit'))
            ->assertOk()
            ->assertSee('Update password')
            ->assertDontSee(
                'Manage your passkeys for passwordless sign-in'
            )
            ->assertDontSee(
                'Add a passkey to sign in without a password'
            )
            ->assertDontSee(
                'Two-factor authentication'
            );
    }

    /**
     * Comprueba que la contraseña pueda actualizarse
     * cuando la contraseña actual es correcta.
     */
    public function test_password_can_be_updated(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('password'),
        ]);

        $this->actingAs($user);

        $component = Livewire::test(
            'pages::settings.security'
        )
            ->set('current_password', 'password')
            ->set('password', 'new-password')
            ->set(
                'password_confirmation',
                'new-password'
            )
            ->call('updatePassword');

        $component->assertHasNoErrors();

        $this->assertTrue(
            Hash::check(
                'new-password',
                $user->refresh()->password
            )
        );
    }

    /**
     * Comprueba que una contraseña actual incorrecta
     * impida cambiar la contraseña del usuario.
     */
    public function test_correct_password_must_be_provided_to_update_password(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('password'),
        ]);

        $this->actingAs($user);

        $component = Livewire::test(
            'pages::settings.security'
        )
            ->set(
                'current_password',
                'wrong-password'
            )
            ->set(
                'password',
                'new-password'
            )
            ->set(
                'password_confirmation',
                'new-password'
            )
            ->call('updatePassword');

        $component->assertHasErrors([
            'current_password',
        ]);

        /*
         * También comprobamos que la contraseña original
         * permanezca sin modificaciones.
         */
        $this->assertTrue(
            Hash::check(
                'password',
                $user->refresh()->password
            )
        );

        $this->assertFalse(
            Hash::check(
                'new-password',
                $user->password
            )
        );
    }
}