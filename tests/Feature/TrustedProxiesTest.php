<?php

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class TrustedProxiesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::get('/__test/request-context', fn (Request $request) => response()->json([
            'ip' => $request->ip(),
            'secure' => $request->secure(),
        ]));
    }

    public function test_cloudflare_puede_enviar_la_ip_real_y_el_protocolo_https(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '173.245.48.10', 'HTTPS' => 'off'])
            ->get('http://localhost/__test/request-context', [
                'CF-Connecting-IP' => '203.0.113.42',
                'X-Forwarded-For' => '203.0.113.42',
                'X-Forwarded-Proto' => 'https',
            ])
            ->assertOk()
            ->assertJson(['ip' => '203.0.113.42', 'secure' => true]);
    }

    public function test_las_cabeceras_de_un_origen_fuera_de_cloudflare_se_ignoran(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.25', 'HTTPS' => 'off'])
            ->get('http://localhost/__test/request-context', [
                'CF-Connecting-IP' => '203.0.113.99',
                'X-Forwarded-For' => '203.0.113.99',
                'X-Forwarded-Proto' => 'https',
            ])
            ->assertOk()
            ->assertJson(['ip' => '198.51.100.25', 'secure' => false]);
    }

    public function test_tambien_se_confia_en_los_rangos_ipv6_de_cloudflare(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '2400:cb00::1234'])
            ->get('http://localhost/__test/request-context', [
                'CF-Connecting-IP' => '2001:db8::1234',
                'X-Forwarded-For' => '2001:db8::1234',
            ])
            ->assertOk()
            ->assertJson(['ip' => '2001:db8::1234']);
    }

    public function test_el_acceso_directo_sin_cabeceras_conserva_la_ip_del_socket(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.25', 'HTTPS' => 'off'])
            ->get('http://localhost/__test/request-context')
            ->assertOk()
            ->assertJson(['ip' => '198.51.100.25', 'secure' => false]);
    }

    public function test_la_variable_de_render_puede_elegir_su_proxy_inmediato(): void
    {
        $original = getenv('TRUSTED_PROXIES');
        putenv('TRUSTED_PROXIES=REMOTE_ADDR');

        try {
            $config = require base_path('config/proxies.php');

            $this->assertSame(['REMOTE_ADDR'], $config['proxies']);
        } finally {
            putenv($original === false ? 'TRUSTED_PROXIES' : 'TRUSTED_PROXIES='.$original);
        }
    }

    public function test_la_configuracion_por_defecto_contiene_rangos_cloudflare_y_no_comodines(): void
    {
        $proxies = config('proxies.proxies');

        $this->assertIsArray($proxies);
        $this->assertContains('173.245.48.0/20', $proxies);
        $this->assertContains('2400:cb00::/32', $proxies);
        $this->assertNotContains('*', $proxies);
        $this->assertNotContains('**', $proxies);
    }
}
