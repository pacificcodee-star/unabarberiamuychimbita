<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Service;
use App\Models\Turn;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicBookingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Miércoles a las 10:00 a. m.: la barbería está abierta, así que se pueden tomar turnos.
        $this->travelTo(\Carbon\Carbon::parse('2026-11-18 10:00:00'));
    }

    private function barber(string $mode): User
    {
        return User::factory()->create([
            'role' => 'barbero',
            'attention_mode' => $mode,
            'active' => true,
        ]);
    }

    private function service(): Service
    {
        return Service::create([
            'name' => 'Corte clásico',
            'price' => 18000,
            'duration_minutes' => 30,
            'active' => true,
        ]);
    }

    public function test_a_visitor_can_book_an_appointment_without_logging_in(): void
    {
        $barber = $this->barber('citas');
        $service = $this->service();

        $response = $this->post(route('public.booking.appointment'), [
            'barber_id' => $barber->id,
            'service_id' => $service->id,
            'client_name' => 'Cliente Público',
            'client_phone' => '300 123 4567',
            'payment_method' => 'efectivo',
            'appointment_date' => now()->addDay()->toDateString(),
            'appointment_time' => '10:30',
            'notes' => 'Primera visita',
        ]);

        // Sin panel ni aviso de "cita registrada": va directo a Mis servicios, donde puede confirmarla.
        $response->assertRedirect(route('my-services.index'));
        $response->assertSessionMissing('booking_success');

        $this->assertDatabaseHas('appointments', [
            'barber_id' => $barber->id,
            'client_name' => 'Cliente Público',
            'status' => 'pendiente',
        ]);
    }

    /** La cita ya no deja el panel de "cita registrada": se ve en Mis servicios y /reservar queda limpio. */
    public function test_the_booked_appointment_shows_in_my_services_without_the_old_panel(): void
    {
        $barber = $this->barber('citas');
        $date = now()->addDay()->toDateString();

        $response = $this->post(route('public.booking.appointment'), [
            'barber_id' => $barber->id,
            'service_id' => $this->service()->id,
            'client_name' => 'Cliente Público',
            'client_phone' => '300 123 4567',
            'payment_method' => 'efectivo',
            'appointment_date' => $date,
            'appointment_time' => '10:30',
        ])->assertRedirect(route('my-services.index'));

        $this->get(route('my-services.index'))
            ->assertOk()
            ->assertDontSee('Tu cita quedó registrada');

        // La cita recién creada aparece en Mis servicios con sus acciones.
        if ($device = $response->getCookie('mis_servicios')) {
            $this->withCookie('mis_servicios', $device->getValue());
            $this->get(route('my-services.index'))->assertOk()->assertSee('data-action="confirmar"', false);
        }

        $this->get(route('public.booking', ['tipo' => 'cita']))
            ->assertOk()
            ->assertDontSee('id="bookingSuccess"', false);
    }

    public function test_a_visitor_can_take_a_turn_without_logging_in(): void
    {
        $barber = $this->barber('llegada');
        $service = $this->service();

        $response = $this->post(route('public.booking.turn'), [
            'barber_id' => $barber->id,
            'service_id' => $service->id,
            'client_name' => 'Cliente Turno',
            'client_phone' => '300 765 4321',
            'payment_method' => 'efectivo',
        ]);

        $response->assertRedirect(route('public.booking', ['tipo' => 'turno']));
        $response->assertSessionHas('booking_success');

        $this->assertDatabaseHas('turns', [
            'barber_id' => $barber->id,
            'client_name' => 'Cliente Turno',
            'turn_number' => 1,
            'status' => 'llamado', // el barbero está libre: se le llama de inmediato
        ]);

        $this->post(route('public.booking.turn'), [
            'barber_id' => $barber->id,
            'service_id' => $service->id,
            'client_name' => 'Segundo Cliente',
            'client_phone' => '300 111 2222',
            'payment_method' => 'efectivo',
        ])->assertRedirect(route('public.booking', ['tipo' => 'turno']));

        $this->assertSame(2, Turn::where('barber_id', $barber->id)->max('turn_number'));
        $this->assertSame('esperando', Turn::where('barber_id', $barber->id)->where('turn_number', 2)->value('status'));
    }

    public function test_attention_modes_cannot_be_mixed(): void
    {
        $appointmentBarber = $this->barber('citas');
        $turnBarber = $this->barber('llegada');

        $this->post(route('public.booking.turn'), [
            'barber_id' => $appointmentBarber->id,
            'service_id' => $this->service()->id,
            'client_name' => 'Cliente Equivocado',
            'client_phone' => '300 000 0000',
            'payment_method' => 'efectivo',
        ])->assertSessionHasErrors('barber_id');

        $this->post(route('public.booking.appointment'), [
            'barber_id' => $turnBarber->id,
            'service_id' => $this->service()->id,
            'client_name' => 'Cliente Equivocado',
            'client_phone' => '300 000 0000',
            'payment_method' => 'efectivo',
            'appointment_date' => now()->addDay()->toDateString(),
            'appointment_time' => '11:00',
        ])->assertSessionHasErrors('barber_id');

        $this->assertSame(0, Appointment::count());
        $this->assertSame(0, Turn::count());
    }
}
