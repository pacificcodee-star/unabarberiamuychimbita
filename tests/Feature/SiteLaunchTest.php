<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Turn;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Lista de lanzamiento del sitio público: legal, SEO, HTTPS, 404, anti-spam y analítica. */
class SiteLaunchTest extends TestCase
{
    use RefreshDatabase;

    public function test_legal_pages_exist_and_are_linked_from_every_page(): void
    {
        $this->get(route('legal.notice'))->assertOk()->assertSee('Aviso legal y condiciones de uso');
        $this->get(route('legal.privacy'))->assertOk()->assertSee('Ley 1581 de 2012')->assertSee('Superintendencia de Industria y Comercio');
        $this->get(route('legal.cookies'))->assertOk()->assertSee('Cookies necesarias');

        $this->get('/')->assertOk()
            ->assertSee(route('legal.notice'), false)
            ->assertSee(route('legal.privacy'), false)
            ->assertSee(route('legal.cookies'), false);
    }

    public function test_public_pages_do_not_link_to_the_staff_login(): void
    {
        foreach (['/', '/servicios', '/barberos', '/resenas', '/contacto', '/reservar', '/aviso-legal'] as $page) {
            $this->get($page)->assertOk()
                ->assertDontSee('Acceso del equipo')
                ->assertDontSee('href="'.route('login').'"', false);
        }
        // El panel sigue disponible para quien conoce la dirección.
        $this->get('/login')->assertOk();

        $this->get('/barberos/login')->assertMovedPermanently()->assertRedirect(route('public.barbers'));
    }

    public function test_legal_owner_data_comes_from_config(): void
    {
        config(['web.legal.owner' => 'Barbería Ejemplo S.A.S.', 'web.legal.nit' => '900.123.456-7']);

        $this->get(route('legal.privacy'))->assertSee('Barbería Ejemplo S.A.S.')->assertSee('900.123.456-7');
    }

    public function test_analytics_only_loads_when_configured(): void
    {
        // Sin analítica no hay nada que aceptar: el aviso flotante de cookies no aparece.
        config(['web.ga4_id' => null]);
        $this->get('/')->assertDontSee('id="cookieBanner"', false)->assertSee('const GA4 = null', false);

        config(['web.ga4_id' => 'G-TEST123']);
        $this->get('/')->assertSee('const GA4 = "G-TEST123"', false)
            ->assertSee('id="cookieBanner"', false)
            ->assertSee('data-cookie-choice="accepted"', false);
        // El script de Google no va en el HTML: se inserta solo tras aceptar.
        $this->get('/')->assertDontSee('<script async src="https://www.googletagmanager.com', false);
    }

    public function test_seo_basics(): void
    {
        $home = $this->get('/')->assertOk();
        $home->assertSee('<meta name="robots" content="index, follow">', false)
            ->assertSee('og:image" content="'.asset('images/og-image.jpg'), false)
            ->assertSee('"@type":"HairSalon"', false)
            ->assertSee('OpeningHoursSpecification', false)
            ->assertSee(asset('favicon.ico'), false);

        $this->get('/sitemap.xml')->assertOk()
            ->assertHeader('Content-Type', 'application/xml; charset=UTF-8')
            ->assertSee(route('legal.privacy'), false)
            ->assertSee(route('public.reviews'), false);
        $this->assertStringStartsWith('<?xml', $this->get('/sitemap.xml')->getContent());

        $this->get('/robots.txt')->assertOk()
            ->assertSee('Sitemap: '.route('sitemap'), false)
            ->assertSee('Disallow: /seguimiento/', false);
    }

    public function test_custom_404_page(): void
    {
        $this->get('/esta-pagina-no-existe')->assertNotFound()
            ->assertSee('Página no encontrada')
            ->assertSee('<meta name="robots" content="noindex">', false)
            ->assertSee('href="/">Ir al inicio', false);
    }

    public function test_https_is_forced_when_enabled(): void
    {
        config(['web.force_https' => true]);

        $this->get('http://localhost/servicios')->assertRedirect('https://localhost/servicios')->assertStatus(301);
        $this->get('http://localhost/up')->assertOk();
        $this->get('https://localhost/servicios')->assertOk()
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
    }

    public function test_https_is_not_forced_in_local(): void
    {
        config(['web.force_https' => false]);

        $this->get('http://localhost/servicios')->assertOk()->assertHeaderMissing('Strict-Transport-Security');
    }

    public function test_booking_honeypot_discards_bots(): void
    {
        $this->travelTo(\Carbon\Carbon::parse('2026-11-18 10:00:00'));
        $citas = User::factory()->create(['role' => 'barbero', 'attention_mode' => 'citas', 'active' => true]);
        $llegada = User::factory()->create(['role' => 'barbero', 'attention_mode' => 'llegada', 'active' => true]);

        $this->post(route('public.booking.appointment'), [
            'barber_id' => $citas->id, 'client_name' => 'Bot', 'client_phone' => '300 123 4567',
            'payment_method' => 'efectivo', 'appointment_date' => now()->addDay()->toDateString(), 'appointment_time' => '10:30',
            'website' => 'http://spam.example',
        ])->assertRedirect(route('public.booking'));

        $this->post(route('public.booking.turn'), [
            'barber_id' => $llegada->id, 'client_name' => 'Bot', 'client_phone' => '300 765 4321',
            'payment_method' => 'efectivo', 'website' => 'http://spam.example',
        ])->assertRedirect(route('public.booking'));

        $this->assertSame(0, Appointment::count());
        $this->assertSame(0, Turn::count());
    }

    public function test_booking_forms_keep_the_honeypot_field(): void
    {
        // Los dos formularios de reserva llevan el campo trampa.
        $this->assertSame(2, substr_count(file_get_contents(resource_path('views/public/booking.blade.php')), 'name="website"'));
    }
}
