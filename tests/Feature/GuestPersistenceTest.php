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

    public function test_empty_active_tab_offers_a_new_service_without_recovery(): void
    {
        $this->book($this->barber())->update(['status' => 'atendida']);

        $this->get('/mis-servicios')->assertSee('No tienes servicios activos')
            ->assertSee('Solicitar nuevo servicio')->assertDontSee('Recuperar mis servicios');
    }

    public function test_recovery_pages_were_retired_and_lead_to_my_services(): void
    {
        $this->get('/mis-servicios/recuperar')->assertRedirect('/mis-servicios');
        $this->get('/mis-servicios/recuperar/codigo')->assertRedirect('/mis-servicios');
        $this->post('/mis-servicios/recuperar', ['phone' => '3001234567', 'consent' => '1'])->assertRedirect('/mis-servicios');
        $this->assertSame(0, GuestVerificationCode::count());
        $this->get('/')->assertDontSee('Recuperar mis servicios');
    }
}
