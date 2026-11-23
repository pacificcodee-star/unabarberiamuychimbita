<?php

namespace Tests\Feature;

use App\Models\StaffNotification;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Campana del panel: "Eliminar todas" vacía la bandeja de quien la usa y nada más. */
class StaffNotificationClearTest extends TestCase
{
    use RefreshDatabase;

    public function test_eliminar_todas_vacia_solo_la_bandeja_de_quien_la_usa(): void
    {
        $this->travelTo(Carbon::parse('2026-11-18 10:00:00'));
        $admin = User::factory()->create(['role' => 'admin', 'active' => true]);
        $barber = User::factory()->create(['role' => 'barbero', 'attention_mode' => 'llegada', 'active' => true]);

        StaffNotification::record('turn_new', 'Nuevo turno #001', 'Ana', '/turns', $barber->id);
        StaffNotification::record('appt_new', 'Nueva cita', 'Luis', '/appointments', $barber->id);

        $feed = $this->actingAs($admin)->getJson(route('staff-notifications.feed'))->assertOk();
        $this->assertSame(2, $feed->json('total'));
        $this->assertSame('blue', $feed->json('items.1.tone'));
        $this->assertSame('Hoy', $feed->json('items.0.day'));

        $this->actingAs($admin)->deleteJson(route('staff-notifications.clear'))->assertOk();

        $this->assertSame(0, $this->actingAs($admin)->getJson(route('staff-notifications.feed'))->json('total'));
        // El barbero conserva los suyos: no se borraron las filas.
        $this->assertSame(2, $this->actingAs($barber)->getJson(route('staff-notifications.feed'))->json('total'));
        $this->assertDatabaseCount('staff_notifications', 2);

        // Lo que llega después sí se ve.
        $this->travel(5)->minutes();
        StaffNotification::record('client_cancelled', 'Cliente canceló su cita', null, null, $barber->id);
        $after = $this->actingAs($admin)->getJson(route('staff-notifications.feed'))->assertOk();
        $this->assertSame(1, $after->json('total'));
        $this->assertSame(1, $after->json('unread'));
        $this->assertSame('red', $after->json('items.0.tone'));
    }

    public function test_eliminar_todas_tambien_borra_avisos_con_la_hora_adelantada(): void
    {
        // Avisos importados con hora UTC: quedan 5 horas "en el futuro" respecto a la hora de Colombia.
        $this->travelTo(Carbon::parse('2026-11-23 00:04:00'));
        $admin = User::factory()->create(['role' => 'admin', 'active' => true]);
        StaffNotification::record('whatsapp_message', 'WhatsApp: Ana');
        StaffNotification::query()->latest('id')->first()->forceFill(['created_at' => '2026-11-23 05:04:00'])->save();
        StaffNotification::record('appt_new', 'Nueva cita');

        $this->assertSame(2, $this->actingAs($admin)->getJson(route('staff-notifications.feed'))->json('unread'));

        // Abrir la campana deja todo como leído, aunque la hora guardada esté adelantada.
        $this->actingAs($admin)->postJson(route('staff-notifications.seen'))->assertOk();
        $this->assertSame(0, $this->actingAs($admin)->getJson(route('staff-notifications.feed'))->json('unread'));

        $this->actingAs($admin)->deleteJson(route('staff-notifications.clear'))->assertOk();
        $this->assertSame(0, $this->actingAs($admin)->getJson(route('staff-notifications.feed'))->json('total'));
    }

    public function test_la_pagina_de_notificaciones_permite_eliminar_todas(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'active' => true]);
        StaffNotification::record('turn_new', 'Nuevo turno #001', 'Ana');

        $this->actingAs($admin)->get(route('staff-notifications.index'))
            ->assertOk()
            ->assertSee('Eliminar todas')
            ->assertSee('Nuevo turno #001');

        $this->actingAs($admin)->delete(route('staff-notifications.clear'))
            ->assertRedirect(route('staff-notifications.index'));

        $this->actingAs($admin)->get(route('staff-notifications.index'))
            ->assertOk()
            ->assertSee('No tienes notificaciones')
            ->assertDontSee('Nuevo turno #001');
    }

    public function test_probar_avisos_envia_un_push_real_a_los_dispositivos_del_usuario(): void
    {
        $push = new class extends \App\Services\PushSender
        {
            public array $sent = [];

            public function isConfigured(): bool
            {
                return true;
            }

            public function send(\App\Models\PushSubscription $subscription, array $payload, int $ttlSeconds = 600): array
            {
                $this->sent[] = $payload;

                return ['ok' => true, 'gone' => false, 'error' => null];
            }
        };
        $this->app->instance(\App\Services\PushSender::class, $push);
        $admin = User::factory()->create(['role' => 'admin', 'active' => true]);

        // Sin dispositivos: lo dice claro.
        $this->actingAs($admin)->postJson(route('staff.push.test'))
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'Este usuario todavía no tiene dispositivos con avisos activos. Toca «Activar».']);

        $admin->pushSubscriptions()->create([
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/prueba',
            'endpoint_hash' => \App\Models\PushSubscription::hashEndpoint('https://fcm.googleapis.com/fcm/send/prueba'),
            'p256dh' => 'clave', 'auth' => 'auth', 'content_encoding' => 'aes128gcm',
        ]);

        $this->actingAs($admin)->postJson(route('staff.push.test'))
            ->assertOk()
            ->assertJson(['devices' => 1, 'sent' => 1]);
        $this->assertSame('Prueba de avisos', $push->sent[0]['title']);
    }

    public function test_solo_el_equipo_puede_vaciar_su_bandeja(): void
    {
        $this->deleteJson(route('staff-notifications.clear'))->assertUnauthorized();
    }
}
