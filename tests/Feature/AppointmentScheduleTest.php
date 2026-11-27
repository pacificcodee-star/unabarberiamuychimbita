<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\BarberUnavailability;
use App\Models\Service;
use App\Models\SiteSetting;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** La disponibilidad y el guardado atómico usan la jornada diaria y la duración real del servicio. */
class AppointmentScheduleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-18 10:00:00')); // miércoles
    }

    private function barber(): User
    {
        return User::factory()->create(['role' => 'barbero', 'attention_mode' => 'citas', 'active' => true]);
    }

    private function service(int $minutes = 30): Service
    {
        return Service::create(['name' => 'Servicio '.$minutes, 'price' => 20000, 'duration_minutes' => $minutes, 'active' => true]);
    }

    private function hours(string $day, string $text): SiteSetting
    {
        $setting = SiteSetting::query()->firstOrCreate([], ['data' => []]);
        $data = $setting->data ?? [];
        $data['hours_'.$day] = $text;
        $data['booking_start'] = '00:00';
        $data['booking_end'] = '23:59';
        $setting->update(['data' => $data]);

        return $setting->refresh();
    }

    private function attributes(User $barber, string $date, string $time, ?Service $service = null): array
    {
        return [
            'barber_id' => $barber->id,
            'service_id' => $service?->id,
            'client_name' => 'Cliente',
            'appointment_date' => $date,
            'appointment_time' => $time,
            'status' => 'pendiente',
        ];
    }

    private function assertRejected(array $attributes): void
    {
        try {
            Appointment::reserveSlot($attributes);
            $this->fail('La cita debió rechazarse.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('appointment_time', $exception->errors());
        }
    }

    public function test_el_horario_diario_manda_sobre_el_rango_global_y_el_servicio_debe_caber_completo(): void
    {
        $this->hours('thu', '10:00 a. m. - 6:00 p. m.'); // jueves 19 de noviembre
        $barber = $this->barber();
        $service = $this->service(60);
        $slots = Appointment::availableTimes($barber, '2026-11-19', serviceId: $service->id);

        $this->assertSame('10:00', $slots[0]);
        $this->assertSame('17:00', end($slots));
        $this->assertNotContains('09:00', $slots);
        $this->assertNotContains('18:00', $slots);
        $this->assertRejected($this->attributes($barber, '2026-11-19', '09:00', $service));
        $this->assertRejected($this->attributes($barber, '2026-11-19', '18:00', $service));
        $this->assertNotNull(Appointment::reserveSlot($this->attributes($barber, '2026-11-19', '17:00', $service)));
    }

    public function test_cambiar_el_cierre_desde_el_panel_amplia_o_recorta_la_disponibilidad_inmediata(): void
    {
        $setting = $this->hours('thu', '9:00 a. m. - 8:00 p. m.');
        $barber = $this->barber();
        $service = $this->service(60);

        $this->assertNotContains('21:00', Appointment::availableTimes($barber, '2026-11-19', serviceId: $service->id));
        $setting->update(['data' => array_merge($setting->data, ['hours_thu' => '9:00 a. m. - 10:00 p. m.'])]);
        $this->assertContains('21:00', Appointment::availableTimes($barber, '2026-11-19', serviceId: $service->id));

        $setting->update(['data' => array_merge($setting->data, ['hours_thu' => '9:00 a. m. - 2:00 p. m.'])]);
        $this->assertNotContains('14:00', Appointment::availableTimes($barber, '2026-11-19', serviceId: $service->id));
        $this->assertNotContains('21:00', Appointment::availableTimes($barber, '2026-11-19', serviceId: $service->id));
        $reducedSlots = Appointment::availableTimes($barber, '2026-11-19', serviceId: $service->id);
        $this->assertSame('13:00', $reducedSlots[array_key_last($reducedSlots)]);
        $this->assertRejected($this->attributes($barber, '2026-11-19', '21:00', $service));
    }

    public function test_una_cita_no_puede_solaparse_ni_empezar_justo_cuando_termina_otra(): void
    {
        $this->hours('thu', '8:00 a. m. - 8:00 p. m.');
        $barber = $this->barber();
        $service = $this->service(45);
        Appointment::reserveSlot($this->attributes($barber, '2026-11-19', '08:00', $service));

        $slots = Appointment::availableTimes($barber, '2026-11-19', serviceId: $service->id);
        $this->assertNotContains('08:30', $slots);
        $this->assertContains('09:00', $slots);
        $this->assertRejected($this->attributes($barber, '2026-11-19', '08:30', $service));
        $this->assertRejected($this->attributes($barber, '2026-11-19', '08:45', $service));
        $this->assertNotNull(Appointment::reserveSlot($this->attributes($barber, '2026-11-19', '08:46', $service)));
    }

    public function test_tambien_se_rechaza_terminar_justo_cuando_empieza_la_siguiente(): void
    {
        $this->hours('thu', '8:00 a. m. - 8:00 p. m.');
        $barber = $this->barber();
        $service = $this->service(45);
        Appointment::reserveSlot($this->attributes($barber, '2026-11-19', '10:00', $service));

        $this->assertRejected($this->attributes($barber, '2026-11-19', '09:30', $service));
        $this->assertRejected($this->attributes($barber, '2026-11-19', '09:15', $service));
        $this->assertNotNull(Appointment::reserveSlot($this->attributes($barber, '2026-11-19', '09:14', $service)));
    }

    public function test_canceladas_y_otros_barberos_no_bloquean(): void
    {
        $this->hours('thu', '9:00 a. m. - 8:00 p. m.');
        $barber = $this->barber();
        $other = $this->barber();
        $service = $this->service(60);
        $cancelled = Appointment::reserveSlot($this->attributes($barber, '2026-11-19', '10:00', $service));
        $cancelled->update(['status' => 'cancelada']);
        Appointment::reserveSlot($this->attributes($other, '2026-11-19', '10:00', $service));

        $this->assertContains('10:00', Appointment::availableTimes($barber, '2026-11-19', serviceId: $service->id));
        $this->assertNotNull(Appointment::reserveSlot($this->attributes($barber, '2026-11-19', '10:00', $service)));
    }

    public function test_reprogramar_ignora_la_propia_cita_y_conserva_la_duracion_de_su_servicio(): void
    {
        $this->hours('thu', '9:00 a. m. - 8:00 p. m.');
        $barber = $this->barber();
        $service = $this->service(60);
        $appointment = Appointment::reserveSlot($this->attributes($barber, '2026-11-19', '10:00', $service));

        $this->assertContains('10:00', Appointment::availableTimes($barber, '2026-11-19', $appointment->id, $service->id));
        $this->assertRejected($this->attributes($barber, '2026-11-19', '10:00', $service));
        Appointment::reserveSlot([
            'barber_id' => $barber->id,
            'appointment_date' => '2026-11-19',
            'appointment_time' => '19:00',
            'status' => 'confirmada',
        ], $appointment);
        $this->assertSame('19:00', substr((string) $appointment->fresh()->appointment_time, 0, 5));
    }

    public function test_ninguna_parte_del_servicio_puede_caer_en_un_bloqueo_del_barbero(): void
    {
        $this->hours('thu', '8:00 a. m. - 8:00 p. m.');
        $barber = $this->barber();
        $service = $this->service(60);
        BarberUnavailability::create(['barber_id' => $barber->id, 'date' => '2026-11-19', 'start_time' => '10:15', 'end_time' => '11:00']);

        $slots = Appointment::availableTimes($barber, '2026-11-19', serviceId: $service->id);
        $this->assertNotContains('10:00', $slots);
        $this->assertContains('09:00', $slots); // termina justo antes del bloqueo
        $this->assertContains('11:00', $slots); // empieza justo al terminar el bloqueo
        $this->assertRejected($this->attributes($barber, '2026-11-19', '10:00', $service));
        $this->assertNotNull(Appointment::reserveSlot($this->attributes($barber, '2026-11-19', '09:00', $service)));
    }

    public function test_un_bloqueo_dentro_del_servicio_tambien_impide_reservar(): void
    {
        $this->hours('thu', '8:00 a. m. - 8:00 p. m.');
        $barber = $this->barber();
        $service = $this->service(120);
        BarberUnavailability::create(['barber_id' => $barber->id, 'date' => '2026-11-19', 'start_time' => '10:15', 'end_time' => '11:00']);

        $slots = Appointment::availableTimes($barber, '2026-11-19', serviceId: $service->id);
        $this->assertContains('08:00', $slots);
        $this->assertNotContains('09:00', $slots);
        $this->assertNotContains('10:00', $slots);
        $this->assertContains('11:00', $slots);
        $this->assertRejected($this->attributes($barber, '2026-11-19', '10:00', $service));
    }

    public function test_el_calendario_del_panel_se_amplia_con_el_cierre_configurado_y_muestra_citas_tardias(): void
    {
        $setting = SiteSetting::query()->firstOrCreate([], ['data' => []]);
        $data = ['booking_start' => '00:00', 'booking_end' => '23:59'];
        foreach (['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'] as $day) {
            $data['hours_'.$day] = 'Cerrado';
        }
        $data['hours_wed'] = '9:00 a. m. - 8:00 p. m.';
        $setting->update(['data' => $data]);
        $admin = User::factory()->create(['role' => 'admin', 'active' => true]);
        $this->actingAs($admin);

        $response = $this->get(route('appointments.index', ['date' => '2026-11-18']))->assertOk();
        $response->assertViewHas('hourEnd', 21);
        $this->assertTrue($response->viewData('rangeStart')->isSameDay(Carbon::parse('2026-11-16')));
        $this->assertTrue($response->viewData('rangeEnd')->isSameDay(Carbon::parse('2026-11-22')));

        $setting->update(['data' => array_merge($setting->data, ['hours_wed' => '9:00 a. m. - 11:59 p. m.'])]);
        $barber = $this->barber();
        $late = Appointment::reserveSlot($this->attributes($barber, '2026-11-18', '23:30', $this->service(29)));
        $late->update(['client_name' => 'Cita tardía']);

        $this->get(route('appointments.index', ['date' => '2026-11-18']))
            ->assertOk()
            ->assertViewHas('hourEnd', 24)
            ->assertViewHas('appointments', fn ($appointments) => $appointments->contains('id', $late->id))
            ->assertSee('Cita tardía');
    }

    public function test_la_reserva_final_rechaza_un_servicio_inactivo(): void
    {
        $this->hours('thu', '9:00 a. m. - 8:00 p. m.');
        $barber = $this->barber();
        $service = $this->service();
        $service->update(['active' => false]);

        try {
            Appointment::reserveSlot($this->attributes($barber, '2026-11-19', '10:00', $service));
            $this->fail('La cita debió rechazarse.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('service_id', $exception->errors());
        }
    }

    public function test_la_reserva_final_rechaza_un_servicio_que_el_barbero_ya_no_ofrece(): void
    {
        $this->hours('thu', '9:00 a. m. - 8:00 p. m.');
        $barber = $this->barber();
        $service = $this->service();
        $other = $this->service(15);
        $barber->offeredServices()->attach($other->id);

        try {
            Appointment::reserveSlot($this->attributes($barber, '2026-11-19', '10:00', $service));
            $this->fail('La cita debió rechazarse.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('service_id', $exception->errors());
        }
    }

    public function test_un_dia_cerrado_no_ofrece_horas_ni_acepta_reservas(): void
    {
        $this->hours('thu', 'Cerrado');
        $barber = $this->barber();
        $service = $this->service();

        $this->assertSame([], Appointment::availableTimes($barber, '2026-11-19', serviceId: $service->id));
        $this->assertRejected($this->attributes($barber, '2026-11-19', '10:00', $service));
    }
}
