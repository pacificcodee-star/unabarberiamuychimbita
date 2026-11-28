<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Sale;
use App\Models\Service;
use App\Models\SiteSetting;
use App\Models\Turn;
use App\Models\User;
use App\Services\BusinessHours;
use App\Services\TurnQueueService;
use App\Services\TurnService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class EarlyCompletionAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    private const DAY = '2026-11-18';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse(self::DAY.' 09:00:00'));
        config([
            'services.whatsapp.driver' => 'evolution',
            'services.evolution.url' => 'https://evolution.test',
            'services.evolution.apikey' => 'global-key',
            'services.evolution.instance' => 'barberia',
        ]);
        Http::fake(['evolution.test/*' => Http::response(['key' => ['id' => 'X']])]);
        $this->admin = User::factory()->create(['role' => 'admin', 'active' => true]);
    }

    private function barber(string $mode): User
    {
        return User::factory()->create(['role' => 'barbero', 'active' => true, 'attention_mode' => $mode]);
    }

    private function service(int $minutes, int $price = 100000): Service
    {
        return Service::create([
            'name' => 'Servicio '.$minutes,
            'price' => $price,
            'duration_minutes' => $minutes,
            'active' => true,
        ]);
    }

    private function setHours(string $hours): void
    {
        $setting = SiteSetting::query()->firstOrNew([]);
        $setting->data = array_merge((array) ($setting->data ?? []), ['hours_wed' => $hours]);
        $setting->save();
    }

    private function book(User $barber, Service $service, string $time, int $price = 100000): Appointment
    {
        return Appointment::reserveSlot([
            'barber_id' => $barber->id,
            'service_id' => $service->id,
            'service_price' => $price,
            'client_name' => 'Cliente '.$time,
            'client_phone' => '3001234567',
            'appointment_date' => self::DAY,
            'appointment_time' => $time,
            'status' => 'confirmada',
            'payment_method' => 'efectivo',
        ]);
    }

    private function assertAppointmentRejected(callable $callback): void
    {
        try {
            $callback();
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('appointment_time', $e->errors());

            return;
        }

        $this->fail('Se aceptó una cita sobre un intervalo ocupado.');
    }

    public function test_turn_panel_restores_statuses_and_keeps_admin_attendance_controls(): void
    {
        $barber = $this->barber('llegada');
        $service = $this->service(30);
        $turn = app(TurnService::class)->createFor($barber, [
            'client_name' => 'Cliente del turno',
            'service_id' => $service->id,
        ], notify: false, advanceQueue: false);
        $turn->update(['status' => 'llamado', 'called_at' => now()->subMinutes(10)]);

        $this->actingAs($this->admin)->get(route('turns.index'))
            ->assertOk()
            ->assertSee('name="status"', false)
            ->assertSee('En espera', false)
            ->assertSee('Llamado', false)
            ->assertSee('En atención', false)
            ->assertSee('Finalizado', false)
            ->assertSee('Llegó', false)
            ->assertSee('No llegó', false);

        $this->actingAs($barber)->get(route('turns.index'))
            ->assertOk()
            ->assertDontSee('name="decision"', false);
    }

    public function test_legacy_active_turn_can_finish_and_recorded_absence_cannot_be_reactivated(): void
    {
        $barber = $this->barber('llegada');
        $service = $this->service(30);
        $legacy = Turn::create([
            'barber_id' => $barber->id,
            'service_id' => $service->id,
            'client_name' => 'Servicio anterior en curso',
            'turn_date' => self::DAY,
            'turn_number' => 1,
            'status' => 'atendiendo',
            'called_at' => now()->subHour(),
            'started_at' => now()->subMinutes(30),
        ]);
        $absent = Turn::create([
            'barber_id' => $barber->id,
            'service_id' => $service->id,
            'client_name' => 'Cliente que no llegó',
            'turn_date' => self::DAY,
            'turn_number' => 2,
            'status' => 'cancelado',
            'attendance_status' => 'ausente',
            'called_at' => now()->subMinutes(20),
            'finished_at' => now(),
        ]);

        $this->actingAs($this->admin)->patch(route('turns.status', $legacy), [
            'status' => 'atendido',
            'payment_method' => 'efectivo',
        ])->assertSessionHasNoErrors();
        $this->assertSame('atendido', $legacy->fresh()->status);
        $this->assertNotNull($legacy->fresh()->sale_id);

        $response = $this->actingAs($this->admin)->get(route('turns.index'))->assertOk();
        $this->assertMatchesRegularExpression(
            '/<tr id="turn-'.$absent->id.'"[^>]*>.*?<option value="esperando"[^>]*disabled[^>]*>/s',
            $response->getContent(),
        );
        $this->actingAs($this->admin)->patch(route('turns.status', $absent), [
            'status' => 'esperando',
        ])->assertSessionHasErrors('status');
        $this->assertSame('cancelado', $absent->fresh()->status);
        $this->assertSame('ausente', $absent->fresh()->attendance_status);
    }

    public function test_early_turn_completion_charges_full_service_and_reopens_capacity_for_web_and_bot(): void
    {
        $this->setHours('08:00 - 12:05');
        $barber = $this->barber('llegada');
        $long = $this->service(130);
        $short = $this->service(30, 20000);
        $tiny = $this->service(15, 15000);
        $turns = app(TurnService::class);

        $first = $turns->createFor($barber, [
            'client_name' => 'Servicio largo',
            'service_id' => $long->id,
            'payment_method' => 'efectivo',
        ], notify: false, advanceQueue: false);
        $second = $turns->createFor($barber, [
            'client_name' => 'Siguiente',
            'service_id' => $short->id,
            'payment_method' => 'efectivo',
        ], notify: false, advanceQueue: false);
        $first->update(['status' => 'llamado', 'called_at' => now()->subMinutes(10)]);

        $this->actingAs($this->admin)->patch(route('turns.attendance', $first), ['decision' => 'presente'])
            ->assertSessionHasNoErrors();
        $this->assertSame('atendiendo', $first->fresh()->status);
        $this->travel(90)->minutes(); // El servicio de 130 minutos termina 40 minutos antes.

        $hours = app(BusinessHours::class);
        $queue = app(TurnQueueService::class);
        $this->assertFalse($hours->turnFit($barber, $tiny)['ok']);
        $this->assertSame('11:50', $queue->freeAt($barber->id)->format('H:i'));

        $long->update(['price' => 120000]); // El precio del catálogo no cambia lo ya acordado.
        $this->actingAs($this->admin)->patch(route('turns.status', $first), [
            'status' => 'atendido',
            'payment_method' => 'transferencia',
        ])->assertSessionHasNoErrors();

        $first->refresh();
        $this->assertSame('atendido', $first->status);
        $this->assertSame('10:30', $first->finished_at->format('H:i'));
        $this->assertSame('transferencia', $first->sale->payment_method);
        $this->assertEquals(100000, (float) $first->sale->total);
        $this->assertSame(1, Sale::count());
        $this->assertSame('llamado', $second->fresh()->status);
        $this->assertSame('11:10', $queue->freeAt($barber->id)->format('H:i'));
        $this->assertTrue($hours->turnFit($barber, $tiny)['ok']);

        // La misma ruta que usan la web y WhatsApp vuelve a aceptar el servicio que antes no cabía.
        $third = $turns->createFor($barber, [
            'client_name' => 'Cupo liberado',
            'service_id' => $tiny->id,
        ], notify: false, advanceQueue: false);
        $this->assertSame(3, $third->fresh()->turn_number);
        $this->assertSame(3, Turn::count());
    }

    public function test_early_appointment_completion_charges_full_service_and_updates_page_slots_and_storage(): void
    {
        $this->setHours('08:00 - 20:00');
        $barber = $this->barber('citas');
        $long = $this->service(130);
        $this->actingAs($this->admin)->post(route('appointments.store'), [
            'barber_id' => $barber->id,
            'service_id' => $long->id,
            'client_name' => 'Cliente de prueba',
            'client_phone' => '3001234567',
            'appointment_date' => self::DAY,
            'appointment_time' => '10:00',
            'status' => 'confirmada',
            'payment_method' => 'efectivo',
        ])->assertSessionHasNoErrors();
        $appointment = Appointment::sole();
        $this->assertEquals(100000, (float) $appointment->service_price);

        $this->travelTo(Carbon::parse(self::DAY.' 10:00:00'));
        $this->actingAs($this->admin)->patch(route('appointments.attendance', $appointment), ['decision' => 'presente'])
            ->assertSessionHasNoErrors();
        $this->travel(90)->minutes(); // Fin real: 11:30; fin previsto: 12:10.

        $this->assertNotContains('11:31', Appointment::availableTimes($barber, self::DAY, service: $long));
        $this->actingAs($this->admin)->get(route('appointments.index', ['date' => self::DAY]))
            ->assertOk()
            ->assertSee('Finalizar y cobrar', false)
            ->assertSee('data-can-finish="1"', false)
            ->assertSee('data-duration="130"', false);

        $long->update(['price' => 120000]);
        $this->actingAs($this->admin)->patch(route('appointments.status', $appointment), [
            'status' => 'atendida',
            'payment_method' => 'efectivo',
        ])->assertSessionHasNoErrors();

        $appointment->refresh();
        $this->assertSame('atendida', $appointment->status);
        $this->assertSame('11:30', $appointment->completedAt()->format('H:i'));
        $this->assertSame(90, $appointment->occupiedMinutes());
        $this->assertEquals(100000, (float) $appointment->sale->total);
        $this->assertSame(1, Sale::count());
        $this->assertSame(1, $appointment->statusEvents()->where('to_status', 'atendida')->count());

        $slots = Appointment::availableTimes($barber, self::DAY, service: $long);
        $this->assertContains('11:31', $slots);
        $this->assertNotContains('11:30', $slots); // Sigue existiendo el minuto mínimo de separación.
        $publicSlots = $this->getJson(route('public.booking.slots', [
            'barber_id' => $barber->id,
            'date' => self::DAY,
            'service_id' => $long->id,
        ]))->assertOk()->json('times');
        $this->assertContains('11:31', $publicSlots);

        $this->assertAppointmentRejected(fn () => $this->book($barber, $long, '11:30'));
        $this->book($barber, $long, '11:31');
        $this->assertSame(2, Appointment::count());

        $this->actingAs($this->admin)->get(route('appointments.index', ['date' => self::DAY]))
            ->assertOk()
            ->assertSee('data-duration="90"', false)
            ->assertSee('data-can-finish="0"', false);

        $this->actingAs($this->admin)->patch(route('appointments.status', $appointment), ['status' => 'atendida'])
            ->assertSessionHas('error');
        $this->assertSame(1, Sale::count());
        $this->assertSame(1, $appointment->statusEvents()->where('to_status', 'atendida')->count());
    }

    public function test_late_completion_extends_occupancy_and_partial_minutes_round_up(): void
    {
        $this->setHours('08:00 - 20:00');
        $lateBarber = $this->barber('citas');
        $service = $this->service(30);
        $late = $this->book($lateBarber, $service, '10:00');

        $this->travelTo(Carbon::parse(self::DAY.' 10:00:00'));
        $this->actingAs($this->admin)->patch(route('appointments.attendance', $late), ['decision' => 'presente'])
            ->assertSessionHasNoErrors();
        $this->travel(45)->minutes();
        $late->update(['status' => 'atendida']);

        $this->assertSame('10:45', $late->fresh()->occupiedUntil()->format('H:i'));
        $this->assertSame(45, $late->fresh()->occupiedMinutes());
        $this->assertNotContains('10:45', Appointment::availableTimes($lateBarber, self::DAY, service: $service));
        $this->assertContains('10:46', Appointment::availableTimes($lateBarber, self::DAY, service: $service));

        $roundedBarber = $this->barber('citas');
        $rounded = $this->book($roundedBarber, $service, '10:00');
        $this->travelTo(Carbon::parse(self::DAY.' 10:00:00'));
        $this->actingAs($this->admin)->patch(route('appointments.attendance', $rounded), ['decision' => 'presente'])
            ->assertSessionHasNoErrors();
        $this->travel(30)->minutes();
        $this->travel(30)->seconds();
        $rounded->update(['status' => 'atendida']);

        $this->assertSame('10:31', $rounded->fresh()->completedAt()->format('H:i'));
        $this->assertNotContains('10:31', Appointment::availableTimes($roundedBarber, self::DAY, service: $service));
        $this->assertContains('10:32', Appointment::availableTimes($roundedBarber, self::DAY, service: $service));
    }
}
