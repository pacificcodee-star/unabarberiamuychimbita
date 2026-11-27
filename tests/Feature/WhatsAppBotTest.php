<?php

namespace Tests\Feature;

use App\Jobs\ProcessWhatsAppMessage;
use App\Models\Appointment;
use App\Models\Service;
use App\Models\SiteSetting;
use App\Models\Turn;
use App\Models\User;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use App\Services\ClientNotifier;
use App\Services\TurnService;
use App\Services\WhatsApp\BookingMessenger;
use App\Services\WhatsApp\WhatsappBotService;
use App\Services\WhatsApp\WhatsAppInbox;
use App\Services\WhatsAppService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Pruebas de la integración con Evolution API: el webhook entra, el bot actúa
 * sobre la agenda y todo lo que se envía pasa por Evolution API (sin salir a internet).
 */
class WhatsAppBotTest extends TestCase
{
    use RefreshDatabase;

    private const APIKEY = 'hook-secret-123';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-11-18 10:00:00')); // miércoles

        config([
            'services.whatsapp.driver' => 'evolution',
            'services.evolution.url' => 'https://evolution.test',
            'services.evolution.apikey' => 'global-key',
            'services.evolution.webhook_secret' => self::APIKEY,
            'services.evolution.instance' => 'barberia',
            'services.evolution.bot_enabled' => true,
        ]);

