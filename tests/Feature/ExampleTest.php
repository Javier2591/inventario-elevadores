<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * La página inicial debe redirigir al dashboard.
     */
    public function test_home_redirects_to_dashboard(): void
    {
        $response = $this->get(route('home'));

        $response->assertRedirect(route('dashboard'));
    }
}