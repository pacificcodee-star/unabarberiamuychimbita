<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Service;
use App\Models\Turn;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Cita y turno van por caminos separados:
 *  - en el bot, "1. Agendar una cita" solo muestra barberos de cita y "2. Tomar un turno" solo los de turno;
 *  - en el panel, el módulo Turnos solo maneja turnos (las citas viven en el módulo Citas).
 */
class BotCitaTurnoTest extends TestCase
{
    use RefreshDatabase;

    private User $deCita;

    private User $deTurno;

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-11-18 10:00:00')); // miércoles, en horario
        config([
            'services.whatsapp.driver' => 'evolution',
            'services.evolution.url' => 'https://evolution.test',
            'services.evolution.apikey' => 'global-key',
            'services.evolution.webhook_secret' => 'hook',
            'services.evolution.instance' => 'barberia',
            'services.evolution.bot_enabled' => true,
        ]);
        Http::fake(['evolution.test/*' => Http::response(['created' => true])]);

        Service::create(['name' => 'Corte', 'price' => 20000, 'duration_minutes' => 30, 'active' => true]);
        $this->deCita = User::factory()->create(['name' => 'Andres Cita', 'role' => 'barbero', 'attention_mode' => 'citas', 'active' => true]);
        $this->deTurno = User::factory()->create(['name' => 'Carlos Turno', 'role' => 'barbero', 'attention_mode' => 'llegada', 'active' => true]);
    }

    private function escribir(string $text): string
    {
        $before = count(Http::recorded());
        $this->postJson('/webhook/evolution', ['event' => 'messages.upsert', 'apikey' => 'hook', 'data' => [
            'key' => ['remoteJid' => '573001234567@s.whatsapp.net', 'fromMe' => false, 'id' => 'T'.(++$this->n)],
            'pushName' => 'Ana', 'message' => ['conversation' => $text], 'messageTimestamp' => now()->getTimestamp(),
        ]])->assertOk();

        return (string) (Http::recorded()->slice($before)->last()[0]['text'] ?? '');
    }

    public function test_el_menu_ofrece_cita_y_turno_por_separado(): void
    {
        $menu = $this->escribir('hola');

        $this->assertStringContainsString('1. 📅 Agendar una cita', $menu);
        $this->assertStringContainsString('2. 🎟️ Tomar un turno', $menu);
    }

    public function test_agendar_cita_solo_muestra_barberos_de_cita(): void
    {
        $this->escribir('hola');
        $this->escribir('1');            // agendar cita
        $this->escribir('Ana Gomez');    // nombre
        $barberos = $this->escribir('1'); // servicio

        $this->assertStringContainsString('Andres Cita', $barberos);
        $this->assertStringNotContainsString('Carlos Turno', $barberos);
        $this->assertStringNotContainsString('menos espera', $barberos);

        $fechas = $this->escribir('1'); // barbero
        $this->assertStringContainsString('Selecciona la fecha', $fechas);
        $this->assertSame(0, Turn::count());
    }

    public function test_cita_por_el_bot_a_pocos_minutos_se_confirma_sola_con_su_propio_mensaje(): void
    {
        $this->travelTo(Carbon::parse('2026-11-18 10:23:00')); // la próxima hora libre es 10:30 (7 minutos)

        $this->escribir('hola');
        $this->escribir('1');            // agendar cita
        $this->escribir('Miguel Torres'); // nombre
        $this->escribir('1');            // servicio
        $fechas = $this->escribir('1');  // barbero
        $horas = $this->escribir('1');   // hoy
        $this->assertStringContainsString('10:30', $horas);
        $this->escribir('1');            // 10:30
        $listo = $this->escribir('1');   // confirmar la reserva

        $cita = Appointment::firstOrFail();
        $this->assertSame('confirmada', $cita->status);
        $this->assertStringContainsString('en 7 minutos y ya quedó confirmada', $listo);
        $this->assertStringContainsString('no necesitas responder', $listo);
        $this->assertStringNotContainsString('30 minutos antes', $listo);
        $this->assertStringContainsString('Miguel Torres', $listo);
    }

    /** Reserva por el chat a las $now; devuelve [mensaje final, textos de horas]. */
    private function reservarCitaA(string $now): array
    {
        $this->travelTo(Carbon::parse($now));
        $this->escribir('hola');
        $this->escribir('1');
        $this->escribir('Miguel Torres');
        $this->escribir('1');
        $this->escribir('1');
        $horas = $this->escribir('1');
        $this->escribir('1');

        return [$this->escribir('1'), $horas];
    }

    public function test_cita_a_20_minutos_no_promete_otro_aviso_porque_ya_salio(): void
    {
        [$listo, $horas] = $this->reservarCitaA('2026-11-18 10:10:00'); // 10:30 → faltan 20 minutos
        $this->assertStringContainsString('10:30', $horas);

        $this->assertStringContainsString('👤 *Cliente:* Miguel Torres', $listo);
        $this->assertStringNotContainsString('Te enviaremos un recordatorio', $listo);
        $this->assertStringNotContainsString('En un momento te llegará', $listo);
        $this->assertStringNotContainsString('A nombre de', $listo);
        // Un solo mensaje: el cliente acaba de confirmar en el chat, no se le pregunta otra vez con el recordatorio.
        Http::assertNotSent(fn ($request) => str_contains((string) ($request['text'] ?? ''), '¿Deseas mantener tu reserva?'));
    }

    public function test_cita_con_tiempo_si_promete_el_recordatorio_de_30_minutos(): void
    {
        [$listo] = $this->reservarCitaA('2026-11-18 08:00:00'); // primera hora libre: 9:00 (falta 1 hora)
        $cita = Appointment::firstOrFail();

        $this->assertGreaterThan(30, now()->diffInMinutes($cita->startsAt(), false));
        $this->assertStringContainsString('30 minutos antes', $listo);
        $this->assertStringContainsString('👤 *Cliente:* Miguel Torres', $listo);
    }

    public function test_tomar_turno_solo_muestra_barberos_de_turno_y_crea_un_turno(): void
    {
        $this->escribir('hola');
        $this->escribir('2');            // tomar turno
        $this->escribir('Ana Gomez');    // nombre
        $barberos = $this->escribir('1'); // servicio

        $this->assertStringContainsString('Carlos Turno', $barberos);
        $this->assertStringNotContainsString('Andres Cita', $barberos);

        $this->escribir('1'); // barbero

        $this->assertSame(1, Turn::where('barber_id', $this->deTurno->id)->count());
        $this->assertSame(0, Appointment::count());
    }

    public function test_turno_llamado_de_una_vez_no_promete_aviso_de_15_minutos(): void
    {
        // Barbero libre: al tomar el turno la fila lo llama de inmediato ("¡Llegó tu turno!").
        $this->escribir('hola');
        $this->escribir('2');
        $this->escribir('Ana Gomez');
        $this->escribir('1');
        $listo = $this->escribir('1');

        $this->assertSame('llamado', Turn::firstOrFail()->status);
        $this->assertStringNotContainsString('Seguimiento', $listo);
        $this->assertStringNotContainsString('/seguimiento', $listo);
        $this->assertStringContainsString('👤 Cliente: *Ana Gomez*', $listo);
        $this->assertStringNotContainsString('15 minutos antes', $listo);
        $this->assertStringNotContainsString('A nombre de', $listo);
    }

    public function test_turno_en_espera_si_avisa_15_minutos_antes(): void
    {
        // Ya hay alguien en la fila: el turno nuevo queda esperando.
        app(\App\Services\TurnService::class)->createFor($this->deTurno, ['client_name' => 'Primero']);

        $this->escribir('hola');
        $this->escribir('2');
        $this->escribir('Ana Gomez');
        $this->escribir('1');
        $listo = $this->escribir('1');

        $this->assertStringContainsString('👤 Cliente: *Ana Gomez*', $listo);
        $this->assertStringContainsString('15 minutos antes', $listo);
    }

    public function test_cancelar_busca_la_reserva_por_nombre_aunque_se_hiciera_con_otro_telefono(): void
    {
        // Reservada en la página con OTRO teléfono: por número no se encontraría.
        $cita = Appointment::create([
            'barber_id' => $this->deCita->id, 'client_name' => 'María José Gómez', 'client_phone' => '310 555 4321',
            'appointment_date' => now()->addDay()->toDateString(), 'appointment_time' => '15:00:00', 'status' => 'confirmada',
        ]);

        $this->escribir('hola');
        $pide = $this->escribir('7'); // cancelar una reserva
        $this->assertStringContainsString('1. 📅 Mi cita', $pide);

        $this->assertStringContainsString('nombre y apellido', $this->escribir('1'));

        // Nombre sin tildes ni mayúsculas: igual la encuentra, pero como el teléfono no es el de este
        // WhatsApp, pide los 4 últimos dígitos del teléfono de la reserva.
        $this->assertStringContainsString('4 últimos dígitos', $this->escribir('maria gomez'));
        $this->assertStringContainsString('no coinciden', $this->escribir('9999'));
        $this->assertStringContainsString('Confirmas que deseas cancelar', $this->escribir('4321'));

        $this->escribir('1');
        $this->assertSame('cancelada', $cita->fresh()->status);
    }

    public function test_cancelar_con_varias_reservas_deja_elegir_cual(): void
    {
        $turno = app(\App\Services\TurnService::class)->createFor($this->deTurno, [
            'client_name' => 'Pedro Pérez', 'client_phone' => '3001234567',
        ]);
        $cita = Appointment::create([
            'barber_id' => $this->deCita->id, 'client_name' => 'Pedro Perez', 'client_phone' => '3001234567',
            'appointment_date' => now()->addDays(2)->toDateString(), 'appointment_time' => '11:00:00', 'status' => 'confirmada',
        ]);

        $this->escribir('cancelar');
        $lista = $this->escribir('Pedro Pérez');
        $this->assertStringContainsString('varias reservas', $lista);
        $this->assertStringContainsString('Turno', $lista);
        $this->assertStringContainsString('Cita', $lista);

        // Mismo teléfono que este WhatsApp: no pide dígitos, va directo a confirmar.
        $this->assertStringContainsString('Confirmas que deseas cancelar', $this->escribir('1'));
        $this->escribir('1');

        $this->assertSame('cancelado', $turno->fresh()->status);
        $this->assertSame('confirmada', $cita->fresh()->status);
    }

    public function test_las_reservas_de_dias_anteriores_no_se_pueden_cancelar(): void
    {
        Appointment::create([
            'barber_id' => $this->deCita->id, 'client_name' => 'Rosa Díaz', 'client_phone' => '3001234567',
            'appointment_date' => now()->subDay()->toDateString(), 'appointment_time' => '15:00:00', 'status' => 'confirmada',
        ]);
        Turn::create([
            'barber_id' => $this->deTurno->id, 'client_name' => 'Rosa Díaz', 'client_phone' => '3001234567',
            'turn_date' => now()->subDay()->toDateString(), 'turn_number' => 1, 'status' => 'esperando',
        ]);

        $this->escribir('cancelar');
        $this->assertStringContainsString('No encontré', $this->escribir('Rosa Diaz'));
    }

    public function test_cancelar_avisa_si_no_hay_reservas_a_ese_nombre(): void
    {
        $this->escribir('cancelar');
        $this->assertStringContainsString('No encontré', $this->escribir('Nadie Existe'));
    }

    public function test_el_modulo_de_turnos_no_muestra_citas_ni_deja_crearlas(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'active' => true]);
        Appointment::create([
            'barber_id' => $this->deCita->id, 'client_name' => 'Cliente De Cita', 'client_phone' => '3000000000',
            'appointment_date' => now()->toDateString(), 'appointment_time' => '11:00:00', 'status' => 'confirmada',
        ]);

        $page = $this->actingAs($admin)->get(route('turns.index'))->assertOk();
        $page->assertDontSee('Cliente De Cita');
        $page->assertDontSee('Andres Cita'); // el barbero de citas no aparece para dar turnos
        $page->assertSee('Carlos Turno');

        // Un turno con un barbero de citas se rechaza.
        $this->actingAs($admin)->post(route('turns.store'), [
            'barber_id' => $this->deCita->id, 'client_name' => 'Luis',
        ])->assertSessionHasErrors('barber_id');

        $this->actingAs($admin)->post(route('turns.store'), [
            'barber_id' => $this->deTurno->id, 'client_name' => 'Luis',
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, Turn::count());
        $this->assertSame(1, Appointment::count()); // no se creó ninguna cita desde Turnos
    }
}
