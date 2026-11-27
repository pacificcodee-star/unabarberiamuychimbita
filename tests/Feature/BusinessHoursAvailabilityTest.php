<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Service;
use App\Models\SiteSetting;
use App\Models\Turn;
use App\Models\User;
use App\Services\BusinessHours;
use App\Services\TurnService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * La disponibilidad de citas y turnos sale siempre del horario por día del panel del admin,
 * leído de la base de datos en cada consulta, y nunca hay choques entre agendamientos.
 */
class BusinessHoursAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    // Miércoles 18 y jueves 19 de noviembre de 2026; domingo 22.
    private const WED = '2026-11-18';

    private const THU = '2026-11-19';

    private const SUN = '2026-11-22';

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-17 07:00:00')); // martes: las fechas de prueba son futuras
    }

    private function setHours(array $hours): void
    {
        $setting = SiteSetting::query()->firstOrNew([]);
        $data = (array) ($setting->data ?? []);
        foreach ($hours as $day => $value) {
            $data['hours_'.$day] = $value;
        }
        $setting->data = $data;
        $setting->save();
    }

    private function citaBarber(): User
    {
        return User::factory()->create(['role' => 'barbero', 'attention_mode' => 'citas', 'active' => true]);
    }

    private function turnBarber(): User
    {
        return User::factory()->create(['role' => 'barbero', 'attention_mode' => 'llegada', 'active' => true]);
    }

    private function service(int $minutes, string $name = 'Corte'): Service
    {
        return Service::create(['name' => $name.' '.$minutes, 'price' => 20000, 'duration_minutes' => $minutes, 'active' => true]);
    }

    private function book(User $barber, Service $service, string $date, string $time): Appointment
    {
        return Appointment::reserveSlot([
            'barber_id' => $barber->id,
            'service_id' => $service->id,
            'client_name' => 'Cliente '.$time,
            'client_phone' => '300 123 4567',
            'appointment_date' => $date,
            'appointment_time' => $time,
            'status' => 'confirmada',
        ]);
    }

    private function assertRejected(callable $callback, string $field = 'appointment_time'): string
    {
        try {
            $callback();
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($field, $e->errors());

            return $e->errors()[$field][0];
        }
        $this->fail('Se aceptó una reserva que debía rechazarse.');
    }

    /** Formulario completo de "Configuración del negocio"; $hours reemplaza días concretos. */
    private function panelPayload(array $hours): array
    {
        $week = [];
        foreach (array_keys(SiteSetting::DAYS) as $day) {
            $week[$day] = $day === 'sun' ? ['closed' => '1'] : ['closed' => '0', 'open' => '09:00', 'close' => '20:00'];
        }

        return [
            'data' => [
                'address' => 'Calle 1 # 2-3', 'city' => 'Tumaco, Colombia', 'phone' => '+57 300 000 0000',
                'email' => 'hola@ejemplo.com', 'whatsapp' => '573000000000',
                'map_url' => 'https://www.google.com/maps?q=Tumaco&output=embed',
            ],
            'hours' => array_replace($week, $hours),
        ];
    }

    // --- Horario por día ---------------------------------------------------------------------

    public function test_cada_dia_ofrece_horas_solo_entre_su_apertura_y_su_cierre(): void
    {
        $this->setHours(['wed' => '10:00 a. m. - 2:00 p. m.', 'thu' => '8:00 a. m. - 12:00 p. m.']);
        $barber = $this->citaBarber();
        $service = $this->service(45);

        $wed = Appointment::availableTimes($barber, self::WED, null, $service);
        $this->assertSame('10:00', $wed[0]);
        $this->assertSame('13:00', end($wed)); // 13:00 + 45 min = 13:45, antes del cierre a las 2:00 p. m.
        $this->assertNotContains('13:30', $wed); // terminaría a las 2:15 p. m.

        $thu = Appointment::availableTimes($barber, self::THU, null, $service);
        $this->assertSame('08:00', $thu[0]);
        $this->assertSame('11:00', end($thu));

        // Ninguna hora ofrecida termina después del cierre.
        foreach ($wed as $time) {
            $this->assertLessThanOrEqual('14:00', Carbon::parse(self::WED.' '.$time)->addMinutes(45)->format('H:i'));
        }
    }

    public function test_una_cita_no_puede_empezar_antes_de_abrir_ni_terminar_despues_del_cierre(): void
    {
        $this->setHours(['wed' => '9:00 a. m. - 8:00 p. m.']);
        $barber = $this->citaBarber();
        $service = $this->service(45);

        $this->assertStringContainsString('fuera del horario', $this->assertRejected(fn () => $this->book($barber, $service, self::WED, '08:30')));
        $this->assertStringContainsString('después del cierre', $this->assertRejected(fn () => $this->book($barber, $service, self::WED, '19:30')));

        $this->book($barber, $service, self::WED, '19:15'); // termina justo a las 8:00 p. m.
        $this->assertSame(1, Appointment::count());
    }

    // --- Cierre extendido --------------------------------------------------------------------

    public function test_atrasar_el_cierre_en_el_panel_ofrece_las_horas_nuevas_enseguida(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'active' => true]);
        $barber = $this->citaBarber();
        $service = $this->service(30);
        $slots = fn () => $this->getJson(route('public.booking.slots', ['barber_id' => $barber->id, 'date' => self::WED, 'service_id' => $service->id]))
            ->assertOk()->json('times');

        $this->actingAs($admin)->put(route('site-settings.update'), $this->panelPayload([
            'wed' => ['closed' => '0', 'open' => '09:00', 'close' => '20:00'],
        ]))->assertSessionHasNoErrors();
        $this->assertSame('19:30', last($slots()));

        $this->actingAs($admin)->put(route('site-settings.update'), $this->panelPayload([
            'wed' => ['closed' => '0', 'open' => '09:00', 'close' => '21:00'],
        ]))->assertSessionHasNoErrors();
        $this->assertSame('20:30', last($slots()));
        $this->book($barber, $service, self::WED, '20:30');
        $this->assertSame(1, Appointment::count());
    }

    public function test_un_proceso_largo_ve_el_horario_nuevo_sin_reiniciar(): void
    {
        $this->setHours(['wed' => '9:00 a. m. - 8:00 p. m.']);
        $barber = $this->citaBarber();
        $service = $this->service(30);

        // Primera lectura: queda la copia de la petición (como en un worker de la cola del bot).
        SiteSetting::currentData();
        $this->assertNotContains('20:00', Appointment::availableTimes($barber, self::WED, null, $service));

        // Otro proceso (el panel) atrasa el cierre: se escribe en la base sin pasar por este proceso.
        DB::table('site_settings')->update(['data' => json_encode(['hours_wed' => '9:00 a. m. - 9:00 p. m.'])]);

        $this->assertContains('20:00', Appointment::availableTimes($barber, self::WED, null, $service));
        $this->assertSame('21:00', app(BusinessHours::class)->window(self::WED)['closing']->format('H:i'));
    }

    public function test_la_cola_acepta_turnos_hasta_el_nuevo_cierre_segun_la_duracion(): void
    {
        $this->setHours(['wed' => '9:00 a. m. - 8:00 p. m.']);
        $barber = $this->turnBarber();
        $service = $this->service(30);
        $take = fn (string $name) => app(TurnService::class)->createFor($barber, [
            'service_id' => $service->id, 'client_name' => $name, 'client_phone' => '300 123 4567',
        ], notify: false, advanceQueue: false);

        // 8:15 p. m.: con cierre a las 8:00 p. m. ya no hay jornada.
        $this->travelTo(Carbon::parse(self::WED.' 20:15:00'));
        $this->assertRejected(fn () => $take('Tarde'), 'barber_id');

        // El admin atrasa el cierre a las 9:00 p. m.: 8:15 + 10 min de margen + 30 min = 8:55 p. m. → cabe.
        $this->setHours(['wed' => '9:00 a. m. - 9:00 p. m.']);
        $take('Primero');

        // El segundo esperaría al primero: 8:55 + 10 + 30 = 9:35 p. m., después del cierre.
        $message = $this->assertRejected(fn () => $take('Segundo'), 'barber_id');
        $this->assertStringContainsString('9:35 p. m.', $message);
        $this->assertStringContainsString('9:00 p. m.', $message);
        $this->assertSame(1, Turn::count());

        // Con el cierre a las 9:30 p. m. sí alcanza un servicio de 15 min: 8:55 + 10 + 15 = 9:20 p. m.
        $this->setHours(['wed' => '9:00 a. m. - 9:30 p. m.']);
        $short = $this->service(15, 'Cejas');
        app(TurnService::class)->createFor($barber, [
            'service_id' => $short->id, 'client_name' => 'Cejas', 'client_phone' => '300 123 4567',
        ], notify: false, advanceQueue: false);
        $this->assertSame(2, Turn::count());
    }

    // --- Día cerrado -------------------------------------------------------------------------

    public function test_un_dia_cerrado_no_tiene_disponibilidad(): void
    {
        $this->setHours(['sun' => 'Cerrado', 'wed' => 'Cerrado']);
        $barber = $this->citaBarber();
        $service = $this->service(30);

        $this->assertSame([], Appointment::availableTimes($barber, self::SUN, null, $service));
        $this->assertSame([], Appointment::availableTimes($barber, self::WED, null, $service));
        $this->assertNull(app(BusinessHours::class)->window(self::WED));
        $this->assertStringContainsString('cerrada', $this->assertRejected(fn () => $this->book($barber, $service, self::WED, '10:00')));

        // Turnos: el día cerrado tampoco recibe solicitudes.
        $this->travelTo(Carbon::parse(self::WED.' 11:00:00'));
        $this->assertRejected(fn () => app(TurnService::class)->createFor($this->turnBarber(), [
            'service_id' => $service->id, 'client_name' => 'Sin Servicio', 'client_phone' => '300 123 4567',
        ], notify: false, advanceQueue: false), 'barber_id');
        $this->assertSame(0, Turn::count());
    }

    // --- Sin choques: 8:45 → 8:46 ------------------------------------------------------------

    public function test_si_un_corte_termina_a_las_8_45_la_siguiente_cita_empieza_a_las_8_46(): void
    {
        $this->setHours(['wed' => '8:00 a. m. - 6:00 p. m.']);
        $barber = $this->citaBarber();
        $corte = $this->service(45);

        $this->book($barber, $corte, self::WED, '08:00'); // 8:00 → 8:45

        $slots = Appointment::availableTimes($barber, self::WED, null, $corte);
        $this->assertSame('08:46', $slots[0]);
        $this->assertNotContains('08:00', $slots);
        $this->assertNotContains('08:30', $slots);
        $this->assertContains('09:00', $slots); // la grilla sigue: 9:00 tampoco choca

        $this->assertRejected(fn () => $this->book($barber, $corte, self::WED, '08:45'));
        $this->assertRejected(fn () => $this->book($barber, $corte, self::WED, '08:30'));
        $this->book($barber, $corte, self::WED, '08:46');
        $this->assertSame(2, Appointment::count());
    }

    public function test_una_cita_tampoco_puede_terminar_justo_cuando_empieza_la_siguiente(): void
    {
        $this->setHours(['wed' => '8:00 a. m. - 6:00 p. m.']);
        $barber = $this->citaBarber();
        $corte = $this->service(30);

        $this->book($barber, $corte, self::WED, '10:00');

        // 9:30 + 30 min = 10:00, el mismo minuto en que empieza la otra: choca.
        $this->assertNotContains('09:30', Appointment::availableTimes($barber, self::WED, null, $corte));
        $this->assertRejected(fn () => $this->book($barber, $corte, self::WED, '09:30'));
        $this->book($barber, $corte, self::WED, '09:29'); // termina a las 9:59
        $this->assertSame(2, Appointment::count());
    }

    public function test_reprogramar_una_cita_no_choca_consigo_misma(): void
    {
        $this->setHours(['wed' => '8:00 a. m. - 6:00 p. m.']);
        $barber = $this->citaBarber();
        $corte = $this->service(45);
        $appointment = $this->book($barber, $corte, self::WED, '09:00');

        $this->assertContains('09:30', Appointment::availableTimes($barber, self::WED, $appointment->id, $corte));
        Appointment::reserveSlot(['barber_id' => $barber->id, 'appointment_date' => self::WED, 'appointment_time' => '09:30'], $appointment);
        $this->assertSame('09:30', substr((string) $appointment->fresh()->appointment_time, 0, 5));
    }
}
