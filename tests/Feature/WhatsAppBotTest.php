<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Service;
use App\Models\User;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use App\Services\TurnService;
use App\Services\WhatsApp\WhatsAppInbox;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
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

    public function test_el_bot_confirma_una_cita_pendiente(): void
    {
        $cita = $this->cita(['status' => 'pendiente']);

        $this->escribir('confirmar')->assertOk();

        $this->assertSame('confirmada', $cita->fresh()->status);

        // El bot contesta y, por el mismo canal, ClientNotifier manda el aviso formal.
        Http::assertSent(fn ($request) => str_contains((string) $request['text'], 'confirmada'));

        $conversation = WhatsAppConversation::query()->firstOrFail();
        $this->assertSame(1, $conversation->unread_count); // pendiente para el equipo (el bot no borra el aviso)
        $this->assertGreaterThan(0, $conversation->messages()->where('direction', 'out')->count());
    }

    public function test_el_bot_cancela_una_cita_y_avisa_al_equipo(): void
    {
        $cita = $this->cita(['status' => 'confirmada']);

        $this->escribir('hola, no podre ir')->assertOk();

        $this->assertSame('cancelada', $cita->fresh()->status);

        $this->assertDatabaseHas('staff_notifications', [
            'type' => 'client_cancelled',
            'title' => 'Cliente anuló su cita (WhatsApp)',
        ]);

        // Como la cancelación la pidió el cliente, no se le manda un aviso extra de cancelación.
        $this->assertDatabaseMissing('client_notifications', [
            'notifiable_type' => 'appointment',
            'notifiable_id' => $cita->id,
            'type' => 'appt_cancelled',
        ]);
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

        Http::assertSent(fn ($request) => str_contains((string) $request['text'], 'turno #'.str_pad((string) $turno->turn_number, 3, '0', STR_PAD_LEFT)));

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
