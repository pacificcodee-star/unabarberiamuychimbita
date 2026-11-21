<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\ClientNotification;
use App\Models\PushSubscription;
use App\Models\Service;
use App\Models\Turn;
use App\Models\User;
use App\Services\ClientNotifier;
use App\Services\PushSender;
use App\Services\TurnQueueService;
use App\Services\TurnService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Envío falso: registra lo que se habría enviado, sin salir a internet. */
class FakePushSender extends PushSender
{
    public array $sent = [];

    public bool $gone = false;

    public function isConfigured(): bool
    {
        return true;
    }

    public function send(PushSubscription $subscription, array $payload, int $ttlSeconds = 600): array
    {
        if ($this->gone) {
            return ['ok' => false, 'gone' => true, 'error' => 'HTTP 410'];
        }
        $this->sent[] = $payload;

        return ['ok' => true, 'gone' => false, 'error' => null];
    }

    public function tags(): array
    {
        return array_column($this->sent, 'tag');
    }
}

class NotificationSystemTest extends TestCase
{
    use RefreshDatabase;

    private FakePushSender $push;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-18 10:00:00')); // miércoles, barbería abierta
        $this->push = new FakePushSender();
        $this->app->instance(PushSender::class, $this->push);
    }

    private function barber(string $mode): User
    {
        return User::factory()->create(['role' => 'barbero', 'attention_mode' => $mode, 'active' => true]);
    }

    private function service(int $minutes = 30): Service
    {
        return Service::create(['name' => 'Corte clásico', 'price' => 18000, 'duration_minutes' => $minutes, 'active' => true]);
    }

    private function turn(User $barber, Service $service, string $name): Turn
    {
        return app(TurnService::class)->createFor($barber, [
            'service_id' => $service->id,
            'client_name' => $name,
            'client_phone' => '300 123 4567',
        ]);
    }

    private function device(Turn|Appointment $subject, string $id = 'abc'): PushSubscription
    {
        $endpoint = 'https://fcm.googleapis.com/fcm/send/'.$id;

        return $subject->pushSubscriptions()->create([
            'endpoint' => $endpoint,
            'endpoint_hash' => PushSubscription::hashEndpoint($endpoint),
            'p256dh' => 'clave-publica',
            'auth' => 'clave-auth',
        ]);
    }

    private function appointment(User $barber, Service $service, Carbon $start): Appointment
    {
        $appointment = Appointment::create([
            'barber_id' => $barber->id,
            'service_id' => $service->id,
            'client_name' => 'Cliente Cita',
            'client_phone' => '300 123 4567',
            'appointment_date' => $start->toDateString(),
            'appointment_time' => $start->format('H:i'),
            'status' => 'confirmada',
        ]);
        app(ClientNotifier::class)->appointmentBooked($appointment->load(['barber', 'service']));
        app(ClientNotifier::class)->flush();

        return $appointment;
    }

    public function test_turn_without_account_gets_a_secret_link_and_push_confirmation_when_the_device_subscribes(): void
    {
        $barber = $this->barber('llegada');
        $service = $this->service();

        $response = $this->post(route('public.booking.turn'), [
            'barber_id' => $barber->id,
            'service_id' => $service->id,
            'client_name' => 'Cliente Sin Cuenta',
            'client_phone' => '300 123 4567',
            'payment_method' => 'efectivo',
        ]);
        $response->assertSessionHas('booking_success');

        $turn = Turn::firstOrFail();
        $this->assertSame(40, strlen($turn->public_token));
        $this->assertSame([], $this->push->sent); // todavía no hay dispositivos

        $url = ['type' => 'turno', 'token' => $turn->public_token];
        $this->get(route('track.show', $url))->assertOk()->assertSee('Activar avisos');

        $this->postJson(route('track.push.store', $url), [
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/dispositivo-1',
            'keys' => ['p256dh' => 'BPublica_-123', 'auth' => 'auth123'],
        ])->assertOk();

        $this->assertSame(1, $turn->pushSubscriptions()->count());
        $this->assertContains("turn:{$turn->id}:turn_confirmed", $this->push->tags());
        $this->assertContains("turn:{$turn->id}:turn_called", $this->push->tags());
    }

    public function test_push_subscription_rejects_unknown_hosts_and_wrong_tokens(): void
    {
        $turn = $this->turn($this->barber('llegada'), $this->service(), 'Ana');

        $this->postJson(route('track.push.store', ['type' => 'turno', 'token' => $turn->public_token]), [
            'endpoint' => 'https://servidor-desconocido.example.com/push',
            'keys' => ['p256dh' => 'abc', 'auth' => 'abc'],
        ])->assertStatus(422);

        $this->get(route('track.show', ['type' => 'turno', 'token' => str_repeat('x', 40)]))->assertNotFound();
        $this->post(route('track.cancel', ['type' => 'turno', 'token' => str_repeat('x', 40)]))->assertNotFound();
        $this->assertSame('llamado', $turn->fresh()->status);
    }

    public function test_queue_finishes_the_service_and_calls_the_next_client_automatically(): void
    {
        $barber = $this->barber('llegada');
        $service = $this->service(30);
        $first = $this->turn($barber, $service, 'Primero');
        $second = $this->turn($barber, $service, 'Segundo');
        $this->device($second);

        $this->assertSame('llamado', $first->fresh()->status);
        $this->assertSame('esperando', $second->fresh()->status);

        $this->travel(10)->minutes(); // se cumple el margen de llegada
        $this->artisan('barberia:tick');
        $this->assertSame('atendiendo', $first->fresh()->status);
        $this->assertNotNull($first->fresh()->started_at);

        $this->travel(29)->minutes();
        $this->artisan('barberia:tick');
        $this->assertSame('atendiendo', $first->fresh()->status); // todavía no termina

        $this->travel(1)->minutes(); // se cumple la duración del servicio
        $this->artisan('barberia:tick');
        $this->assertSame('atendido', $first->fresh()->status);
        $this->assertSame('llamado', $second->fresh()->status);
        $this->assertContains("turn:{$second->id}:turn_called", $this->push->tags());

        // Reinicio del servidor o doble ejecución: no se repite el aviso.
        $this->artisan('barberia:tick');
        $this->artisan('barberia:tick');
        $this->assertCount(1, array_keys($this->push->tags(), "turn:{$second->id}:turn_called"));
    }

    public function test_cancelling_a_waiting_turn_moves_the_others_forward_and_stops_its_notifications(): void
    {
        $barber = $this->barber('llegada');
        $service = $this->service(30);
        $this->turn($barber, $service, 'Primero');
        $second = $this->turn($barber, $service, 'Segundo');
        $third = $this->turn($barber, $service, 'Tercero');
        $this->device($second);

        $queue = app(TurnQueueService::class);
        $this->assertSame(2, $queue->estimates($barber->id)[$third->id]['ahead']);
        $before = $queue->estimates($barber->id)[$third->id]['call_at'];

        $this->post(route('track.cancel', ['type' => 'turno', 'token' => $second->public_token]))->assertRedirect();

        $this->assertSame('cancelado', $second->fresh()->status);
        $this->assertSame(1, $queue->estimates($barber->id)[$third->id]['ahead']);
        $this->assertTrue($queue->estimates($barber->id)[$third->id]['call_at']->lt($before));
        $this->assertSame(0, $second->clientNotifications()->where('status', ClientNotification::PENDING)->count());

        $sentBefore = count($this->push->sent);
        $this->travel(60)->minutes();
        $this->artisan('barberia:tick');
        $this->assertNotContains("turn:{$second->id}:turn_called", $this->push->tags());
        $this->assertSame('cancelado', $second->fresh()->status);
        $this->assertGreaterThanOrEqual($sentBefore, count($this->push->sent));
    }

    public function test_a_turn_that_already_started_cannot_be_cancelled_by_the_client(): void
    {
        $turn = $this->turn($this->barber('llegada'), $this->service(), 'Ana');
        $this->travel(10)->minutes();
        $this->artisan('barberia:tick');
        $this->assertSame('atendiendo', $turn->fresh()->status);

        $this->post(route('track.cancel', ['type' => 'turno', 'token' => $turn->public_token]))->assertSessionHas('track_error');
        $this->assertSame('atendiendo', $turn->fresh()->status);
    }

    public function test_appointment_reminders_are_sent_once_at_the_right_time(): void
    {
        $start = now()->addHours(3); // 1:00 p. m.
        $appointment = $this->appointment($this->barber('citas'), $this->service(), $start);
        $this->device($appointment);
        $key = "appointment:{$appointment->id}";
        $stamp = $start->format('YmdHi');

        $this->assertSame(3, $appointment->clientNotifications()->whereIn('type', ClientNotifier::REMINDER_TYPES)->count());

        $this->artisan('barberia:tick');
        $this->assertContains("{$key}:appt_booked", $this->push->tags());
        $this->assertNotContains("{$key}:appt_reminder_60:{$stamp}", $this->push->tags());

        $this->travelTo($start->copy()->subMinutes(60));
        $this->artisan('barberia:tick');
        $this->artisan('barberia:tick');
        $this->assertCount(1, array_keys($this->push->tags(), "{$key}:appt_reminder_60:{$stamp}"));

        $this->travelTo($start->copy()->subMinutes(15));
        $this->artisan('barberia:tick');
        $this->assertCount(1, array_keys($this->push->tags(), "{$key}:appt_reminder_15:{$stamp}"));

        $this->travelTo($start->copy());
        $this->artisan('barberia:tick');
        $this->artisan('barberia:tick');
        $this->assertCount(1, array_keys($this->push->tags(), "{$key}:appt_reminder_0:{$stamp}"));
    }

    public function test_rescheduling_replaces_reminders_and_cancelling_removes_them(): void
    {
        $start = now()->addDay()->setTime(15, 0);
        $appointment = $this->appointment($this->barber('citas'), $this->service(), $start);
        $notifier = app(ClientNotifier::class);

        $before = ['status' => $appointment->status, 'starts_at' => $appointment->startsAt()];
        $appointment->update(['appointment_time' => '17:00']);
        $notifier->appointmentChanged($appointment->fresh()->load(['barber', 'service']), $before);

        $pending = $appointment->clientNotifications()->where('channel', 'push')->where('status', ClientNotification::PENDING)->whereIn('type', ClientNotifier::REMINDER_TYPES)->get();
        $this->assertCount(3, $pending);
        $this->assertTrue($pending->every(fn ($n) => str_contains($n->dedupe_key, $start->copy()->setTime(17, 0)->format('YmdHi'))));
        $this->assertSame(3, $appointment->clientNotifications()->where('status', ClientNotification::CANCELLED)->whereIn('type', ClientNotifier::REMINDER_TYPES)->count());

        $this->post(route('track.cancel', ['type' => 'cita', 'token' => $appointment->public_token]))->assertSessionHas('track_success');
        $this->assertSame('cancelada', $appointment->fresh()->status);
        // Se cancelan los recordatorios viejos; queda solo la confirmación de cancelación.
        $this->assertSame(0, $appointment->clientNotifications()->where('status', ClientNotification::PENDING)->whereIn('type', ClientNotifier::REMINDER_TYPES)->count());
        $this->assertSame(1, $appointment->clientNotifications()->where('type', 'appt_cancelled')->count());
    }

    public function test_invalid_subscriptions_are_removed(): void
    {
        $turn = $this->turn($this->barber('llegada'), $this->service(), 'Ana');
        $this->device($turn);
        $this->push->gone = true;

        app(ClientNotifier::class)->deliverPendingFor($turn);

        $this->assertSame(0, $turn->pushSubscriptions()->count());
    }

    public function test_client_bell_inbox_lists_the_notifications_of_the_turn_even_without_push(): void
    {
        $turn = $this->turn($this->barber('llegada'), $this->service(), 'Ana');

        $titles = collect($this->getJson(route('track.status', ['type' => 'turno', 'token' => $turn->public_token]))
            ->assertOk()
            ->json('notifications'))->pluck('title')->all();

        $this->assertContains('Turno #001 confirmado', $titles);
        $this->assertContains('¡Llegó tu turno #001!', $titles);
    }

    public function test_staff_bell_shows_public_bookings_to_their_barber_and_to_the_admin(): void
    {
        $barber = $this->barber('llegada');
        $other = $this->barber('llegada');
        $admin = User::factory()->create(['role' => 'admin', 'active' => true]);

        $this->post(route('public.booking.turn'), [
            'barber_id' => $barber->id,
            'service_id' => $this->service()->id,
            'client_name' => 'Cliente Web',
            'client_phone' => '300 123 4567',
            'payment_method' => 'efectivo',
        ])->assertSessionHas('booking_success');

        $this->get(route('staff-notifications.feed'))->assertRedirect(route('login'));

        $this->assertSame(0, $this->actingAs($other)->getJson(route('staff-notifications.feed'))->assertOk()->json('unread'));
        $this->assertGreaterThanOrEqual(1, $this->actingAs($admin)->getJson(route('staff-notifications.feed'))->json('unread'));

        $feed = $this->actingAs($barber)->getJson(route('staff-notifications.feed'))->assertOk();
        $this->assertGreaterThanOrEqual(1, $feed->json('unread'));
        $this->assertContains('Nuevo turno #1: Cliente Web', collect($feed->json('items'))->pluck('title')->all());

        $this->travel(1)->minutes();
        $this->actingAs($barber)->postJson(route('staff-notifications.seen'))->assertOk();
        $this->assertSame(0, $this->actingAs($barber)->getJson(route('staff-notifications.feed'))->json('unread'));

        $this->actingAs($admin)->get(route('staff-notifications.index'))->assertOk()->assertSee('Cliente Web');
        $this->actingAs($admin)->get(route('staff-notifications.index', ['tab' => 'clientes']))->assertOk()->assertSee('Cliente Web');
    }
}
