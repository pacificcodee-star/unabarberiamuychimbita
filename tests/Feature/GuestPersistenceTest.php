<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\GuestCredential;
use App\Models\GuestVerificationCode;
use App\Models\GuestVisitor;
use App\Models\Service;
use App\Models\Turn;
use App\Models\User;
use App\Services\WhatsAppService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GuestPersistenceTest extends TestCase
{
    use RefreshDatabase;

    private string $deviceToken;

    private \Symfony\Component\HttpFoundation\Cookie $deviceCookie;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->parse('2026-11-18 10:00:00'));
    }

    private function barber(string $mode = 'citas'): User
    {
        return User::factory()->create(['role' => 'barbero', 'active' => true, 'attention_mode' => $mode]);
    }

    private function book(User $barber, string $type = 'cita', string $time = '10:00'): Appointment|Turn
    {
        $service = Service::firstOrCreate(['name' => 'Corte clásico'], [
            'price' => 18000, 'duration_minutes' => 30, 'active' => true,
        ]);
        $response = $this->post('/reservar/'.$type, [
            'barber_id' => $barber->id, 'service_id' => $service->id, 'client_name' => 'Ana',
            'client_phone' => '3001234567', 'payment_method' => 'efectivo',
            'appointment_date' => '2026-11-19', 'appointment_time' => $time,
        ])->assertSessionHasNoErrors()->assertRedirect();
        $this->deviceCookie = $response->getCookie('mis_servicios');
        $this->deviceToken = $this->deviceCookie->getValue();
        $this->withCookie('mis_servicios', $this->deviceToken);

        return $type === 'cita' ? Appointment::latest('id')->firstOrFail() : Turn::latest('id')->firstOrFail();
    }

    public function test_credential_persists_and_is_only_stored_as_a_hash(): void
    {
        $appointment = $this->book($this->barber());
        $this->get('/mis-servicios')->assertOk()->assertSee('Corte clásico');

        $credential = GuestCredential::firstOrFail();
        $this->assertNotSame($this->deviceToken, $credential->token_hash);
        $this->assertSame(hash('sha256', $this->deviceToken), $credential->token_hash);
        $this->assertTrue($this->deviceCookie->isHttpOnly());
        $this->assertSame('lax', $this->deviceCookie->getSameSite());
        $this->assertSame($appointment->guest_visitor_id, $credential->guest_visitor_id);
    }

    public function test_revoked_credential_stops_showing_bookings(): void
    {
        $appointment = $this->book($this->barber());
        $this->get('/mis-servicios')->assertSee('Corte clásico');

        GuestCredential::query()->update(['revoked_at' => now()]);

        $this->get('/mis-servicios')->assertOk()->assertDontSee('Corte clásico')
            ->assertSee('No tienes servicios activos');
        $this->assertNotNull($appointment->fresh());
    }

    public function test_visitor_can_have_active_and_historical_bookings_with_rebook_link(): void
    {
        $barber = $this->barber();
        $first = $this->book($barber, 'cita', '10:00');
        $second = $this->book($barber, 'cita', '11:00');

        $this->assertSame($first->guest_visitor_id, $second->guest_visitor_id);

        $this->get('/mis-servicios')->assertSeeInOrder(['Activos', 'Historial', 'Citas'])
            ->assertDontSee('Volver a reservar');

        $first->update(['status' => 'atendida']);
        $this->assertDatabaseHas('reservation_status_events', [
            'subject_type' => 'appointment', 'subject_id' => $first->id,
            'from_status' => 'pendiente', 'to_status' => 'atendida',
        ]);
        $this->get('/mis-servicios')->assertSeeInOrder(['Citas anteriores', 'Volver a reservar'])
            ->assertSee('Precio registrado: $18.000');
        $this->assertSame(2, $first->guestVisitor->appointments()->count());

        Service::where('name', 'Corte clásico')->update(['active' => false]);
        $this->get('/mis-servicios')->assertDontSee('Volver a reservar');
    }

    public function test_empty_active_tab_offers_a_new_service_and_recovery(): void
    {
        $this->book($this->barber())->update(['status' => 'atendida']);

        $this->get('/mis-servicios')->assertSee('No tienes servicios activos')
            ->assertSee('Solicitar nuevo servicio')->assertSee('Recuperar mis servicios');
    }

    public function test_phone_recovery_sends_a_hashed_single_use_code_and_restores_bookings(): void
    {
        $appointment = $this->book($this->barber());
        $this->withCookie('mis_servicios', 'dispositivo-nuevo-sin-credencial');
        $this->get('/mis-servicios')->assertDontSee('Corte clásico');

        $sent = null;
        $this->mock(WhatsAppService::class, function ($mock) use (&$sent) {
            $mock->shouldReceive('isConfigured')->andReturn(true);
            $mock->shouldReceive('sendText')->once()->andReturnUsing(function (string $to, string $message) use (&$sent) {
                $sent = $message;

                return true;
            });
        });

        $this->post('/mis-servicios/recuperar', ['phone' => '300 123 4567', 'consent' => '1'])
            ->assertRedirect(route('my-services.recover.verify'));
        $this->assertNotNull($sent);
        preg_match('/\b(\d{6})\b/', $sent, $match);
        $code = $match[1];

        $record = GuestVerificationCode::latest('id')->firstOrFail();
        $this->assertNotSame($code, $record->code_hash);
        $this->assertSame(GuestVerificationCode::hashCode($code), $record->code_hash);

        $this->post('/mis-servicios/recuperar/codigo', ['code' => $code === '000000' ? '111111' : '000000'])
            ->assertSessionHasErrors('code');
        $this->assertSame(1, $record->fresh()->attempts);
        $this->get('/mis-servicios')->assertDontSee('Corte clásico');

        $response = $this->post('/mis-servicios/recuperar/codigo', ['code' => $code])
            ->assertRedirect(route('my-services.index'));
        $this->withCookie('mis_servicios', $response->getCookie('mis_servicios')->getValue());

        $this->assertNotNull($record->fresh()->consumed_at);
        $this->assertNotNull(GuestVisitor::where('verified_phone', '3001234567')->first()?->recovery_consent_at);
        $this->get('/mis-servicios')->assertSee('Corte clásico');
        $this->assertSame($appointment->guest_visitor_id, GuestVisitor::where('verified_phone', '3001234567')->value('id'));
    }

    public function test_recovery_code_expires_and_blocks_after_five_attempts(): void
    {
        $this->book($this->barber());
        $this->withCookie('mis_servicios', 'otro-navegador');

        $sent = null;
        $this->mock(WhatsAppService::class, function ($mock) use (&$sent) {
            $mock->shouldReceive('isConfigured')->andReturn(true);
            $mock->shouldReceive('sendText')->andReturnUsing(function ($to, $message) use (&$sent) {
                $sent = $message;

                return true;
            });
        });

        $this->post('/mis-servicios/recuperar', ['phone' => '3001234567', 'consent' => '1']);
        preg_match('/\b(\d{6})\b/', (string) $sent, $match);
        $code = $match[1];
        $wrong = $code === '123456' ? '654321' : '123456';

        $this->travel(11)->minutes();
        $this->post('/mis-servicios/recuperar/codigo', ['code' => $code])->assertSessionHasErrors('code');
        $this->assertNull(GuestVerificationCode::latest('id')->first()->consumed_at);

        $this->travel(-11)->minutes();
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->post('/mis-servicios/recuperar/codigo', ['code' => $wrong])->assertSessionHasErrors('code');
        }
        $this->assertSame(5, GuestVerificationCode::latest('id')->first()->attempts);
        $this->post('/mis-servicios/recuperar/codigo', ['code' => $code])->assertSessionHasErrors('code');
        $this->assertNull(GuestVerificationCode::latest('id')->first()->consumed_at);
    }

    public function test_unknown_phone_gets_the_same_answer_without_sending_a_code(): void
    {
        $this->mock(WhatsAppService::class, function ($mock) {
            $mock->shouldReceive('isConfigured')->andReturn(true);
            $mock->shouldNotReceive('sendText');
        });

        $this->post('/mis-servicios/recuperar', ['phone' => '3110000000', 'consent' => '1'])
            ->assertRedirect(route('my-services.recover.verify'));
        $this->assertSame(0, GuestVerificationCode::count());
    }

    public function test_recovery_does_not_claim_to_send_when_whatsapp_is_not_configured(): void
    {
        $this->book($this->barber());
        $this->mock(WhatsAppService::class, function ($mock) {
            $mock->shouldReceive('isConfigured')->andReturn(false);
            $mock->shouldNotReceive('sendText');
        });

        $this->from('/mis-servicios/recuperar')
            ->post('/mis-servicios/recuperar', ['phone' => '3001234567', 'consent' => '1'])
            ->assertRedirect('/mis-servicios/recuperar')
            ->assertSessionHasErrors('phone');
        $this->assertSame(0, GuestVerificationCode::count());
    }

    public function test_recovery_requires_phone_ownership_consent(): void
    {
        $this->book($this->barber());
        $this->mock(WhatsAppService::class, fn ($mock) => $mock->shouldReceive('isConfigured')->andReturn(true));

        $this->post('/mis-servicios/recuperar', ['phone' => '3001234567'])
            ->assertSessionHasErrors('consent');
        $this->assertSame(0, GuestVerificationCode::count());
    }

    public function test_recovery_page_is_not_indexable_and_respects_privacy(): void
    {
        $this->mock(WhatsAppService::class, fn ($mock) => $mock->shouldReceive('isConfigured')->andReturn(true));

        $this->get('/mis-servicios/recuperar')->assertOk()
            ->assertSee('Recuperar mis servicios')
            ->assertSee('noindex, nofollow', false)
            ->assertSee('Enviar código');
        $this->get('/mis-servicios/recuperar/codigo')->assertRedirect('/mis-servicios/recuperar');
    }
}
