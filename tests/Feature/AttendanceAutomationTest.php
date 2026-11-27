<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\ClientNotification;
use App\Models\Service;
use App\Models\Turn;
use App\Models\User;
use App\Services\AttendanceAutomation;
use App\Services\ClientNotifier;
use App\Services\TurnService;
use App\Support\AttendanceConfirmation;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Reglas automáticas de asistencia:
 *  - sin confirmar 15 min después del recordatorio por WhatsApp → se cancela y se avisa;
 *  - cita confirmada espera verificación de llegada; al terminar desde la llegada real → atendida + gracias;
 *  - cita agendada faltando menos de 15 min → confirmada sola;
 *  - turno finalizado solo → mensaje de gracias.
 */
class AttendanceAutomationTest extends TestCase
{
    use RefreshDatabase;

    private User $barbero;

    private Service $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-11-18 10:00:00')); // miércoles, en horario
        config([
            'services.whatsapp.driver' => 'evolution',
            'services.evolution.url' => 'https://evolution.test',
            'services.evolution.apikey' => 'global-key',
            'services.evolution.instance' => 'barberia',
        ]);
        Http::fake(['evolution.test/*' => Http::response(['key' => ['id' => 'X']])]);

        $this->barbero = User::factory()->create(['role' => 'barbero', 'attention_mode' => 'citas', 'active' => true]);
        $this->service = Service::create(['name' => 'Corte', 'price' => 20000, 'duration_minutes' => 30, 'active' => true]);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'active' => true]);
    }

    private function cita(string $time, string $status = 'pendiente'): Appointment
    {
        return Appointment::create([
            'barber_id' => $this->barbero->id,
            'service_id' => $this->service->id,
            'client_name' => 'Ana Gómez',
            'client_phone' => '3001234567',
            'appointment_date' => now()->toDateString(),
            'appointment_time' => $time,
            'status' => $status,
            'whatsapp_consent' => true,
        ]);
    }

    /** Simula que el recordatorio ya salió por WhatsApp hace $minutes minutos. */
    private function recordatorioEnviado($subject, string $type, int $minutes): void
    {
        ClientNotification::create([
            'notifiable_type' => $subject->getMorphClass(),
            'notifiable_id' => $subject->getKey(),
            'type' => $type,
            'channel' => 'whatsapp',
            'title' => 'Recordatorio',
            'body' => '¿Confirmas?',
            'dedupe_key' => $subject->notificationKey().':'.$type.':prueba:whatsapp',
            'scheduled_for' => now()->subMinutes($minutes),
            'expires_at' => now()->addHour(),
            'status' => ClientNotification::SENT,
            'attempts' => 1,
            'sent_at' => now()->subMinutes($minutes),
        ]);
    }

    private function automatizar(): array
    {
        return app(AttendanceAutomation::class)->run();
    }

    public function test_cita_sin_confirmar_15_minutos_despues_del_recordatorio_se_cancela_y_se_avisa(): void
    {
        $cita = $this->cita('10:30:00');
        $this->recordatorioEnviado($cita, 'appt_reminder_30', 10);

        $this->automatizar();
        $this->assertSame('pendiente', $cita->fresh()->status); // aún dentro de los 15 minutos

        $this->travel(6)->minutes();
        $this->automatizar();

        $this->assertSame('cancelada', $cita->fresh()->status);
        $this->assertTrue(ClientNotification::where('notifiable_id', $cita->id)
            ->where('type', 'appt_cancelled')->where('dedupe_key', 'like', '%sin-confirmar%')->exists());
        $this->assertDatabaseHas('staff_notifications', ['title' => 'Cita cancelada: el cliente no confirmó']);
    }

    public function test_cita_confirmada_espera_al_administrador_y_termina_desde_la_llegada_real(): void
    {
        $cita = $this->cita('10:30:00');
        $this->recordatorioEnviado($cita, 'appt_reminder_30', 20);
        $cita->update(['status' => 'confirmada']);
        AttendanceConfirmation::mark($cita); // el cliente respondió "1. Confirmar"

        $this->automatizar();
        $this->assertSame('confirmada', $cita->fresh()->status);

        $this->travelTo(Carbon::parse('2026-11-18 11:00:00'));
        $this->automatizar();

        $cita->refresh();
        $this->assertSame('confirmada', $cita->status);
        $this->assertNull($cita->attendance_status);
        $this->assertNull($cita->sale_id);
        $this->assertSame(1, \App\Models\StaffNotification::where('type', 'attendance_check')->count());

        $this->actingAs($this->admin())->patch(route('appointments.attendance', $cita), ['decision' => 'presente'])->assertRedirect();
        $this->travel(30)->minutes();
        $this->automatizar();

        $cita->refresh();
        $this->assertSame('atendida', $cita->status);
        $this->assertNotNull($cita->sale_id);
        $this->assertTrue(ClientNotification::where('notifiable_id', $cita->id)->where('type', 'service_thanks')->exists());
    }

    public function test_cita_confirmada_por_el_panel_pero_no_por_el_cliente_no_se_marca_sola(): void
    {
        $cita = $this->cita('09:00:00', 'confirmada'); // sin recordatorio ni confirmación del cliente
        $this->travelTo(Carbon::parse('2026-11-18 12:00:00'));

        $this->automatizar();

        $this->assertSame('confirmada', $cita->fresh()->status);
        $this->assertNull($cita->fresh()->attendance_status);
        $this->assertNull($cita->fresh()->sale_id);
        $this->assertSame(1, \App\Models\StaffNotification::where('type', 'attendance_check')->count());
    }

    public function test_cita_agendada_faltando_menos_de_15_minutos_se_confirma_sola(): void
    {
        $cita = $this->cita('10:10:00');
        app(ClientNotifier::class)->appointmentBooked($cita);

        $this->assertSame('confirmada', $cita->fresh()->status);
        $this->assertTrue(AttendanceConfirmation::confirmed($cita));
        $this->assertFalse(ClientNotification::where('notifiable_id', $cita->id)->where('type', 'appt_reminder_30')->exists());
    }

    public function test_cita_agendada_faltando_exactamente_15_minutos_se_confirma_sola(): void
    {
        $cita = $this->cita('10:15:00');
        app(ClientNotifier::class)->appointmentBooked($cita);

        $this->assertSame('confirmada', $cita->fresh()->status);
        $this->assertTrue(AttendanceConfirmation::confirmed($cita));
    }

    public function test_cita_agendada_faltando_7_o_14_minutos_recibe_el_mensaje_de_confirmada_sola(): void
    {
        foreach (['10:07:00' => '7 minutos', '10:14:00' => '14 minutos'] as $time => $text) {
            $cita = $this->cita($time);
            app(ClientNotifier::class)->appointmentBooked($cita);

            $this->assertSame('confirmada', $cita->fresh()->status);
            $aviso = ClientNotification::where('notifiable_id', $cita->id)->where('type', 'appt_booked')->where('channel', 'whatsapp')->first();
            $this->assertStringContainsString('en '.$text, $aviso->body);
            $this->assertStringContainsString('confirmada automáticamente', $aviso->body);
            $this->assertStringNotContainsString('recordatorio 30 minutos', $aviso->body);
        }
    }

    public function test_cita_agendada_faltando_16_minutos_no_se_confirma_sola(): void
    {
        $cita = $this->cita('10:16:00');
        app(ClientNotifier::class)->appointmentBooked($cita);

        $this->assertSame('pendiente', $cita->fresh()->status);
        $this->assertFalse(AttendanceConfirmation::confirmed($cita));
    }

    public function test_cita_agendada_por_el_bot_no_se_atiende_sin_verificar_llegada(): void
    {
        $cita = $this->cita('10:15:00', 'confirmada'); // así la crea el bot
        app(ClientNotifier::class)->autoConfirmIfSoon($cita);

        $this->assertTrue(AttendanceConfirmation::confirmed($cita));

        $this->travelTo(Carbon::parse('2026-11-18 10:45:00'));
        $this->automatizar();
        $this->assertSame('confirmada', $cita->fresh()->status);
        $this->assertNull($cita->fresh()->attendance_status);
        $this->assertNull($cita->fresh()->sale_id);
    }

    public function test_cita_agendada_faltando_20_minutos_recibe_un_solo_mensaje_y_corre_el_plazo(): void
    {
        $cita = $this->cita('10:20:00');
        $notifier = app(ClientNotifier::class);
        $notifier->appointmentBooked($cita);
        $notifier->flush();

        $this->assertSame('pendiente', $cita->fresh()->status);
        // El recordatorio no sale aparte (el mensaje de la reserva ya pide responder SÍ): queda como enviado.
        $reminder = ClientNotification::where('notifiable_id', $cita->id)->where('type', 'appt_reminder_30')->where('channel', 'whatsapp')->first();
        $this->assertNotNull($reminder);
        $this->assertSame(ClientNotification::SENT, $reminder->status);
        $this->assertStringContainsString('no se mandó aparte', (string) $reminder->last_error);
        $sentNow = ClientNotification::where('notifiable_id', $cita->id)->where('channel', 'whatsapp')->whereNull('last_error')->where('status', ClientNotification::SENT)->pluck('type')->all();
        $this->assertNotContains('appt_reminder_30', $sentNow);

        // Si no confirma, a los 15 minutos se cancela como siempre.
        $this->travel(16)->minutes();
        $this->automatizar();
        $this->assertSame('cancelada', $cita->fresh()->status);
    }

    public function test_turno_sin_confirmar_15_minutos_despues_del_recordatorio_se_anula_y_se_avisa(): void
    {
        $deTurno = User::factory()->create(['role' => 'barbero', 'attention_mode' => 'llegada', 'active' => true]);
        $turnos = app(TurnService::class);
        $turnos->createFor($deTurno, ['client_name' => 'Primero', 'service_id' => $this->service->id]);
        $turno = $turnos->createFor($deTurno, [
            'client_name' => 'Luis Pérez', 'client_phone' => '3009998888', 'whatsapp_consent' => true, 'service_id' => $this->service->id,
        ]);
        $this->recordatorioEnviado($turno, 'turn_reminder', 16);

        $this->automatizar();

        $this->assertSame('cancelado', $turno->fresh()->status);
        $this->assertTrue(ClientNotification::where('notifiable_id', $turno->id)
            ->where('type', 'turn_cancelled')->where('dedupe_key', 'like', '%sin-confirmar%')->exists());
    }

    public function test_turno_confirmado_no_se_anula(): void
    {
        $deTurno = User::factory()->create(['role' => 'barbero', 'attention_mode' => 'llegada', 'active' => true]);
        $turnos = app(TurnService::class);
        $turnos->createFor($deTurno, ['client_name' => 'Primero', 'service_id' => $this->service->id]);
        $turno = $turnos->createFor($deTurno, ['client_name' => 'Luis Pérez', 'client_phone' => '3009998888', 'whatsapp_consent' => true]);
        $this->recordatorioEnviado($turno, 'turn_reminder', 20);
        AttendanceConfirmation::mark($turno);

        $this->automatizar();

        $this->assertSame('esperando', $turno->fresh()->status);
    }

    public function test_turno_que_termina_solo_envia_gracias(): void
    {
        $deTurno = User::factory()->create(['role' => 'barbero', 'attention_mode' => 'llegada', 'active' => true]);
        $turno = app(TurnService::class)->createFor($deTurno, [
            'client_name' => 'Luis Pérez', 'client_phone' => '3009998888', 'whatsapp_consent' => true, 'service_id' => $this->service->id,
        ]);

        $this->artisan('barberia:tick');
        $this->travel(11)->minutes();
        $this->artisan('barberia:tick');
        $this->assertSame('llamado', $turno->fresh()->status);
        $this->assertNull($turno->fresh()->started_at);
        $this->assertNull($turno->fresh()->sale_id);

        $this->actingAs($this->admin())->patch(route('turns.attendance', $turno), ['decision' => 'presente'])->assertRedirect();
        $this->travel(31)->minutes();
        $this->artisan('barberia:tick');

        $this->assertSame('atendido', $turno->fresh()->status);
        $this->assertNotNull($turno->fresh()->sale_id);
        $this->assertTrue(ClientNotification::where('notifiable_id', $turno->id)->where('type', 'service_thanks')->exists());
    }
}
