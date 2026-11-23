<?php

namespace Tests\Feature;

use App\Models\Service;
use App\Models\User;
use App\Support\PageCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Caché de páginas públicas para visitantes anónimos (App\Support\PageCache). */
class PublicPageCacheTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['web.page_cache.enabled' => true, 'web.page_cache.ttl' => 60]);
        PageCache::flush();
    }

    protected function tearDown(): void
    {
        PageCache::flush();
        parent::tearDown();
    }

    public function test_la_segunda_visita_anonima_sale_de_la_cache_sin_sesion_ni_token(): void
    {
        $this->get('/')->assertOk()->assertHeader('X-Page-Cache', 'MISS');
        $this->assertFileExists(PageCache::directory().'/index.html');
        $this->assertStringContainsString('<meta name="csrf-token" content="">', file_get_contents(PageCache::directory().'/index.html'));

        $hit = $this->get('/')->assertOk()->assertHeader('X-Page-Cache', 'HIT');
        $this->assertSame([], $hit->headers->getCookies(), 'Una copia de la caché no crea sesión ni cookies.');
        $this->assertStringContainsString('<meta name="csrf-token" content="">', $hit->getContent());
    }

    public function test_quien_tiene_reservas_o_es_del_equipo_ve_su_pagina_y_no_la_copia(): void
    {
        $this->get('/servicios')->assertHeader('X-Page-Cache', 'MISS');

        $this->withUnencryptedCookie('mis_servicios', 'x')->get('/servicios')->assertOk()->assertHeaderMissing('X-Page-Cache');
        $this->withUnencryptedCookie('ubmc_staff', '1')->get('/servicios')->assertOk()->assertHeaderMissing('X-Page-Cache');
    }

    public function test_parametros_desconocidos_no_usan_la_cache_y_los_de_campanas_si(): void
    {
        $this->get('/barberos')->assertHeader('X-Page-Cache', 'MISS');
        $this->get('/barberos?utm_source=instagram')->assertHeader('X-Page-Cache', 'HIT');
        $this->get('/barberos?pagina=2')->assertOk()->assertHeaderMissing('X-Page-Cache');
    }

    public function test_paginas_con_formularios_o_datos_personales_nunca_se_guardan(): void
    {
        foreach (['/reservar', '/mis-servicios', '/resenas', '/login'] as $path) {
            $this->get($path)->assertHeaderMissing('X-Page-Cache');
        }
        $this->assertSame([], glob(PageCache::directory().'/*.html'));
    }

    public function test_con_sesion_iniciada_no_se_guarda_la_pagina(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'active' => true]);

        $this->actingAs($admin)->get('/contacto')->assertOk()->assertHeaderMissing('X-Page-Cache');
        $this->assertFileDoesNotExist(PageCache::directory().'/contacto.html');
    }

    public function test_un_cambio_en_el_panel_borra_las_copias(): void
    {
        $service = Service::create(['name' => 'Corte', 'price' => 20000, 'duration_minutes' => 30, 'active' => true]);
        $this->get('/servicios')->assertHeader('X-Page-Cache', 'MISS');
        $this->assertFileExists(PageCache::directory().'/servicios.html');

        $service->update(['price' => 25000]);

        $this->assertFileDoesNotExist(PageCache::directory().'/servicios.html');
        $this->get('/servicios')->assertHeader('X-Page-Cache', 'MISS')->assertSee('25.000');
    }

    public function test_las_copias_vencidas_no_se_usan_y_se_purgan(): void
    {
        $this->get('/nosotros')->assertHeader('X-Page-Cache', 'MISS');
        touch(PageCache::directory().'/nosotros.html', time() - 120);

        $this->assertSame(1, PageCache::purgeExpired());
        $this->get('/nosotros')->assertHeader('X-Page-Cache', 'MISS');
    }

    public function test_al_entrar_el_equipo_queda_marcado_para_no_usar_la_copia(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'active' => true, 'password' => bcrypt('secreta-123')]);

        $this->post(route('login'), ['email' => $admin->email, 'password' => 'secreta-123'])->assertCookie('ubmc_staff');
        $this->post(route('logout'))->assertCookieExpired('ubmc_staff');
    }

    public function test_la_version_del_contenido_no_abre_sesion(): void
    {
        $response = $this->getJson('/contenido/version')->assertOk()->assertJsonStructure(['v']);
        $this->assertSame([], $response->headers->getCookies());
    }
}
