<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\BarberUnavailability;
use App\Models\Service;
use App\Models\StaffNotification;
use App\Models\Turn;
use App\Models\User;
use App\Services\TurnQueueService;
use App\Support\AttendanceConfirmation;
use App\Support\ClientServiceAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

class MyServicesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->parse('2026-11-18 10:00:00'));
        $this->withCredentials();
    }

    private function barber(string $mode): User
    {
        return User::factory()->create(['role' => 'barbero', 'active' => true, 'attention_mode' => $mode]);
    }

    private function book(User $barber, string $type): Appointment|Turn
    {
        $service = Service::create([
            'name' => 'Corte clásico',
            'price' => 18000,
            'duration_minutes' => 30,
            'active' => true,
        ]);
        $response = $this->post('/reservar/'.$type, [
            'barber_id' => $barber->id, 'service_id' => $service->id, 'client_name' => 'Ana', 'client_phone' => '3001234567',
            'payment_method' => 'efectivo', 'appointment_date' => '2026-11-19', 'appointment_time' => '10:00',
        ])->assertSessionHasNoErrors()->assertRedirect();
        if ($device = $response->getCookie('mis_servicios')) {
            $this->withCookie('mis_servicios', $device->getValue());
        }
        return $type === 'cita' ? Appointment::latest('id')->firstOrFail() : Turn::latest('id')->firstOrFail();
    }

    public function test_customer_manages_original_appointment_and_staff_see_each_change(): void
    {
        $this->get('/')->assertDontSee('Mis servicios');
        $barber = $this->barber('citas');
        $appointment = $this->book($barber, 'cita');
        $this->get('/')->assertSee('Mis servicios');
        $action = "/mis-servicios/cita/{$appointment->id}/";
        $this->get('/mis-servicios')->assertSee('data-action="confirmar"', false)->assertSee('Volver');
        $this->postJson($action.'confirmar')->assertOk();
        $this->assertTrue(AttendanceConfirmation::confirmed($appointment->refresh()));
        $this->assertSame('confirmada', $appointment->fresh()->status);
        $this->postJson($action.'confirmar')->assertUnprocessable();
        $this->assertSame(1, StaffNotification::where('type', 'client_confirmed')->count());

        $occupied = Appointment::reserveSlot(['barber_id' => $barber->id, 'client_name' => 'Otra persona',
            'appointment_date' => '2026-11-19', 'appointment_time' => '11:00', 'status' => 'pendiente']);
        BarberUnavailability::create(['barber_id' => $barber->id, 'date' => '2026-11-19', 'start_time' => '12:00', 'end_time' => '12:30']);
        $this->getJson('/reservar/horarios?date=2026-11-19&barber_id='.$barber->id)->assertJsonMissing(['11:00'])->assertJsonMissing(['12:00']);
        foreach (['11:00', '12:00', '11:15'] as $time) {
            $this->postJson($action.'reprogramar', ['appointment_date' => '2026-11-19', 'appointment_time' => $time])->assertJsonValidationErrors('appointment_time');
        }
        $this->postJson($action.'reprogramar', ['appointment_date' => '2026-11-19', 'appointment_time' => '13:00'])->assertOk();
        $this->assertSame('13:00', $appointment->fresh()->startsAt()->format('H:i'));
        $this->assertSame(2, Appointment::count());
        $this->postJson($action.'cancelar')->assertOk();
        $this->postJson($action.'cancelar')->assertUnprocessable();
        $this->assertSame(1, $appointment->clientNotifications()->where('type', 'appt_cancelled')->count());
        $this->assertContains('13:00', Appointment::availableTimes($barber, '2026-11-19'));
        $this->get('/mis-servicios', ['X-Service-List' => '1'])->assertSeeInOrder(['Citas anteriores', 'Cancelada'])->assertDontSee('data-action="confirmar"', false);
        $this->postJson("/mis-servicios/cita/{$occupied->id}/cancelar")->assertNotFound();

        foreach ([$barber, User::factory()->create(['role' => 'admin'])] as $staff) {
            $this->actingAs($staff)->get(route('appointments.index', ['date' => '2026-11-19']))->assertOk()->assertSee('data-status="Cancelada"', false);
            $this->getJson(route('staff-notifications.feed'))->assertJsonFragment(['title' => 'Cita cancelada por el cliente']);
        }
    }

    public function test_empty_page_no_longer_offers_recovery(): void
    {
        $this->get('/mis-servicios')->assertOk()
            ->assertSee('Solicitar nuevo servicio')
            ->assertDontSee('Recuperar mis servicios')
            ->assertDontSee('/mis-servicios/recuperar');
    }

    public function test_appointment_timeline_waits_for_verified_arrival_and_finishes_from_the_real_start(): void
    {
        $appointment = $this->book($this->barber('citas'), 'cita');
        $appointment->update(['status' => 'confirmada']);
        AttendanceConfirmation::mark($appointment);

        $this->travelTo($appointment->startsAt()->subMinute());
        $this->get('/mis-servicios')->assertOk()
            ->assertSee('Cliente: Ana')
            ->assertSee('data-timeline-fill', false)
            ->assertSee('aria-valuenow="0"', false)
            ->assertSee('10:30 am');

        $this->travelTo($appointment->startsAt()->addMinutes(12));
        $this->get('/mis-servicios', ['X-Service-List' => '1'])->assertOk()
            ->assertSee('aria-valuenow="0"', false)
            ->assertDontSee('12 de 30 min')
            ->assertDontSee('Pendiente de cierre');

        $this->travelTo($appointment->startsAt()->addMinutes(32));
        $admin = User::factory()->create(['role' => 'admin', 'active' => true]);
        $this->actingAs($admin)->patch(route('appointments.attendance', $appointment), ['decision' => 'presente'])->assertRedirect();
        $this->assertSame('10:32', $appointment->fresh()->started_at->format('H:i'));
        $this->get('/mis-servicios')->assertOk()
            ->assertSee('aria-valuenow="0"', false)
            ->assertSee('10:32 am')
            ->assertDontSee('Citas anteriores');

        $this->travel(30)->minutes();
        $this->assertSame(1, app(\App\Services\AttendanceAutomation::class)->finishVerifiedAppointments());
        $this->get('/mis-servicios')->assertOk()
            ->assertSee('aria-valuenow="100"', false)
            ->assertSee('10:32 am')
            ->assertSee('Finalizado');
    }

    public function test_called_turn_timeline_starts_after_admin_verifies_arrival(): void
    {
        config(['notificaciones.turn_grace_minutes' => 10]);
        $turn = $this->book($this->barber('llegada'), 'turno');
        $queue = app(TurnQueueService::class);
        $this->assertSame('llamado', $turn->status);
        $this->get('/mis-servicios')->assertOk()
            ->assertSee('Cliente: Ana')
            ->assertSee('10:10 am')
            ->assertSee('10:40 am')
            ->assertSee('aria-valuenow="0"', false);

        $this->travel(12)->minutes();
        $queue->advance($turn->barber_id);
        $this->assertSame('llamado', $turn->fresh()->status);
        $this->assertNull($turn->fresh()->started_at);

        $admin = User::factory()->create(['role' => 'admin', 'active' => true]);
        $this->actingAs($admin)->patch(route('turns.attendance', $turn), ['decision' => 'presente'])->assertRedirect();
        $this->assertSame('10:12', $turn->fresh()->started_at->format('H:i'));
        $this->get('/mis-servicios')->assertOk()->assertSee('0 de 30 min');

        $this->travel(30)->minutes();
        $queue->advance($turn->barber_id);
        $this->get('/mis-servicios')->assertOk()
            ->assertSee('10:12 am')
            ->assertSee('10:42 am')
            ->assertSee('aria-valuenow="100"', false);
    }

    public function test_early_finished_turn_exposes_the_completed_timeline_to_the_public_animation(): void
    {
        config(['notificaciones.turn_grace_minutes' => 10]);
        $turn = $this->book($this->barber('llegada'), 'turno');
        $admin = User::factory()->create(['role' => 'admin', 'active' => true]);

        $this->get('/mis-servicios', ['X-Service-List' => '1'])->assertOk()
            ->assertSee('data-service-type="turno" data-service-id="'.$turn->id.'"', false)
            ->assertSee('data-finished="false"', false);

        $this->travel(12)->minutes();
        app(TurnQueueService::class)->advance($turn->barber_id);
        $this->actingAs($admin)->patch(route('turns.attendance', $turn), ['decision' => 'presente'])->assertRedirect();
        $this->get('/mis-servicios', ['X-Service-List' => '1'])->assertOk()
            ->assertSee('data-service-type="turno" data-service-id="'.$turn->id.'"', false)
            ->assertSee('data-finished="false"', false)
            ->assertSee('data-start=', false);
        $this->travel(12)->minutes();
        $this->actingAs($admin)->patch(route('turns.status', $turn), [
            'status' => 'atendido',
            'payment_method' => 'efectivo',
        ])->assertSessionHasNoErrors();

        $this->get('/mis-servicios', ['X-Service-List' => '1'])->assertOk()
            ->assertSee('data-service-type="turno" data-service-id="'.$turn->id.'"', false)
            ->assertSee('data-finished="true"', false)
            ->assertSee('aria-valuenow="100"', false)
            ->assertSee('Turnos anteriores')
            ->assertSee('Finalizado');
    }

    public function test_early_finished_appointment_exposes_the_completed_timeline_to_the_public_animation(): void
    {
        $appointment = $this->book($this->barber('citas'), 'cita');
        $appointment->update(['status' => 'confirmada']);
        $admin = User::factory()->create(['role' => 'admin', 'active' => true]);

        $this->travelTo($appointment->startsAt());
        $this->actingAs($admin)->patch(route('appointments.attendance', $appointment), ['decision' => 'presente'])->assertRedirect();
        $this->travel(12)->minutes();
        $this->actingAs($admin)->patch(route('appointments.status', $appointment), [
            'status' => 'atendida',
            'payment_method' => 'efectivo',
        ])->assertSessionHasNoErrors();

        $this->get('/mis-servicios', ['X-Service-List' => '1'])->assertOk()
            ->assertSee('data-service-type="cita" data-service-id="'.$appointment->id.'"', false)
            ->assertSee('data-finished="true"', false)
            ->assertSee('aria-valuenow="100"', false)
            ->assertSee('Citas anteriores')
            ->assertSee('Atendida');
    }

    public function test_web_and_pwa_keep_finished_services_with_the_cookie_after_the_session_closes(): void
    {
        $appointment = $this->book($this->barber('citas'), 'cita');
        $turn = $this->book($this->barber('llegada'), 'turno');
        $this->assertSame($appointment->guest_visitor_id, $turn->guest_visitor_id);
        $appointment->update(['status' => 'atendida']);
        $turn->update(['status' => 'atendido']);

        $this->flushSession();
        $this->travel(60)->days();
        $this->get('/?source=pwa')->assertSee('Mis servicios');
        $history = $this->get('/mis-servicios')->assertOk()
            ->assertSee('data-service-tab="historial" aria-selected="true"', false)
            ->assertSeeInOrder(['Citas anteriores', 'Atendida', 'Turnos anteriores', 'Finalizado']);
        $this->assertStringContainsString('no-store', $history->headers->get('Cache-Control'));

        $this->withCookie('mis_servicios', '');
        $this->postJson('/mis-servicios/vincular', ['type' => 'cita', 'token' => $appointment->public_token])->assertNotFound();
        $this->get('/mis-servicios')->assertOk()->assertDontSee('Citas anteriores')->assertDontSee('Turnos anteriores');
        $this->assertNotNull($appointment->fresh());
        $this->assertNotNull($turn->fresh());
    }

    public function test_checking_a_lost_booking_response_relinks_the_device(): void
    {
        $barber = $this->barber('citas');
        $appointment = Appointment::reserveSlot([
            'barber_id' => $barber->id, 'client_name' => 'Lucía', 'client_phone' => '3001234567',
            'appointment_date' => '2026-11-19', 'appointment_time' => '09:00', 'status' => 'pendiente',
        ]);
        $key = (string) Str::uuid();
        Cache::put('reserva:'.$key, [
            'success' => [
                'type' => 'cita',
                'track_url' => route('track.show', ['type' => 'cita', 'token' => $appointment->public_token]),
                'track_type' => 'cita',
                'track_token' => $appointment->public_token,
                'access_url' => ClientServiceAccess::recoveryUrl($appointment),
            ],
            'redirect' => route('my-services.index'),
        ], now()->addDay());

        $response = $this->getJson('/reservar/estado/'.$key)->assertOk()->assertJson(['status' => 'done']);
        $device = $response->getCookie('mis_servicios');
        $this->assertNotNull($device, 'La consulta de estado debe volver a vincular el dispositivo.');

        $this->withCookie('mis_servicios', $device->getValue());
        $this->get('/mis-servicios')->assertOk()->assertSee('9:00 am');
    }

    public function test_only_a_valid_individual_link_recovers_an_existing_booking(): void
    {
        $barber = $this->barber('citas');
        $data = ['barber_id' => $barber->id, 'client_phone' => '3001234567', 'appointment_date' => '2026-11-19', 'status' => 'pendiente'];
        $a = Appointment::create($data + ['appointment_time' => '11:00', 'client_name' => 'A']);
        $b = Appointment::create($data + ['appointment_time' => '12:00', 'client_name' => 'B']);
        $link = ClientServiceAccess::recoveryUrl($a);
        $this->get(str_replace($a->public_token, $b->public_token, $link))->assertForbidden();
        $this->postJson("/mis-servicios/cita/{$a->id}/confirmar")->assertNotFound();
        $response = $this->get($link)->assertRedirect('/mis-servicios');
        $this->withCookie('mis_servicios', $response->getCookie('mis_servicios')->getValue());
        $this->get('/mis-servicios')->assertSee('11:00 am')->assertDontSee('12:00 pm');
        foreach (['confirmar', 'reprogramar', 'cancelar'] as $action) {
            $this->postJson("/mis-servicios/cita/{$b->id}/$action")->assertNotFound();
        }
        $a->update(['status' => 'atendida']);
        foreach (['confirmar', 'cancelar'] as $action) {
            $this->postJson("/mis-servicios/cita/{$a->id}/$action")->assertUnprocessable();
        }
        $this->travel(40)->days();
        $this->get($link)->assertForbidden();
        $this->get('/mis-servicios')->assertSee('Atendida');
    }

    public function test_the_page_distinguishes_client_confirmation_from_verified_arrival(): void
    {
        $turn = $this->book($this->barber('llegada'), 'turno');

        $this->get('/mis-servicios')->assertOk()->assertDontSee('Cliente confirmó')->assertDontSee('Llegada verificada');

        AttendanceConfirmation::mark($turn);

        $this->get('/mis-servicios', ['X-Service-List' => '1'])->assertOk()
            ->assertSee('Cliente confirmó')->assertDontSee('Llegada verificada');

        $admin = User::factory()->create(['role' => 'admin', 'active' => true]);
        $this->travel(10)->minutes();
        app(TurnQueueService::class)->advance($turn->barber_id);
        $this->actingAs($admin)->patch(route('turns.attendance', $turn), ['decision' => 'presente'])->assertRedirect();
        $this->get('/mis-servicios')->assertOk()->assertSee('Llegada verificada');
    }

    public function test_cancelling_called_turn_updates_queue_estimates_history_and_notifications_once(): void
    {
        $barber = $this->barber('llegada');
        $first = $this->book($barber, 'turno');
        $second = $this->book($barber, 'turno');
        $third = $this->book($barber, 'turno');
        $queue = app(TurnQueueService::class);
        $before = $queue->estimates($barber->id)[$third->id];
        $this->postJson("/mis-servicios/turno/{$first->id}/cancelar")->assertOk();
        $this->postJson("/mis-servicios/turno/{$first->id}/cancelar")->assertUnprocessable();
        $this->assertSame('llamado', $second->fresh()->status);
        $after = $queue->estimates($barber->id)[$third->id];
        $this->assertSame($before['ahead'] - 1, $after['ahead']);
        $this->assertTrue($after['start_at']->lt($before['start_at']));
        $this->assertSame(1, $first->clientNotifications()->where('type', 'turn_cancelled')->count());
        $this->assertSame(1, StaffNotification::where('type', 'client_cancelled')->count());
        $this->get('/mis-servicios', ['X-Service-List' => '1'])->assertSeeInOrder(['1 persona antes de ti', 'Turnos anteriores', 'Anulado']);
        $second->update(['status' => 'atendiendo']);
        $this->postJson("/mis-servicios/turno/{$second->id}/cancelar")->assertUnprocessable();
        foreach ([$barber, User::factory()->create(['role' => 'admin'])] as $staff) {
            $this->actingAs($staff)->get(route('turns.index'))->assertOk()->assertSee('data-booking-region="queue"', false)->assertSee('Anulado');
        }
    }
}
