<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $this->get(route('dashboard'))
            ->assertRedirect(route('login'));
    }

    public function test_admin_is_redirected_to_the_admin_dashboard(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_ADMIN,
            'is_active' => true,
        ]);

        $this->actingAs($admin)
            ->get(route('dashboard'))
            ->assertRedirect(route('admin.dashboard'));
    }

    public function test_technician_is_redirected_to_the_technician_dashboard(): void
    {
        $technician = User::factory()->create([
            'role' => User::ROLE_TECHNICIAN,
            'is_active' => true,
        ]);

        $this->actingAs($technician)
            ->get(route('dashboard'))
            ->assertRedirect(route('technician.dashboard'));
    }
}