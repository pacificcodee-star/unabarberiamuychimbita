<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\ClientNotification;
use App\Models\ConsentRecord;
use App\Models\Service;
use App\Models\SiteSetting;
use App\Models\StaffNotification;
use App\Models\Turn;
use App\Models\User;
use App\Services\TurnQueueService;
use App\Services\WhatsApp\WhatsappBotService;
use App\Support\ClientServiceAccess;
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

    private function escribir(string $text, string $jid = '573001234567@s.whatsapp.net'): string
    {
        $before = count(Http::recorded());
        $this->postJson('/webhook/evolution', ['event' => 'messages.upsert', 'apikey' => 'hook', 'data' => [
            'key' => ['remoteJid' => $jid, 'fromMe' => false, 'id' => 'T'.(++$this->n)],
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

    public function test_confirmar_en_whatsapp_actualiza_la_cita_de_la_pwa_y_la_pasa_al_historial(): void
    {
        $booking = $this->post(route('public.booking.appointment'), [
            'barber_id' => $this->deCita->id,
            'service_id' => Service::firstOrFail()->id,
            'client_name' => 'Ana desde la PWA',
            'client_phone' => '300 123 4567',
            'payment_method' => 'efectivo',
            'whatsapp_consent' => 1,
            'appointment_date' => '2026-11-19',
            'appointment_time' => '10:00',
        ])->assertRedirect(route('my-services.index'));
        $this->withCookie('mis_servicios', $booking->getCookie('mis_servicios')->getValue());
        $this->get('/mis-servicios')->assertSee('Pendiente')->assertSee('data-action="confirmar"', false);

        $this->assertStringContainsString('quedó *confirmada*', $this->escribir('sí'));
        $this->assertNotNull(Appointment::firstOrFail()->attendance_confirmed_at);
        $this->get('/mis-servicios', ['X-Service-List' => '1'])->assertOk()
            ->assertSee('Confirmada')->assertDontSee('data-action="confirmar"', false);

        $this->flushSession();
        $this->get('/?source=pwa')->assertSee('Mis servicios');
        $this->get('/mis-servicios')->assertSee('Ana desde la PWA')->assertSee('Confirmada');

        $this->travelTo(Carbon::parse('2026-11-19 10:00:00'));
        app(\App\Services\ServiceArrival::class)->decide(Appointment::sole(), true);
        $this->travelTo(Carbon::parse('2026-11-19 10:31:00'));
        $this->assertSame(1, app(\App\Services\AttendanceAutomation::class)->finishConfirmedAppointments());
        $this->get('/mis-servicios')->assertSeeInOrder(['Historial', 'Citas anteriores', 'Atendida', 'Ana desde la PWA']);
    }

    public function test_historial_de_whatsapp_disponible_solo_en_dispositivos_vinculados(): void
    {
        $service = Service::firstOrFail();
        $web = $this->post(route('public.booking.appointment'), [
            'barber_id' => $this->deCita->id, 'service_id' => $service->id,
            'client_name' => 'Reserva de la PWA', 'client_phone' => '300 123 4567',
            'payment_method' => 'efectivo', 'appointment_date' => '2026-11-19', 'appointment_time' => '10:00',
        ])->assertRedirect(route('my-services.index'));
        $original = $web->getCookie('mis_servicios')->getValue();
        $this->withCookie('mis_servicios', $original);

        $past = ['barber_id' => $this->deCita->id, 'service_id' => $service->id,
            'client_phone' => '573001234567', 'appointment_date' => '2026-11-17', 'status' => 'atendida'];
        $legacy = Appointment::create($past + ['client_name' => 'Cita antigua del bot', 'appointment_time' => '10:00']);
        ConsentRecord::grant($legacy, 'tratamiento_reserva', 'whatsapp_bot');
        Appointment::create($past + ['client_name' => 'Cita ajena sin vincular', 'appointment_time' => '11:00']);
        $this->get('/')->assertDontSee('historial-whatsapp', false);
        $this->get('/mis-servicios')->assertDontSee('Cita antigua del bot')->assertSee('Reserva de la PWA')
            ->assertDontSee('Abrir WhatsApp')->assertDontSee('Actualizar mi historial')
            ->assertDontSee('Envía el mensaje preparado')->assertDontSee('Vincular historial de WhatsApp');

        $confirm = route('my-services.whatsapp.confirm');
        $finish = route('my-services.whatsapp.finish');
        $this->get($confirm)->assertNotFound();
        $this->post($finish)->assertNotFound();
        $link = $this->enlaceDeHistorial();
        $this->get($link)->assertRedirect($confirm);
        $this->get($confirm)->assertOk()->assertSee('Vincular este dispositivo')->assertSee('4567')->assertDontSee('573001234567');
        $this->assertDatabaseCount('guest_credentials', 1);
        $this->get('/mis-servicios')->assertDontSee('Cita antigua del bot');
        $linked = $this->post($finish)->assertRedirect(route('my-services.index'));
        $this->get($link)->assertNotFound();
        $this->withCookie('mis_servicios', $linked->getCookie('mis_servicios')->getValue());
        $this->get('/mis-servicios')->assertSee('Cita antigua del bot')->assertDontSee('Cita ajena sin vincular')
            ->assertDontSee('Actualizar mi historial');

        $this->escribir(WhatsappBotService::TURN_REQUEST);
        $this->escribir('Ana turno WhatsApp');
        $this->escribir('1');
        $this->escribir('1');
        $this->escribir('sí');
        $turn = Turn::firstOrFail();
        $this->assertSame('573001234567', $turn->history_whatsapp_phone);
        $turn->update(['status' => 'atendido']);
        $this->flushSession();
        $this->get('/mis-servicios')->assertSee('Cita antigua del bot')->assertSee('Ana turno WhatsApp')->assertSee('Turnos anteriores');

        $this->withCookie('mis_servicios', $original);
        $this->get('/mis-servicios')->assertDontSee('Cita antigua del bot')->assertSee('Reserva de la PWA');
        $this->withCookie('mis_servicios', '');
        $this->get('/mis-servicios')->assertDontSee('Cita antigua del bot')->assertDontSee('Reserva de la PWA');

        $this->get($this->enlaceDeHistorial())->assertRedirect($confirm);
        $other = $this->post($finish)->assertRedirect(route('my-services.index'));
        $this->withCookie('mis_servicios', $other->getCookie('mis_servicios')->getValue());
        $this->get('/mis-servicios')->assertSee('Cita antigua del bot')->assertSee('Reserva de la PWA')
            ->assertSee('Ana turno WhatsApp')->assertDontSee('Cita ajena sin vincular');
    }

    public function test_primer_ingreso_desde_el_formulario_recupera_citas_y_turnos_del_bot(): void
    {
        $this->reservarCitaA('2026-11-18 10:10:00');
        foreach ([WhatsappBotService::TURN_REQUEST, 'Ana turno WhatsApp', '1', '1', 'sí'] as $message) {
            $this->escribir($message, '573001234567:7@s.whatsapp.net');
        }
        $cita = Appointment::sole();
        $turno = Turn::sole();
        $this->assertSame($cita->history_whatsapp_phone, $turno->history_whatsapp_phone);
        $this->assertEquals(20000, $cita->service_price);
        $this->assertEquals(20000, $turno->service_price);

        $confirm = route('my-services.whatsapp.confirm');
        $finish = route('my-services.whatsapp.finish');
        $this->get(route('public.booking'))->assertSee('data-history-phone="appointmentPhone"', false)
            ->assertSee('data-history-phone="turnPhone"', false);
        $this->post($finish, ['intent' => 'lookup', 'client_phone' => '(300) 123-4567'])->assertRedirect($confirm);
        $screen = $this->get($confirm)->assertOk()->assertSee('4567');
        parse_str(parse_url($screen->viewData('chatUrl'), PHP_URL_QUERY), $params);
        $this->escribir($params['text'], '13001234567@s.whatsapp.net');
        $this->post($finish)->assertSessionHasErrors('whatsapp')->assertCookieMissing('mis_servicios');
        $this->get('/mis-servicios')->assertDontSee('Miguel Torres')->assertDontSee('Ana turno WhatsApp');

        $this->escribir($params['text']);
        $linked = $this->post($finish)->assertRedirect(route('my-services.index'));
        $this->withCookie('mis_servicios', $linked->getCookie('mis_servicios')->getValue());
        $this->get('/mis-servicios')->assertViewHas('activeCitas', fn ($rows) => $rows->contains($cita))
            ->assertViewHas('activeTurns', fn ($rows) => $rows->contains($turno));
        $cita->update(['status' => 'atendida']);
        $turno->update(['status' => 'atendido']);
        $this->get('/mis-servicios', ['X-Service-List' => '1'])
            ->assertViewHas('historyAppointments', fn ($rows) => $rows->contains($cita))
            ->assertViewHas('historyTurns', fn ($rows) => $rows->contains($turno))
            ->assertViewHas('hasActive', false);
        $this->post($finish, ['intent' => 'lookup', 'client_phone' => '+57 300 123 4567'])
            ->assertRedirect(route('my-services.index'));
        $this->assertDatabaseCount('appointments', 1);
        $this->assertDatabaseCount('turns', 1);
    }

    public function test_la_consulta_desde_la_pwa_vence_y_respeta_el_indicativo_internacional(): void
    {
        $confirm = route('my-services.whatsapp.confirm');
        $finish = route('my-services.whatsapp.finish');
        $lookup = ['intent' => 'lookup', 'client_phone' => '+45 3212 4567'];
        $this->post($finish, $lookup)->assertRedirect($confirm);
        parse_str(parse_url($this->get($confirm)->viewData('chatUrl'), PHP_URL_QUERY), $params);
        $this->escribir($params['text'], '574532124567@s.whatsapp.net');
        $this->post($finish)->assertSessionHasErrors('whatsapp')->assertCookieMissing('mis_servicios');
        $this->travel(11)->minutes();
        $this->post($finish)->assertRedirect(route('public.booking'))->assertCookieMissing('mis_servicios');
        $this->post($finish, $lookup)->assertRedirect($confirm);
        parse_str(parse_url($this->get($confirm)->viewData('chatUrl'), PHP_URL_QUERY), $new);
        $this->escribir($params['text'], '4532124567@s.whatsapp.net');
        $this->post($finish)->assertSessionHasErrors('whatsapp')->assertCookieMissing('mis_servicios');
        $this->escribir($new['text'], '4532124567@s.whatsapp.net');
        $this->post($finish)->assertRedirect(route('my-services.index'))->assertCookie('mis_servicios');
        $this->assertDatabaseHas('guest_credentials', ['whatsapp_phone' => '4532124567']);
    }

    public function test_migracion_recupera_reservas_antiguas_solo_con_evidencia_del_bot(): void
    {
        $base = ['barber_id' => $this->deCita->id, 'client_phone' => '(300) 123-4567',
            'client_name' => 'Ana', 'appointment_date' => '2026-11-16', 'status' => 'atendida'];
        $old = Appointment::create($base + ['appointment_time' => '10:00']);
        $unverified = Appointment::create($base + ['appointment_time' => '11:00']);
        $turn = Turn::create(['barber_id' => $this->deTurno->id, 'client_phone' => '+1 300 123 4567',
            'client_name' => 'Otra persona', 'turn_date' => '2026-11-16', 'turn_number' => 1, 'status' => 'atendido']);
        ConsentRecord::grant($old, 'tratamiento_reserva', 'whatsapp_bot');
        ConsentRecord::grant($turn, 'tratamiento_reserva', 'whatsapp_bot');
        $migration = require database_path('migrations/2026_11_26_000005_normalize_bot_history_phones.php');
        $migration->up();
        $migration->up();
        $this->assertSame('573001234567', $old->fresh()->history_whatsapp_phone);
        $this->assertSame('13001234567', $turn->fresh()->history_whatsapp_phone);
        $this->assertNull($unverified->fresh()->history_whatsapp_phone);
    }

    public function test_el_enlace_privado_del_historial_vence_sin_vincular_el_dispositivo(): void
    {
        $link = $this->enlaceDeHistorial();
        $this->get($link)->assertRedirect(route('my-services.whatsapp.confirm'));
        $this->travel(11)->minutes();

        $this->get($link)->assertNotFound();
        $this->post(route('my-services.whatsapp.finish'))->assertNotFound()->assertCookieMissing('mis_servicios');
    }

    private function enlaceDeHistorial(): string
    {
        $reply = $this->escribir('Vincular historial');
        $this->assertStringContainsString('enlace privado', $reply);
        $this->assertSame(1, preg_match('~https?://\S+/whatsapp/conectar/[A-Za-z0-9]{48}$~', $reply, $link));

        return $link[0];
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
        $this->assertSame('573001234567', $cita->history_whatsapp_phone);
        $this->assertNotNull($cita->attendance_confirmed_at);
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

        $preview = $this->escribir('1'); // barbero
        $this->assertStringContainsString('Responde *SÍ* o *1*', $preview);
        $this->assertDatabaseCount('turns', 0);
        $this->assertDatabaseMissing('staff_notifications', ['type' => 'turn_new']);

        $this->escribir('1'); // confirmar
        $this->assertSame(1, Turn::where('barber_id', $this->deTurno->id)->count());
        $this->assertSame(0, Appointment::count());
    }

    public function test_turnos_de_la_web_y_whatsapp_comparten_cola_y_avisan_al_confirmar_y_al_llamar(): void
    {
        $this->post(route('public.booking.turn'), [
            'barber_id' => $this->deTurno->id,
            'service_id' => Service::firstOrFail()->id,
            'client_name' => 'Cliente de la web',
            'client_phone' => '310 123 4567',
            'payment_method' => 'efectivo',
        ])->assertRedirect(route('my-services.index'));

        $this->assertStringContainsString('tomar tu turno', $this->escribir(WhatsappBotService::TURN_REQUEST));
        $this->escribir('Ana Gomez');
        $this->escribir('1');
        $this->escribir('1');
        $this->assertDatabaseCount('turns', 1);
        $this->escribir('sí');

        $turn = Turn::where('client_name', 'Ana Gomez')->firstOrFail();
        $this->assertSame(2, $turn->turn_number);
        $this->assertSame('esperando', $turn->status);
        $this->assertDatabaseHas('staff_notifications', [
            'type' => 'turn_new',
            'title' => 'Nuevo turno #2 confirmado (WhatsApp): Ana Gomez',
            'url' => route('turns.index', [], false),
        ]);
        Http::assertSent(fn ($request) => str_contains((string) $request['text'], 'quedó registrado correctamente')
            && ! str_contains((string) $request['text'], 'http'));

        $this->escribir('sí'); // no duplica el turno ni el aviso al equipo
        $this->assertDatabaseCount('turns', 2);
        $this->assertSame(1, StaffNotification::where('type', 'turn_new')->where('title', 'like', '%(WhatsApp)%')->count());

        Turn::where('turn_number', 1)->firstOrFail()->update([
            'status' => 'atendiendo',
            'started_at' => now()->subMinutes(30),
        ]);
        app(TurnQueueService::class)->advance($this->deTurno->id);

        $this->assertSame('llamado', $turn->fresh()->status);
        $this->assertDatabaseHas('client_notifications', [
            'notifiable_id' => $turn->id, 'type' => 'turn_called', 'channel' => 'whatsapp', 'status' => ClientNotification::SENT,
        ]);
        Http::assertSent(fn ($request) => $request['number'] === '573001234567'
            && str_contains((string) $request['text'], '¡Llegó tu turno!'));
    }

    public function test_el_bot_puede_consultar_en_el_mismo_numero_un_turno_hecho_en_la_web(): void
    {
        $this->post(route('public.booking.turn'), [
            'barber_id' => $this->deTurno->id,
            'service_id' => Service::firstOrFail()->id,
            'client_name' => 'Ana Gomez',
            'client_phone' => '300 123 4567',
            'payment_method' => 'efectivo',
        ])->assertRedirect(route('my-services.index'));

        $turn = Turn::firstOrFail();
        $reply = $this->escribir('mi turno');

        $this->assertStringContainsString('Estado: *Llamado*', $reply);
        $this->assertStringContainsString(ClientServiceAccess::recoveryUrl($turn), $reply);
        $this->assertDatabaseCount('turns', 1);
    }

    public function test_la_web_abre_whatsapp_con_los_comandos_para_turno_y_asesor(): void
    {
        SiteSetting::create(['data' => ['whatsapp' => '573006789012']]);
        $url = 'https://wa.me/573006789012?text=';
        $advisor = $url.rawurlencode(WhatsappBotService::ADVISOR_REQUEST);

        $this->get(route('public.booking', ['tipo' => 'turno']))->assertOk()
            ->assertSee($url.rawurlencode(WhatsappBotService::TURN_REQUEST))
            ->assertSee($advisor);
        $this->get(route('public.contact'))->assertOk()->assertSee($advisor);
    }

    public function test_turno_con_barbero_libre_recibe_confirmacion_antes_del_llamado(): void
    {
        // Barbero libre: al tomar el turno la fila lo llama de inmediato ("¡Llegó tu turno!").
        $this->escribir('hola');
        $this->escribir('2');
        $this->escribir('Ana Gomez');
        $this->escribir('1');
        $this->escribir('1');
        $before = count(Http::recorded());
        $this->escribir('1');

        $this->assertSame('llamado', Turn::firstOrFail()->status);
        $messages = Http::recorded()->slice($before)->map(fn ($pair) => (string) $pair[0]['text'])->values();
        $this->assertStringContainsString('👤 Cliente: *Ana Gomez*', $messages[0]);
        $this->assertStringNotContainsString('http', $messages[0]);
        $this->assertStringContainsString('¡Llegó tu turno!', $messages[1]);
    }

    public function test_turno_no_se_guarda_si_el_horario_vence_antes_de_confirmar(): void
    {
        $this->travelTo(Carbon::parse('2026-11-18 19:29:00'));
        $this->escribir('hola');
        $this->escribir('2');
        $this->escribir('Ana Gomez');
        $this->escribir('1');
        $this->escribir('1');

        $this->travelTo(Carbon::parse('2026-11-18 19:31:00'));
        $this->assertStringContainsString('se reciben hasta las 7:30 p. m.', $this->escribir('sí'));
        $this->assertDatabaseCount('turns', 0);
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
