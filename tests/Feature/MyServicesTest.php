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
        $this->assertTrue(AttendanceConfirmation::confirmed($appointment));
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

    public function test_the_page_shows_when_the_client_confirmed_attendance_from_whatsapp(): void
    {
        $turn = $this->book($this->barber('llegada'), 'turno');

        $this->get('/mis-servicios')->assertOk()->assertDontSee('Asistencia confirmada');

        // Lo mismo que hace el bot cuando el cliente responde «sí» por WhatsApp.
        AttendanceConfirmation::mark($turn);

        $this->get('/mis-servicios', ['X-Service-List' => '1'])->assertOk()->assertSee('Asistencia confirmada');
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