        Http::fake(['evolution.test/*' => Http::response(['created' => true])]);
    }

    // ------------------------------------------------------------------------------------------
    // Casos
    // ------------------------------------------------------------------------------------------

    public function test_webhook_rechazado_sin_apikey(): void
    {
        $this->postJson('/webhook/evolution', ['event' => 'messages.upsert', 'data' => []])
            ->assertStatus(401);

        $this->postJson('/webhook/evolution', ['event' => 'messages.upsert', 'apikey' => 'otra', 'data' => []])
            ->assertStatus(401);

        Http::assertNothingSent();
    }

    public function test_vps_encola_una_sola_respuesta_y_confirma_recepcion_sin_esperar_whatsapp(): void
    {
        config(['services.evolution.queue_incoming' => true]);
        Queue::fake([ProcessWhatsAppMessage::class]);

        $this->escribir('hola', '573001234567@s.whatsapp.net', ['id' => 'ENTRANTE-COLA'])->assertOk();
        $this->escribir('hola', '573001234567@s.whatsapp.net', ['id' => 'ENTRANTE-COLA'])->assertOk();

        Queue::assertPushed(ProcessWhatsAppMessage::class, 1);
        Http::assertNothingSent();
        $this->assertDatabaseHas('whatsapp_messages', ['evolution_id' => 'ENTRANTE-COLA', 'direction' => 'in']);
    }

    public function test_reanuda_a_los_30_minutos_sin_respuesta_y_no_envia_el_menu_dos_veces(): void
    {
        $this->escribir(WhatsappBotService::ADVISOR_REQUEST)->assertOk();
        $this->escribir('1')->assertOk();
        $conversation = WhatsAppConversation::firstOrFail();
        $this->assertFalse($conversation->bot_active);
        $this->assertTrue($conversation->advisor_wait_until->equalTo(now()->addMinutes(30)));

        $this->travel(29)->minutes();
        $this->escribir('¿Hay alguien?')->assertOk();
        app(WhatsappBotService::class)->resumeUnansweredAdvisorChats();
        $this->assertFalse($conversation->fresh()->bot_active);
        Http::assertSentCount(2);

        $this->travel(1)->minutes();
        app(WhatsappBotService::class)->resumeUnansweredAdvisorChats();
        app(WhatsappBotService::class)->resumeUnansweredAdvisorChats();
        $this->assertTrue($conversation->fresh()->bot_active);
        $this->assertNull($conversation->fresh()->advisor_wait_until);
        Http::assertSentCount(3);
        Http::assertSent(fn ($request) => str_contains((string) $request['text'], 'todavía no ha podido responder'));
    }

    public function test_respuesta_del_asesor_desde_panel_o_celular_conserva_la_pausa(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'active' => true]);
        $panel = WhatsAppConversation::create(['phone' => '573001234567', 'jid' => '573001234567@s.whatsapp.net']);
        $mobile = WhatsAppConversation::create(['phone' => '573001111222', 'jid' => '573001111222@s.whatsapp.net']);
        $panel->waitForAdvisor();
        $mobile->waitForAdvisor();

        $this->travel(10)->minutes();
        $this->actingAs($admin)->post(route('whatsapp.chat.send', $panel), ['body' => 'Te atiendo desde el panel.'])->assertRedirect();
        $this->escribir('Eco de un envío automático', $mobile->jid, ['fromMe' => true, 'source' => 'web'])->assertOk();
        $this->assertNotNull($mobile->fresh()->advisor_wait_until);
        $this->escribir('Hola, te atiendo desde el celular.', $mobile->jid, ['fromMe' => true, 'source' => 'android', 'id' => 'SALIENTE-ANDROID'])->assertOk();
        $this->escribir('Hola, te atiendo desde el celular.', $mobile->jid, ['fromMe' => true, 'source' => 'android', 'id' => 'SALIENTE-ANDROID'])->assertOk();

        $this->travel(30)->minutes();
        app(WhatsappBotService::class)->resumeUnansweredAdvisorChats();
        foreach ([$panel, $mobile] as $conversation) {
            $this->assertFalse($conversation->fresh()->bot_active);
            $this->assertNull($conversation->fresh()->advisor_wait_until);
        }
        $this->assertSame(1, WhatsAppMessage::where('evolution_id', 'SALIENTE-ANDROID')->count());
        Http::assertSentCount(1);
    }

    public function test_reintenta_un_429_y_vincula_los_acuses_al_id_de_whatsapp(): void
    {
        Http::swap(new Factory);
        Http::fakeSequence('evolution.test/*')
            ->pushStatus(429)
            ->push(['key' => ['id' => 'ENVIADO-CON-ID', 'fromMe' => true]], 201);

        $this->postJson('/webhook/evolution', [
            'event' => 'messages.update', 'apikey' => self::APIKEY,
            'data' => ['keyId' => 'ENVIADO-CON-ID', 'fromMe' => true, 'status' => 'DELIVERY_ACK'],
        ])->assertOk();
        $this->escribir('hola')->assertOk();
        Http::assertSentCount(2);
        $message = WhatsAppMessage::where('evolution_id', 'ENVIADO-CON-ID')->firstOrFail();
        $this->assertSame('delivered', $message->status);

        $this->postJson('/webhook/evolution', [
            'event' => 'messages.update', 'apikey' => self::APIKEY,
            'data' => [
                ['keyId' => 'ENVIADO-CON-ID', 'fromMe' => true, 'status' => 'READ'],
                ['keyId' => 'ENVIADO-CON-ID', 'fromMe' => true, 'status' => 'SERVER_ACK'],
            ],
        ])->assertOk();
        $this->assertSame('read', $message->fresh()->status);
    }

    public function test_mensaje_entrante_se_guarda_en_la_bandeja(): void
    {
        $this->escribir('hola', '573001111222@s.whatsapp.net', ['pushName' => 'Carlos'])->assertOk();

        $conversation = WhatsAppConversation::query()->firstOrFail();

        $this->assertSame('573001111222', $conversation->phone);
        $this->assertSame('Carlos', $conversation->client_name);
        $this->assertSame(1, $conversation->unread_count);

        $message = $conversation->messages()->firstOrFail();
        $this->assertSame('in', $message->direction);
        $this->assertSame('hola', $message->body);
    }

    public function test_fuera_de_horario_el_menu_permite_consultar_modificar_y_cancelar(): void
    {
        $this->travelTo(Carbon::parse('2026-11-18 23:30:00')); // miércoles, ya cerrado

        $this->escribir('hola')->assertOk();
        Http::assertSent(function ($request) {
            $text = (string) $request['text'];

            return str_contains($text, 'fuera de nuestro horario de atención')
                && str_contains($text, '4. 🔎 Consultar mi cita o turno.')
                && str_contains($text, '5. ✏️ Modificar una reserva.')
                && str_contains($text, '6. ❌ Cancelar una reserva.')
                && str_contains($text, '7. 👋 Finalizar conversación.')
                && str_contains($text, '8. 💬 Dejar una solicitud para un asesor.')
                && ! str_contains($text, 'Tomar un turno');
        });

        // La opción 6 lleva a cancelar (no a despedirse).
        $this->escribir('6')->assertOk();
        Http::assertSent(fn ($request) => str_contains(mb_strtolower((string) $request['text']), 'cancel'));
    }

    public function test_una_solicitud_de_asesor_desde_la_web_fuera_del_horario_llega_al_panel(): void
    {
        $this->travelTo(Carbon::parse('2026-11-18 17:15:00'));
        SiteSetting::create(['data' => ['hours_wed' => '09:00 - 17:00', 'booking_end' => '20:00']]);

        $this->escribir(WhatsappBotService::ADVISOR_REQUEST)->assertOk();
        $this->escribir('1')->assertOk();

        $conversation = WhatsAppConversation::firstOrFail();
        $this->assertFalse($conversation->bot_active);
        $this->assertDatabaseHas('staff_notifications', [
            'type' => 'client_advisor',
            'url' => route('whatsapp.chat.show', $conversation, false),
        ]);
        Http::assertSent(fn ($request) => str_contains((string) $request['text'], 'próximo horario de atención'));
    }

    public function test_el_bot_confirma_una_cita_pendiente(): void
    {
        $cita = $this->cita(['status' => 'pendiente']);

        $this->escribir('confirmar')->assertOk();

        $this->assertSame('confirmada', $cita->fresh()->status);

        // El bot agradece la confirmación por el mismo canal.
        Http::assertSent(fn ($request) => str_contains((string) $request['text'], 'gracias por confirmar'));

        $conversation = WhatsAppConversation::query()->firstOrFail();
        $this->assertSame(1, $conversation->unread_count); // pendiente para el equipo (el bot no borra el aviso)
        $this->assertGreaterThan(0, $conversation->messages()->where('direction', 'out')->count());
    }

    public function test_el_bot_confirma_con_si_aunque_haya_un_menu_abierto_antes(): void
    {
        $cita = $this->cita(['status' => 'pendiente']);

        // Conversación vieja con el bot: queda el menú a la espera de un número.
        $this->escribir('hola')->assertOk();
        $this->assertSame('pendiente', $cita->fresh()->status);

        // El cliente responde SÍ al aviso de la cita: se confirma igual.
        $this->escribir('SÍ')->assertOk();

        $this->assertSame('confirmada', $cita->fresh()->status);
        Http::assertSent(fn ($request) => str_contains((string) $request['text'], 'gracias por confirmar'));
        Http::assertNotSent(fn ($request) => str_contains((string) $request['text'], 'Tipo de servicio'));
    }

    /** Lo que la gente escribe de verdad para decir que sí. */
    public function test_el_bot_entiende_si_escrito_de_muchas_formas(): void
    {
        foreach (['Sí 👍', 'siii', 'Si señor', 'sí, claro, gracias', '👍', '✅', 'De una', 'Listo, ahí estaré', 'Okk', 'si confirmo mi cita'] as $i => $respuesta) {
            $cita = $this->cita(['status' => 'pendiente', 'client_phone' => '30012345'.str_pad((string) $i, 2, '0', STR_PAD_LEFT)]);

            $this->escribir($respuesta, '57'.$cita->client_phone.'@s.whatsapp.net')->assertOk();

            $this->assertSame('confirmada', $cita->fresh()->status, "No confirmó con «{$respuesta}»");
        }
    }

    public function test_el_bot_no_confirma_si_el_cliente_pregunta_o_dice_que_no(): void
    {
        foreach (['si llego tarde?', 'si pero quiero cambiar la hora', 'no confirmo', 'sí, cancélala', 'no'] as $i => $respuesta) {
            $cita = $this->cita(['status' => 'pendiente', 'client_phone' => '30098765'.str_pad((string) $i, 2, '0', STR_PAD_LEFT)]);

            $this->escribir($respuesta, '57'.$cita->client_phone.'@s.whatsapp.net')->assertOk();

            $this->assertSame('pendiente', $cita->fresh()->status, "Confirmó con «{$respuesta}»");
        }
    }

    public function test_el_si_confirma_la_cita_pendiente_aunque_el_cliente_este_en_otro_menu(): void
    {
        $cita = $this->cita(['status' => 'pendiente']);

        // Antes de responder, el cliente entró al menú de servicios del bot (espera un número).
        $this->escribir('hola')->assertOk();
        $this->escribir('3')->assertOk();

        $this->escribir('Sí')->assertOk();

        $this->assertSame('confirmada', $cita->fresh()->status);
        Http::assertSent(fn ($request) => str_contains((string) ($request['text'] ?? ''), 'gracias por confirmar'));
    }

    public function test_el_aviso_de_la_cita_cierra_el_menu_abierto_para_leer_la_respuesta(): void
    {
        $cita = $this->cita(['status' => 'pendiente', 'appointment_date' => now()->toDateString(), 'appointment_time' => '10:25:00']);
        $this->escribir('hola')->assertOk();       // menú principal abierto: "1" sería "agendar cita"
        $this->assertNotNull(Cache::get('wa.bot.flow.573001234567'));

        $notifier = app(ClientNotifier::class);
        $notifier->appointmentBooked($cita->load(['barber', 'service']));
        $notifier->flush();

        $this->assertNull(Cache::get('wa.bot.flow.573001234567'));
        $this->escribir('1')->assertOk();           // "1. Confirmar mi asistencia" del recordatorio
        $this->assertSame('confirmada', $cita->fresh()->status);
    }

    public function test_mensajes_con_la_direccion_nueva_de_whatsapp_lid_se_atienden(): void
    {
        $cita = $this->cita(['status' => 'pendiente']);

        // El número llega en remoteJidAlt y el remitente principal es un @lid.
        $this->escribir('sí', '1234567890123@lid', ['remoteJidAlt' => '573001234567@s.whatsapp.net'])->assertOk();
        $this->assertSame('confirmada', $cita->fresh()->status);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/message/sendText/')
            && $request['number'] === '1234567890123@lid');

        // Al revés: remoteJidAlt trae el @lid y el número va en remoteJid.
        $otra = $this->cita(['status' => 'pendiente', 'client_phone' => '3002223344']);
        $this->escribir('sí', '573002223344@s.whatsapp.net', ['remoteJidAlt' => '9876543210987@lid'])->assertOk();
        $this->assertSame('confirmada', $otra->fresh()->status);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/message/sendText/')
            && $request['number'] === '573002223344');

        // Solo con senderPn.
        $tercera = $this->cita(['status' => 'pendiente', 'client_phone' => '3005556677']);
        $this->escribir('sí', '5555555555555@lid', ['senderPn' => '573005556677@s.whatsapp.net'])->assertOk();
        $this->assertSame('confirmada', $tercera->fresh()->status);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/message/sendText/')
            && $request['number'] === '5555555555555@lid');
    }

    public function test_con_el_bot_en_pausa_el_si_igual_confirma_la_cita_pendiente(): void
    {
        $cita = $this->cita(['status' => 'pendiente']);
        // El cliente había pedido un asesor antes: el bot quedó en pausa en su chat.
        WhatsAppConversation::query()->create(['phone' => '573001234567', 'jid' => '573001234567@s.whatsapp.net', 'bot_active' => false]);

        $this->escribir('hola')->assertOk();
        Http::assertNotSent(fn ($request) => str_contains((string) ($request['text'] ?? ''), 'menú'));

        $this->escribir('Si')->assertOk();

        $this->assertSame('confirmada', $cita->fresh()->status);
        Http::assertSent(fn ($request) => str_contains((string) ($request['text'] ?? ''), 'gracias por confirmar')
            && ! str_contains((string) $request['text'], 'Volver al menú principal'));
        $this->assertFalse(WhatsAppConversation::query()->firstOrFail()->bot_active, 'El asesor sigue a cargo.');
    }

    public function test_la_respuesta_con_solo_el_lid_se_reconoce_si_antes_le_escribimos(): void
    {
        $cita = $this->cita(['status' => 'pendiente']);
        Http::swap(new Factory);
        Http::fake([
            'evolution.test/message/sendText/*' => Http::response(['key' => ['remoteJid' => '210987654321098@lid', 'fromMe' => true, 'id' => 'ENV-1']]),
            'evolution.test/*' => Http::response(['created' => true]),
        ]);

        // Le pedimos confirmar la cita: WhatsApp responde con su @lid.
        app(WhatsAppService::class)->sendText('3001234567', 'Confirma tu cita respondiendo SÍ');

        // El cliente responde y llega solo el @lid, sin número.
        $this->escribir('sí', '210987654321098@lid')->assertOk();

        $this->assertSame('confirmada', $cita->fresh()->status);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/message/sendText/')
            && $request['number'] === '210987654321098@lid');
    }

    public function test_un_mensaje_con_solo_un_lid_desconocido_se_guarda_y_responde_al_mismo_lid(): void
    {
        $this->escribir('hola', '555555555555555@lid')->assertOk();

        $conversation = WhatsAppConversation::query()->firstOrFail();
        $this->assertNull($conversation->phone);
        $this->assertNull($conversation->username);
        $this->assertSame('555555555555555@lid', $conversation->jid);
        $this->assertSame(2, WhatsAppMessage::query()->count());
        Http::assertSent(fn ($request) => str_contains($request->url(), '/message/sendText/')
            && $request['number'] === '555555555555555@lid');
    }

    public function test_reconoce_el_username_y_lo_muestra_junto_al_lid_sin_inventar_telefono(): void
    {
        $this->escribir('hola', '122299361538159@lid', [
            'remoteJidUsername' => 'Nombre123',
            'pushName' => 'Ana',
        ])->assertOk();

        $conversation = WhatsAppConversation::query()->firstOrFail();
        $this->assertNull($conversation->phone);
        $this->assertSame('nombre123', $conversation->username);
        $this->assertSame('Ana', $conversation->client_name);
        $this->assertSame('122299361538159@lid', $conversation->jid);
        $this->assertSame('@nombre123', $conversation->contactIdentity());
        $this->assertSame(2, WhatsAppMessage::query()->count());
        Http::assertSent(fn ($request) => $request['number'] === '122299361538159@lid');

        // El estado del menú pertenece al JID, no al nombre visible ni a un teléfono inventado.
        $this->escribir('3', '122299361538159@lid', ['remoteJidUsername' => 'Nombre123'])->assertOk();
        $this->assertSame(1, WhatsAppConversation::query()->count());
        $this->assertSame('services', Cache::get('wa.bot.flow.122299361538159@lid')['step']);
    }

    public function test_username_y_telefono_real_comparten_una_sola_conversacion(): void
    {
        $this->escribir('hola', '122299361538159@lid', [
            'remoteJidAlt' => '573001234567@s.whatsapp.net',
            'remoteJidUsername' => 'Nombre123',
        ])->assertOk();
        $this->escribir('3', '122299361538159@lid', [
            'remoteJidAlt' => '573001234567@s.whatsapp.net',
            'remoteJidUsername' => 'Nombre123',
        ])->assertOk();

        $conversation = WhatsAppConversation::query()->firstOrFail();
        $this->assertSame('573001234567', $conversation->phone);
        $this->assertSame('nombre123', $conversation->username);
        $this->assertSame('122299361538159@lid', $conversation->jid);
        $this->assertSame(1, WhatsAppConversation::query()->count());
        $this->assertNotNull(Cache::get('wa.bot.flow.573001234567'));
    }

    public function test_un_username_sin_lid_se_guarda_y_recibe_respuesta_por_su_jid(): void
    {
        $this->escribir('hola', 'Nombre123@s.whatsapp.net', ['remoteJidUsername' => '@Nombre123'])->assertOk();

        $conversation = WhatsAppConversation::query()->firstOrFail();
        $this->assertNull($conversation->phone);
        $this->assertSame('nombre123', $conversation->username);
        Http::assertSent(fn ($request) => $request['number'] === 'nombre123@s.whatsapp.net');
    }

    public function test_un_remote_jid_con_arroba_tambien_guarda_el_username(): void
    {
        $this->escribir('hola', '@Nombre123')->assertOk();

        $conversation = WhatsAppConversation::query()->firstOrFail();
        $this->assertNull($conversation->phone);
        $this->assertSame('nombre123', $conversation->username);
        $this->assertSame('nombre123@s.whatsapp.net', $conversation->jid);
        Http::assertSent(fn ($request) => $request['number'] === 'nombre123@s.whatsapp.net');
    }

    public function test_el_username_con_puntos_es_metadata_y_puede_reutilizarse_en_otro_jid(): void
    {
        $primera = WhatsAppConversation::query()->create([
            'jid' => '555555555555561@lid',
            'username' => 'Juan.Perez',
        ]);
        $segunda = WhatsAppConversation::query()->create([
            'jid' => '555555555555562@lid',
            'username' => '@JUAN.PEREZ',
        ]);

        $this->assertSame('juan.perez', $primera->username);
        $this->assertSame('juan.perez', $segunda->username);
        $this->assertNotSame($primera->id, $segunda->id);
        $this->assertDatabaseCount('whatsapp_conversations', 2);
        $this->assertNull(WhatsAppConversation::normalizeUsername('123456789'));
        $this->assertNull(WhatsAppConversation::normalizeUsername('Ana Gómez'));
    }

    public function test_al_unificar_telefono_y_lid_conserva_una_sola_conversacion_y_todo_el_historial(): void
    {
        $this->escribir('hola', '573009998877@s.whatsapp.net')->assertOk();
        $principal = WhatsAppConversation::query()->where('phone', '573009998877')->firstOrFail();

        $this->escribir('hola', '555555555555563@lid', ['remoteJidUsername' => 'Nombre123'])->assertOk();
        $this->assertDatabaseCount('whatsapp_conversations', 2);

        $this->escribir('3', '555555555555563@lid', [
            'remoteJidAlt' => '573009998877@s.whatsapp.net',
            'remoteJidUsername' => 'Nombre123',
        ])->assertOk();

        $conversation = WhatsAppConversation::query()->firstOrFail();
        $this->assertSame($principal->id, $conversation->id);
        $this->assertSame('573009998877', $conversation->phone);
        $this->assertSame('nombre123', $conversation->username);
        $this->assertSame('555555555555563@lid', $conversation->jid);
        $this->assertSame(6, WhatsAppMessage::query()->count());
        $this->assertSame(6, WhatsAppMessage::query()->where('conversation_id', $principal->id)->count());
        $this->assertSame('services', Cache::get('wa.bot.flow.573009998877')['step']);
    }

    public function test_el_nombre_visible_no_se_interpreta_como_username(): void
    {
        $this->escribir('hola', '555555555555556@lid', ['pushName' => '@FalsoNombre'])->assertOk();

        $conversation = WhatsAppConversation::query()->firstOrFail();
        $this->assertNull($conversation->username);
        $this->assertSame('@FalsoNombre', $conversation->client_name);
        $this->assertNull($conversation->phone);
        $this->assertNull(WhatsAppInbox::phoneFromJid('ana2026@s.whatsapp.net'));
    }

    public function test_cuando_luego_llega_el_telefono_se_conserva_la_conversacion_y_el_flujo(): void
    {
        $this->escribir('hola', '555555555555557@lid')->assertOk();
        $this->assertNotNull(Cache::get('wa.bot.flow.555555555555557@lid'));

        $this->escribir('3', '555555555555557@lid', ['senderPn' => '573009998877@s.whatsapp.net'])->assertOk();

        $conversation = WhatsAppConversation::query()->firstOrFail();
        $this->assertSame('573009998877', $conversation->phone);
        $this->assertSame(1, WhatsAppConversation::query()->count());
        $this->assertSame('services', Cache::get('wa.bot.flow.573009998877')['step']);
        $this->assertNull(Cache::get('wa.bot.flow.555555555555557@lid'));
    }

    public function test_reservar_sin_telefono_pide_contacto_y_no_lo_convierte_en_identidad(): void
    {
        Service::create(['name' => 'Corte', 'price' => 20000, 'duration_minutes' => 30, 'active' => true]);
        $jid = '555555555555558@lid';
        $extra = ['remoteJidUsername' => 'ClientePrivado'];
        $this->escribir('hola', $jid, $extra)->assertOk();
        $this->escribir('1', $jid, $extra)->assertOk();

        $this->escribir('Cliente Privado', $jid, $extra)->assertOk();
        Http::assertSent(fn ($request) => str_contains((string) $request['text'], 'teléfono de contacto'));
        $this->assertSame(0, Appointment::query()->count());
        $this->assertSame(0, Turn::query()->count());

        // Un flujo antiguo que llegue a confirmar sin contacto también vuelve a pedirlo.
        Cache::put('wa.bot.flow.'.$jid, [
            'step' => 'book_confirm',
            'data' => [
                'mode' => 'cita', 'client_name' => 'Cliente Privado', 'service_id' => 1,
                'barber_id' => 1, 'date' => now()->addDay()->toDateString(), 'time' => '10:00',
            ],
            'history' => [],
        ], now()->addMinutes(30));
        $this->escribir('1', $jid, $extra)->assertOk();
        Http::assertSent(fn ($request) => str_contains((string) $request['text'], 'teléfono de contacto'));
        $this->assertSame('book_phone', Cache::get('wa.bot.flow.'.$jid)['step']);
        $this->assertSame(0, Appointment::query()->count());
        $this->assertNull(WhatsAppConversation::query()->firstOrFail()->phone);
    }

    public function test_modificar_sin_telefono_no_continua_un_flujo_abierto(): void
    {
        $jid = '555555555555564@lid';
        $this->escribir('hola', $jid, ['remoteJidUsername' => 'ClientePrivado'])->assertOk();
        $conversation = WhatsAppConversation::query()->firstOrFail();
        $cita = $this->cita();
        Cache::put('wa.bot.flow.'.$jid, [
            'step' => 'modify',
            'data' => ['appointment_id' => $cita->id],
            'history' => [],
        ], now()->addMinutes(30));

        $this->escribir('1', $jid, ['remoteJidUsername' => 'ClientePrivado'])->assertOk();

        Http::assertSent(fn ($request) => str_contains((string) $request['text'], 'modificar una reserva'));
        $this->assertSame('pendiente', $cita->fresh()->status);
        $this->assertNull($conversation->fresh()->phone);
    }

    public function test_el_panel_busca_abre_y_responde_un_chat_con_username_sin_telefono(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'active' => true]);
        $this->escribir('hola', '555555555555559@lid', ['remoteJidUsername' => 'PanelUsuario'])->assertOk();
        $conversation = WhatsAppConversation::query()->firstOrFail();

        $this->actingAs($admin)->get(route('whatsapp.chat.index', ['q' => '@panelusuario']))
            ->assertOk()->assertSee('@panelusuario');
        $this->actingAs($admin)->get(route('whatsapp.chat.show', $conversation))
            ->assertOk()->assertSee('Sin teléfono visible')->assertDontSee('https://wa.me/');
        $this->actingAs($admin)->post(route('whatsapp.chat.send', $conversation), ['body' => 'Respuesta manual'])
            ->assertRedirect();
        Http::assertSent(fn ($request) => $request['number'] === '555555555555559@lid'
            && $request['text'] === 'Respuesta manual');
    }

    public function test_cloud_no_hace_una_peticion_invalida_sin_telefono(): void
    {
        config(['services.whatsapp.driver' => 'cloud']);
        $conversation = WhatsAppConversation::query()->create([
            'jid' => '555555555555560@lid',
            'phone' => null,
            'username' => 'clouduser',
        ]);

        $this->assertFalse(app(WhatsAppInbox::class)->send($conversation, 'No se debe enviar por Cloud sin teléfono'));
        Http::assertNothingSent();
        $this->assertSame('failed', $conversation->messages()->firstOrFail()->status);
    }

    public function test_cita_web_sobre_la_hora_manda_un_solo_mensaje(): void
    {
        $barber = User::factory()->create(['role' => 'barbero', 'attention_mode' => 'citas', 'active' => true]);
        $cita = $this->cita(['barber_id' => $barber->id, 'appointment_date' => now()->toDateString(), 'appointment_time' => '10:20:00']);

        $notifier = app(ClientNotifier::class);
        $notifier->appointmentBooked($cita->load(['barber', 'service']));
        $notifier->flush();
        app(BookingMessenger::class)->requestAppointmentConfirmation($cita);

        $sent = collect(Http::recorded())->filter(fn ($pair) => str_contains($pair[0]->url(), 'sendText'));
        $this->assertCount(1, $sent, 'Al agendar 20 minutos antes debe salir un solo mensaje por WhatsApp.');
        $this->assertStringContainsString('Por favor confirma tu cita', (string) $sent->first()[0]['text']);
    }

    public function test_turno_web_con_avisos_manda_un_solo_mensaje_de_registro(): void
    {
        SiteSetting::query()->delete();
        $barber = User::factory()->create(['role' => 'barbero', 'attention_mode' => 'llegada', 'active' => true]);
        // El barbero está ocupado: el turno queda en espera y no se llama enseguida.
        app(TurnService::class)->createFor($barber, ['client_name' => 'Primero', 'client_phone' => '3009990000'], notify: false);
        Turn::query()->update(['status' => 'atendiendo']);

        app(TurnService::class)->createFor($barber, ['client_name' => 'Ana', 'client_phone' => '3001234567', 'whatsapp_consent' => true]);

        // Antes llegaban dos avisos de registro a la vez ("quedó registrado" y "ha sido confirmado").
        $registro = collect(Http::recorded())->filter(fn ($pair) => str_contains($pair[0]->url(), 'sendText')
            && str_contains((string) ($pair[0]['number'] ?? ''), '3001234567')
            && preg_match('/quedó registrado|ha sido confirmado/u', (string) ($pair[0]['text'] ?? '')) === 1);
        $this->assertCount(1, $registro, 'El turno registrado debe avisarse una sola vez.');
    }

    public function test_el_bot_encuentra_la_cita_aunque_el_telefono_se_haya_guardado_con_espacios(): void
    {
        // Formulario web: el teléfono puede quedar guardado como "300 123 4567".
        $cita = $this->cita(['status' => 'pendiente', 'client_phone' => '300 123 4567']);

        $this->escribir('sí')->assertOk();

        $this->assertSame('confirmada', $cita->fresh()->status);
    }

    public function test_el_bot_cancela_una_cita_y_avisa_al_equipo(): void
    {
        $cita = $this->cita(['status' => 'confirmada', 'client_name' => 'Ana Gómez']);

        // El bot pide el nombre para encontrar la reserva y luego confirmar la cancelación.
        $this->escribir('hola, no podre ir')->assertOk();
        Http::assertSent(fn ($request) => str_contains((string) $request['text'], 'nombre y apellido'));
        $this->escribir('ana gomez')->assertOk();
        $this->assertSame('confirmada', $cita->fresh()->status);
        Http::assertSent(fn ($request) => str_contains((string) $request['text'], 'Confirmas que deseas cancelar'));

        $this->escribir('1')->assertOk();

        $this->assertSame('cancelada', $cita->fresh()->status);

        $this->assertDatabaseHas('staff_notifications', [
            'type' => 'client_cancelled',
            'title' => 'Cliente canceló su cita (WhatsApp)',
        ]);

        // La cancelación siempre se confirma al cliente (la pida él o la barbería), con los datos de la página.
        $this->assertDatabaseHas('client_notifications', [
            'notifiable_type' => 'appointment',
            'notifiable_id' => $cita->id,
            'type' => 'appt_cancelled',
        ]);
        Http::assertSent(fn ($request) => str_contains((string) $request['text'], 'Confirmación de cancelación de cita'));
    }

    public function test_el_bot_informa_el_estado_del_turno(): void
    {
        $barbero = User::factory()->create(['role' => 'barbero', 'attention_mode' => 'llegada', 'active' => true]);
        $service = Service::create(['name' => 'Corte', 'price' => 15000, 'duration_minutes' => 30, 'active' => true]);

        $turno = app(TurnService::class)->createFor($barbero, [
            'service_id' => $service->id,
            'client_name' => 'Ana',
            'client_phone' => '3001234567',
        ]);

        $this->escribir('como voy?')->assertOk();

        Http::assertSent(fn ($request) => str_contains((string) $request['text'], '#'.str_pad((string) $turno->turn_number, 3, '0', STR_PAD_LEFT)));

        // Informar no cambia el turno (la fila la mueve solo el avance automático).
        $this->assertSame($turno->fresh()->status, $turno->status);
    }

    public function test_el_bot_responde_precios_y_horarios(): void
    {
        Service::create(['name' => 'Corte degradado', 'price' => 22000, 'duration_minutes' => 30, 'active' => true]);

        $this->escribir('cuanto cuesta el corte?')->assertOk();
        Http::assertSent(fn ($request) => str_contains((string) $request['text'], 'Corte degradado'));

        $this->escribir('que horarios manejan?')->assertOk();
        Http::assertSent(fn ($request) => str_contains((string) $request['text'], 'Horarios'));
    }

    public function test_el_bot_propone_reservar(): void
    {
        $this->escribir('quiero una cita')->assertOk();

        Http::assertSent(fn ($request) => str_contains((string) $request['text'], '/reservar'));
    }

    public function test_el_bot_no_da_turnos_en_la_ultima_media_hora_antes_del_cierre(): void
    {
        User::factory()->create(['role' => 'barbero', 'attention_mode' => 'llegada', 'active' => true]);
        Service::create(['name' => 'Corte', 'price' => 15000, 'duration_minutes' => 30, 'active' => true]);

        // Cierre a las 8:00 p. m.: a las 7:31 p. m. ya no se reciben turnos.
        $this->travelTo(Carbon::parse('2026-11-18 19:31:00'));
        $this->escribir('quiero sacar turno')->assertOk();

        Http::assertSent(fn ($request) => str_contains((string) $request['text'], 'se reciben hasta las 7:30 p. m.'));
        Http::assertSent(fn ($request) => str_contains((string) $request['text'], 'Podrá solicitar su turno mañana a partir de las'));
        $this->assertDatabaseCount('turns', 0);
    }

    public function test_el_bot_da_turnos_hasta_la_media_hora_antes_del_cierre(): void
    {
        User::factory()->create(['role' => 'barbero', 'attention_mode' => 'llegada', 'active' => true]);
        Service::create(['name' => 'Corte', 'price' => 15000, 'duration_minutes' => 30, 'active' => true]);

        $this->travelTo(Carbon::parse('2026-11-18 19:30:00'));
        $this->escribir('quiero sacar turno')->assertOk();

        Http::assertNotSent(fn ($request) => str_contains((string) $request['text'], 'ha finalizado'));
        Http::assertSent(fn ($request) => str_contains((string) $request['text'], 'tomar tu turno'));
    }

    public function test_ignora_mensajes_propios_y_de_grupos(): void
    {
        // Mensaje enviado por el negocio (fromMe): no debe entrar ni rebotar en el bot.
        $this->escribir('algo', '573001111222@s.whatsapp.net', ['fromMe' => true])->assertOk();
        // Grupo de WhatsApp: fuera del alcance de esta bandeja.
        $this->escribir('algo', '120363000000000000@g.us', ['remoteJid' => '120363000000000000@g.us'])->assertOk();

        $this->assertDatabaseCount('whatsapp_conversations', 0);
        $this->assertDatabaseCount('whatsapp_messages', 0);
        Http::assertNothingSent();
    }

    public function test_respuestas_duplicadas_del_webhook_no_se_procesan_dos_veces(): void
    {
        $this->escribir('precios', '573001111222@s.whatsapp.net', ['id' => 'MSG-ID-1'])->assertOk();
        Http::assertSentCount(1);

        // Evolution reintenta el mismo evento: se ignora por el id del mensaje.
        $this->escribir('precios', '573001111222@s.whatsapp.net', ['id' => 'MSG-ID-1'])->assertOk();
        Http::assertSentCount(1);

        // Solo el primer mensaje entrante; la respuesta del bot es la fila de salida.
        $this->assertSame(1, WhatsAppMessage::query()->where('direction', 'in')->count());
    }

    public function test_el_equipo_puede_responder_a_mano(): void
    {
        $conversation = WhatsAppConversation::create([
            'jid' => '573001111222@s.whatsapp.net',
            'phone' => '573001111222',
            'client_name' => 'Ana',
        ]);

        $ok = app(WhatsAppInbox::class)->send($conversation, 'Hola Ana, te esperamos 🙌');

        $this->assertTrue($ok);
        Http::assertSent(fn ($request) => $request->url() === 'https://evolution.test/message/sendText/barberia'
            && $request['number'] === '573001111222'
            && $request['text'] === 'Hola Ana, te esperamos 🙌');

        $this->assertDatabaseHas('whatsapp_messages', [
            'conversation_id' => $conversation->id,
            'direction' => 'out',
            'body' => 'Hola Ana, te esperamos 🙌',
            'status' => 'sent',
        ]);
    }

    public function test_las_paginas_del_panel_de_whatsapp_se_renderizan(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'active' => true]);

        $conversation = WhatsAppConversation::create([
            'jid' => '573001111222@s.whatsapp.net',
            'phone' => '573001111222',
            'client_name' => 'Ana',
        ]);

        $this->actingAs($admin)->get(route('whatsapp.index'))->assertOk();
        $this->actingAs($admin)->get(route('whatsapp.chat.index'))->assertOk();
        $this->actingAs($admin)->get(route('whatsapp.chat.show', $conversation))->assertOk();
    }

    public function test_los_enlaces_de_chats_privados_requieren_acceso_al_panel(): void
    {
        $url = route('whatsapp.chat.show', 76);

        $this->get($url)->assertRedirect(route('login'));

        $barber = User::factory()->create(['role' => 'barbero', 'active' => true]);
        $this->actingAs($barber)->get($url)->assertForbidden();
    }

    public function test_el_hilo_abierto_recibe_mensajes_nuevos_despues_de_consultas_vacias(): void
    {
        config(['services.evolution.bot_enabled' => false, 'services.evolution.send_gap_ms' => 0]);
        $admin = User::factory()->create(['role' => 'admin', 'active' => true]);
        $conversation = WhatsAppConversation::create(['phone' => '573001234567', 'jid' => '573001234567@s.whatsapp.net']);

        WhatsAppMessage::insert(array_map(fn ($number) => [
            'conversation_id' => $conversation->id,
            'direction' => WhatsAppMessage::DIRECTION_IN,
            'type' => 'text',
            'body' => sprintf('Historial %03d', $number),
            'created_at' => now(),
            'updated_at' => now(),
        ], range(1, 205)));
        $after = (int) $conversation->messages()->max('id');

        $this->actingAs($admin)->get(route('whatsapp.chat.show', $conversation))
            ->assertOk()
            ->assertSee('data-last="'.$after.'"', false)
            ->assertSee('Historial 205')
            ->assertDontSee('Historial 005');

        for ($round = 1; $round <= 8; $round++) {
            $feed = route('whatsapp.chat.messages', ['conversation' => $conversation, 'after' => $after]);
            $this->getJson($feed)->assertOk()->assertJsonCount(0, 'messages');

            $incoming = "Mensaje del cliente $round";
            $reply = "Respuesta del equipo $round";
            $this->escribir($incoming)->assertOk();
            $this->post(route('whatsapp.chat.send', $conversation), ['body' => $reply])->assertRedirect();

            $update = $this->getJson($feed)->assertOk()
                ->assertHeader('Cache-Control', 'no-store, private')
                ->assertJsonCount(2, 'messages')
                ->assertJsonPath('messages.0.body', $incoming)
                ->assertJsonPath('messages.0.direction', 'in')
                ->assertJsonPath('messages.1.body', $reply)
                ->assertJsonPath('messages.1.direction', 'out');
            $after = $update->json('messages.1.id');
            $update->assertJsonPath('receipts.0.id', $after);
        }
    }

    public function test_el_canal_en_vivo_es_privado_y_solo_firma_el_chat_solicitado(): void
    {
        config(['reverb.chat_enabled' => true]);
        $chat = WhatsAppConversation::create(['phone' => '573001234567', 'jid' => '573001234567@s.whatsapp.net']);
        $url = route('whatsapp.chat.live.auth', $chat);
        $channel = "private-whatsapp.chat.{$chat->id}";
        $credentials = ['socket_id' => '1234.5678', 'channel_name' => $channel];

        $this->postJson($url, $credentials)->assertUnauthorized();
        $this->actingAs(User::factory()->create(['role' => 'barbero', 'active' => true]))
            ->postJson($url, $credentials)->assertForbidden();
        $admin = User::factory()->create(['role' => 'admin', 'active' => true]);
        $this->actingAs($admin)->postJson($url, [...$credentials, 'channel_name' => 'private-whatsapp.chat.0'])
            ->assertUnprocessable();

        $app = config('reverb.apps.apps.0');
        $signature = hash_hmac('sha256', "1234.5678:$channel", $app['secret']);
        $this->postJson($url, $credentials)->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertExactJson(['auth' => $app['key'].':'.$signature]);
    }

    public function test_el_equipo_envia_desde_el_hilo_sin_redirigir_y_muestra_fallos(): void
    {
        config(['services.evolution.send_gap_ms' => 0]);
        Http::swap(new Factory);
        Http::fakeSequence('evolution.test/*')
            ->push(['key' => ['id' => 'RESPUESTA-PANEL']], 201)
            ->pushStatus(400);
        $admin = User::factory()->create(['role' => 'admin', 'active' => true]);
        $chat = WhatsAppConversation::create(['phone' => '573001234567', 'jid' => '573001234567@s.whatsapp.net']);

        $this->actingAs($admin)->postJson(route('whatsapp.chat.send', $chat), ['body' => 'Respuesta desde el hilo'])
            ->assertOk();
        $this->postJson(route('whatsapp.chat.send', $chat), ['body' => 'Mensaje fallido'])
            ->assertStatus(502);
        $this->assertDatabaseHas('whatsapp_messages', ['body' => 'Respuesta desde el hilo', 'status' => 'sent']);
        $this->assertDatabaseHas('whatsapp_messages', ['body' => 'Mensaje fallido', 'status' => 'failed']);
        Http::assertSentCount(2);
    }

    public function test_el_equipo_puede_eliminar_un_chat_de_la_bandeja(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'active' => true]);

        // El cliente escribe y el bot le contesta: quedan mensajes de entrada y de salida.
        $this->escribir('hola', '573001111222@s.whatsapp.net')->assertOk();
        $conversation = WhatsAppConversation::query()->firstOrFail();
        $this->assertGreaterThan(1, $conversation->messages()->count());
        $chatUrl = route('whatsapp.chat.show', $conversation, false);
        $this->assertDatabaseHas('staff_notifications', ['type' => 'whatsapp_message', 'url' => $chatUrl]);

        $this->actingAs($admin)
            ->delete(route('whatsapp.chat.destroy', $conversation))
            ->assertRedirect(route('whatsapp.chat.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseCount('whatsapp_conversations', 0);
        $this->assertDatabaseCount('whatsapp_messages', 0);

        $this->get($chatUrl)->assertRedirect(route('whatsapp.chat.index'));
        $this->getJson($chatUrl)->assertNotFound();

        // Si vuelve a escribir, aparece como chat nuevo y el bot empieza desde el menú.
        $this->escribir('hola', '573001111222@s.whatsapp.net')->assertOk();
        $this->assertDatabaseCount('whatsapp_conversations', 1);
    }

    public function test_conectar_deja_el_webhook_registrado_aunque_la_instancia_sea_nueva(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'active' => true]);
        config(['services.evolution.webhook_url' => 'http://127.0.0.1:8000/webhook/evolution']);

        $this->actingAs($admin)->postJson(route('whatsapp.connect'))->assertOk();

        Http::assertSent(fn ($request) => $request->url() === 'https://evolution.test/webhook/set/barberia'
            && $request['webhook']['url'] === 'http://127.0.0.1:8000/webhook/evolution'
            && $request['webhook']['headers']['apikey'] === 'global-key');
    }

    public function test_desvincular_numero_borra_la_instancia_y_la_deja_lista_para_un_qr_nuevo(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'active' => true]);
        config(['services.evolution.webhook_url' => 'http://127.0.0.1:8000/webhook/evolution']);

        $this->actingAs($admin)
            ->post(route('whatsapp.logout'))
            ->assertRedirect(route('whatsapp.index'))
            ->assertSessionHas('success');

        Http::assertSent(fn ($request) => $request->method() === 'DELETE'
            && $request->url() === 'https://evolution.test/instance/delete/barberia');
        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && $request->url() === 'https://evolution.test/instance/create'
            && $request['instanceName'] === 'barberia');
        // El webhook se vuelve a registrar con la cabecera que exige este sitio.
        Http::assertSent(fn ($request) => $request->url() === 'https://evolution.test/webhook/set/barberia'
            && $request['webhook']['url'] === 'http://127.0.0.1:8000/webhook/evolution'
            && $request['webhook']['headers']['apikey'] === 'global-key');
    }

    // ------------------------------------------------------------------------------------------
    // Utilidades
    // ------------------------------------------------------------------------------------------

    /** Dispara el webhook como lo haría Evolution API. */
    private function escribir(string $text, string $jid = '573001234567@s.whatsapp.net', array $extra = [])
    {
        $key = array_merge([
            'remoteJid' => $jid,
            'fromMe' => false,
            'id' => 'MSG-'.uniqid(),
        ], array_diff_key($extra, ['remoteJid' => true]));

        $data = array_merge([
            'key' => $key,
            'pushName' => $extra['pushName'] ?? 'Ana',
            'message' => ['conversation' => $text],
            'messageTimestamp' => now()->getTimestamp(),
        ], collect($extra)->except(['remoteJid', 'fromMe', 'id', 'pushName'])->all());

        return $this->postJson('/webhook/evolution', [
            'event' => 'messages.upsert',
            'instance' => 'barberia',
            'apikey' => self::APIKEY,
            'data' => $data,
        ]);
    }

    private function cita(array $attributes = []): Appointment
    {
        $barber = User::factory()->create(['role' => 'barbero', 'attention_mode' => 'citas', 'active' => true]);

        return Appointment::create(array_merge([
            'barber_id' => $barber->id,
            'client_name' => 'Ana',
            'client_phone' => '3001234567',
            'appointment_date' => now()->addDay()->toDateString(),
            'appointment_time' => '10:00:00',
            'status' => 'pendiente',
            'whatsapp_consent' => true,
        ], $attributes));
    }
}
