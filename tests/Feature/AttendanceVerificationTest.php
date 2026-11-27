<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\ClientNotification;
use App\Models\Sale;
use App\Models\Service;
use App\Models\StaffNotification;
use App\Models\Turn;
use App\Models\User;
use App\Services\AttendanceAutomation;
use App\Services\TurnQueueService;
use App\Services\TurnService;
use App\Support\AttendanceConfirmation;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AttendanceVerificationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Service $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-18 10:00:00'));
        config([
            'services.whatsapp.driver' => 'evolution',
            'services.evolution.url' => 'https://evolution.test',
            'services.evolution.apikey' => 'global-key',
            'services.evolution.instance' => 'barberia',
        ]);
        Http::fake(['evolution.test/*' => Http::response(['key' => ['id' => 'X']])]);
        $this->admin = User::factory()->create(['role' => 'admin', 'active' => true]);
        $this->service = Service::create(['name' => 'Corte', 'price' => 20000, 'duration_minutes' => 30, 'active' => true]);
    }

    private function barber(string $mode): User
    {
        return User::factory()->create(['role' => 'barbero', 'active' => true, 'attention_mode' => $mode]);
    }

    private function appointment(User $barber): Appointment
    {
        $appointment = Appointment::create([
            'barber_id' => $barber->id,
            'service_id' => $this->service->id,
            'client_name' => 'Ana',
            'client_phone' => '3001234567',
            'appointment_date' => '2026-11-18',
            'appointment_time' => '10:00',
            'status' => 'confirmada',
            'payment_method' => 'efectivo',
        ]);
        AttendanceConfirmation::mark($appointment);

        return $appointment;
    }

    public function test_client_confirmation_only_prompts_the_admin_and_never_generates_income(): void
    {
        $barber = $this->barber('citas');
        $appointment = $this->appointment($barber);

        $result = app(AttendanceAutomation::class)->run();

        $this->assertSame(1, $result['prompted']);
        $this->assertSame('confirmada', $appointment->fresh()->status);
        $this->assertNull($appointment->fresh()->attendance_status);
        $this->assertSame(0, Sale::count());
        $notice = StaffNotification::where('type', 'attendance_check')->firstOrFail();
        $this->assertNull($notice->barber_id);
        $this->assertStringContainsString('attendance='.$appointment->id, $notice->url);
        $this->assertSame(0, StaffNotification::visibleTo($barber)->where('type', 'attendance_check')->count());
        $this->assertSame(1, StaffNotification::visibleTo($this->admin)->where('type', 'attendance_check')->count());

        app(AttendanceAutomation::class)->run();
        $this->assertSame(1, StaffNotification::where('type', 'attendance_check')->count());
        $this->assertSame(0, Sale::count());
    }

    public function test_verified_appointment_finishes_after_actual_start_and_records_one_sale(): void
    {
        $appointment = $this->appointment($this->barber('citas'));

        $this->actingAs($this->admin)->patchJson(route('appointments.attendance', $appointment), ['decision' => 'presente'])
            ->assertOk()->assertJson(['attendance_status' => 'presente']);

        $appointment->refresh();
        $this->assertSame('confirmada', $appointment->status);
        $this->assertSame('10:00', $appointment->started_at->format('H:i'));
        $this->assertSame($this->admin->id, $appointment->attendance_verified_by);
        $this->assertSame(0, Sale::count());

        $this->travel(29)->minutes();
        $this->assertSame(0, app(AttendanceAutomation::class)->finishVerifiedAppointments());
        $this->assertSame(0, Sale::count());

        $this->travel(1)->minutes();
        $this->assertSame(1, app(AttendanceAutomation::class)->finishVerifiedAppointments());
        $appointment->refresh();
        $this->assertSame('atendida', $appointment->status);
        $this->assertNotNull($appointment->sale_id);
        $this->assertSame(1, Sale::count());
        $this->assertSame(1, $appointment->sale->items()->count());
        $this->assertTrue(ClientNotification::where('notifiable_id', $appointment->id)->where('type', 'service_thanks')->exists());

        app(AttendanceAutomation::class)->finishVerifiedAppointments();
        $this->assertSame(1, Sale::count());
    }

    public function test_no_show_cancels_without_sale_thanks_or_survey(): void
    {
        $appointment = $this->appointment($this->barber('citas'));

        $this->actingAs($this->admin)->patchJson(route('appointments.attendance', $appointment), ['decision' => 'ausente'])
            ->assertOk()->assertJson(['attendance_status' => 'ausente']);

        $appointment->refresh();
        $this->assertSame('cancelada', $appointment->status);
        $this->assertSame('ausente', $appointment->attendance_status);
        $this->assertNull($appointment->started_at);
        $this->assertSame(0, Sale::count());
        $this->assertSame(0, ClientNotification::where('notifiable_id', $appointment->id)->where('type', 'service_thanks')->count());
        $this->assertSame(0, ClientNotification::where('notifiable_id', $appointment->id)->where('type', 'survey_invite')->count());
        $this->assertSame(0, Appointment::where('status', '!=', 'cancelada')->count());

        $cancellations = ClientNotification::where('notifiable_id', $appointment->id)->where('type', 'appt_cancelled')->count();
        $this->actingAs($this->admin)->patchJson(route('appointments.attendance', $appointment), ['decision' => 'ausente'])->assertOk();
        $this->assertSame($cancellations, ClientNotification::where('notifiable_id', $appointment->id)->where('type', 'appt_cancelled')->count());
        $this->assertSame(0, Sale::count());
    }

    public function test_called_turn_waits_for_arrival_then_finishes_and_calls_the_next_client(): void
    {
        $barber = $this->barber('llegada');
        $turns = app(TurnService::class);
        $first = $turns->createFor($barber, ['client_name' => 'Primero', 'service_id' => $this->service->id]);
        $second = $turns->createFor($barber, ['client_name' => 'Segundo', 'service_id' => $this->service->id]);

        $this->travel(10)->minutes();
        $this->artisan('barberia:tick')->assertSuccessful();
        $this->assertSame('llamado', $first->fresh()->status);
        $this->assertNull($first->fresh()->started_at);
        $this->assertSame(0, Sale::count());

        $this->actingAs($this->admin)->patchJson(route('turns.attendance', $first), ['decision' => 'presente'])->assertOk();
        $this->assertSame('atendiendo', $first->fresh()->status);
        $this->assertSame('10:10', $first->fresh()->started_at->format('H:i'));
        $this->assertSame('esperando', $second->fresh()->status);
        $this->assertSame(0, Sale::count());

        $this->travel(29)->minutes();
        $this->artisan('barberia:tick')->assertSuccessful();
        $this->assertSame('atendiendo', $first->fresh()->status);

        $this->travel(1)->minutes();
        $this->artisan('barberia:tick')->assertSuccessful();
        $this->assertSame('atendido', $first->fresh()->status);
        $this->assertSame('llamado', $second->fresh()->status);
        $this->assertSame(1, Sale::count());
    }

    public function test_turn_no_show_cancels_and_advances_without_income(): void
    {
        $barber = $this->barber('llegada');
        $turns = app(TurnService::class);
        $first = $turns->createFor($barber, ['client_name' => 'Primero', 'service_id' => $this->service->id]);
        $second = $turns->createFor($barber, ['client_name' => 'Segundo', 'service_id' => $this->service->id]);

        $this->travel(10)->minutes();
        $this->actingAs($this->admin)->patchJson(route('turns.attendance', $first), ['decision' => 'ausente'])->assertOk();

        $this->assertSame('cancelado', $first->fresh()->status);
        $this->assertSame('ausente', $first->fresh()->attendance_status);
        $this->assertSame('llamado', $second->fresh()->status);
        $this->assertSame(0, Sale::count());
        $this->assertSame(0, ClientNotification::where('notifiable_id', $first->id)->where('type', 'service_thanks')->count());

        $cancellations = ClientNotification::where('notifiable_id', $first->id)->where('type', 'turn_cancelled')->count();
        $this->actingAs($this->admin)->patchJson(route('turns.attendance', $first), ['decision' => 'ausente'])->assertOk();
        $this->assertSame($cancellations, ClientNotification::where('notifiable_id', $first->id)->where('type', 'turn_cancelled')->count());
        $this->assertSame('llamado', $second->fresh()->status);
        $this->assertSame(0, Sale::count());
    }

    public function test_barber_cannot_verify_or_bypass_the_admin_decision(): void
    {
        $barber = $this->barber('citas');
        $appointment = $this->appointment($barber);
        $turnBarber = $this->barber('llegada');
        $turn = app(TurnService::class)->createFor($turnBarber, ['client_name' => 'Turno', 'service_id' => $this->service->id]);
        $this->travel(10)->minutes();

        $this->actingAs($barber)->patchJson(route('appointments.attendance', $appointment), ['decision' => 'presente'])->assertForbidden();
        $this->patch(route('appointments.status', $appointment), ['status' => 'atendida'])->assertSessionHasErrors('status');
        $this->assertSame('confirmada', $appointment->fresh()->status);

        $this->actingAs($turnBarber)->patchJson(route('turns.attendance', $turn), ['decision' => 'presente'])->assertForbidden();
        $this->patch(route('turns.status', $turn), ['status' => 'atendiendo'])->assertSessionHasErrors('status');
        $this->assertSame('llamado', $turn->fresh()->status);
        $this->assertSame(0, Sale::count());
    }

    public function test_arrival_cannot_be_verified_before_the_service_is_due(): void
    {
        $appointment = Appointment::create([
            'barber_id' => $this->barber('citas')->id,
            'service_id' => $this->service->id,
            'client_name' => 'Ana',
            'appointment_date' => '2026-11-18',
            'appointment_time' => '11:00',
            'status' => 'confirmada',
        ]);

        $this->actingAs($this->admin)->patchJson(route('appointments.attendance', $appointment), ['decision' => 'presente'])
            ->assertUnprocessable()->assertJsonValidationErrors('decision');
        $this->assertNull($appointment->fresh()->attendance_status);
    }

    public function test_overdue_appointment_can_only_be_marked_absent(): void
    {
        $appointment = $this->appointment($this->barber('citas'));
        $this->travel(1)->days();

        $this->actingAs($this->admin)->patchJson(route('appointments.attendance', $appointment), ['decision' => 'presente'])
            ->assertUnprocessable()->assertJsonValidationErrors('decision');
        $this->assertNull($appointment->fresh()->attendance_status);

        $this->patchJson(route('appointments.attendance', $appointment), ['decision' => 'ausente'])->assertOk();
        $appointment->refresh();
        $this->assertSame('cancelada', $appointment->status);
        $this->assertSame('ausente', $appointment->attendance_status);
        $this->assertNull($appointment->started_at);
        $this->assertSame(0, Sale::count());
    }

    public function test_no_show_cannot_be_edited_or_reactivated(): void
    {
        $barber = $this->barber('citas');
        $appointment = $this->appointment($barber);
        $this->actingAs($this->admin)->patchJson(route('appointments.attendance', $appointment), ['decision' => 'ausente'])->assertOk();
        $verifiedAt = $appointment->fresh()->attendance_verified_at;

        $this->put(route('appointments.update', $appointment), [
            'barber_id' => $barber->id,
            'service_id' => $this->service->id,
            'client_name' => 'Ana',
            'client_phone' => '3001234567',
            'appointment_date' => '2026-11-18',
            'appointment_time' => '11:00',
            'status' => 'confirmada',
        ])->assertSessionHas('error');
        $this->patch(route('appointments.status', $appointment), ['status' => 'confirmada'])
            ->assertSessionHasErrors('status');

        $appointment->refresh();
        $this->assertSame('cancelada', $appointment->status);
        $this->assertSame('ausente', $appointment->attendance_status);
        $this->assertTrue($appointment->attendance_verified_at->equalTo($verifiedAt));
        $this->assertSame($this->admin->id, $appointment->attendance_verified_by);
        $this->assertSame(0, Sale::count());
    }

    public function test_legacy_attended_appointment_can_register_income_without_attendance_backfill(): void
    {
        $appointment = Appointment::create([
            'barber_id' => $this->barber('citas')->id,
            'service_id' => $this->service->id,
            'client_name' => 'Cita anterior',
            'appointment_date' => '2026-11-17',
            'appointment_time' => '09:00',
            'status' => 'atendida',
            'payment_method' => 'efectivo',
        ]);

        $this->assertNull($appointment->attendance_status);

        $this->actingAs($this->admin)->patch(route('appointments.status', $appointment), [
            'status' => 'atendida',
            'payment_method' => 'efectivo',
        ])->assertSessionHas('success');

        $this->assertNotNull($appointment->fresh()->sale_id);
        $this->assertSame(1, Sale::count());
        $this->assertNull($appointment->fresh()->attendance_status);
    }

    public function test_legacy_attended_turn_can_register_income_without_attendance_backfill(): void
    {
        $turn = Turn::create([
            'barber_id' => $this->barber('llegada')->id,
            'service_id' => $this->service->id,
            'client_name' => 'Turno anterior',
            'turn_date' => '2026-11-17',
            'turn_number' => 1,
            'status' => 'atendido',
            'called_at' => '2026-11-17 09:00:00',
            'started_at' => '2026-11-17 09:10:00',
            'finished_at' => '2026-11-17 09:40:00',
            'payment_method' => 'efectivo',
        ]);

        $this->assertNull($turn->attendance_status);

        $this->actingAs($this->admin)->patch(route('turns.status', $turn), [
            'status' => 'atendido',
            'payment_method' => 'efectivo',
        ])->assertSessionHas('success');

        $this->assertNotNull($turn->fresh()->sale_id);
        $this->assertSame(1, Sale::count());
        $this->assertNull($turn->fresh()->attendance_status);
    }

    public function test_legacy_turn_in_progress_finishes_and_advances_without_attendance_backfill(): void
    {
        $barber = $this->barber('llegada');
        $first = Turn::create([
            'barber_id' => $barber->id,
            'service_id' => $this->service->id,
            'client_name' => 'Servicio en curso',
            'turn_date' => '2026-11-18',
            'turn_number' => 1,
            'status' => 'atendiendo',
            'called_at' => '2026-11-18 09:00:00',
            'started_at' => '2026-11-18 09:30:00',
        ]);
        $second = Turn::create([
            'barber_id' => $barber->id,
            'service_id' => $this->service->id,
            'client_name' => 'Siguiente',
            'turn_date' => '2026-11-18',
            'turn_number' => 2,
            'status' => 'esperando',
        ]);

        app(TurnQueueService::class)->advance($barber->id);

        $this->assertSame('atendido', $first->fresh()->status);
        $this->assertNotNull($first->fresh()->sale_id);
        $this->assertNull($first->fresh()->attendance_status);
        $this->assertSame('llamado', $second->fresh()->status);
        $this->assertSame(1, Sale::count());
    }

    public function test_panel_only_offers_attendance_actions_to_the_admin(): void
    {
        $barber = $this->barber('citas');
        $this->appointment($barber);

        $this->actingAs($this->admin)->get(route('appointments.index', ['date' => '2026-11-18']))
            ->assertOk()->assertSee('Llegó')->assertSee('No llegó');
        $this->actingAs($barber)->get(route('appointments.index', ['date' => '2026-11-18']))
            ->assertOk()->assertDontSee('name="decision"', false);
    }
}
