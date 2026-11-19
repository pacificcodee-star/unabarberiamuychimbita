<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_landing_page_is_public_and_shows_the_business(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('Barbería Chimbita')
            ->assertSee('Reservar cita o turno');
    }

    public function test_the_about_page_shows_the_mission_and_vision(): void
    {
        $this->get('/nosotros')
            ->assertOk()
            ->assertSee('Misión')
            ->assertSee('Visión');
    }

    public function test_the_public_pages_are_available(): void
    {
        foreach (['/servicios', '/barberos', '/resenas', '/contacto', '/reservar'] as $path) {
            $this->get($path)->assertOk();
        }
    }

    public function test_the_login_page_is_available(): void
    {
        $this->get('/login')->assertOk();
    }

    public function test_the_dashboard_requires_authentication(): void
    {
        $this->get('/dashboard')->assertRedirect(route('login'));
    }
}
