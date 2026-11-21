<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Turn;
use App\Models\User;
use App\Services\TurnService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** Auditoría de seguridad y resiliencia: acceso, duplicados, idempotencia, errores y registros. */
class SecurityResilienceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-18 10:00:00')); // miércoles, abierto
    }

    private function barber(string $mode = 'citas'): User
    {
        return User::factory()->create(['role' => 'barbero', 'attention_mode' => $mode, 'active' => true]);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'active' => true]);
    }

    private function bookingPayload(User $barber, array $extra = []): array
    {
        return $extra + [
            'barber_id' => $barber->id,
            'client_name' => 'Cliente Prueba',
            'client_phone' => '300 123 4567',
            'payment_method' => 'efectivo',
            'appointment_date' => now()->addDay()->toDateString(),
            'appointment_time' => '10:30',
        ];
    }

    // --- Reservas simultáneas y duplicadas -----------------------------------------------------

    public function test_the_same_slot_cannot_be_booked_twice(): void
    {
        $barber = $this->barber();

        $this->post(route('public.booking.appointment'), $this->bookingPayload($barber))->assertSessionHas('booking_success');
        $this->post(route('public.booking.appointment'), $this->bookingPayload($barber, ['client_name' => 'Otra Persona']))
            ->assertSessionHasErrors('appointment_time');

        $this->assertSame(1, Appointment::count());
    }

    public function test_reserve_slot_is_atomic_and_frees_cancelled_slots(): void
    {
        $barber = $this->barber();
        $attributes = ['barber_id' => $barber->id, 'client_name' => 'A', 'appointment_date' => '2026-11-19', 'appointment_time' => '11:00', 'status' => 'pendiente'];

        $first = Appointment::reserveSlot($attributes);
        try {
            Appointment::reserveSlot(['client_name' => 'B'] + $attributes);
            $this->fail('Se permitió reservar un horario ocupado.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('appointment_time', $e->errors());
        }

        // Al cancelar, el horario queda libre otra vez.
        $first->update(['status' => 'cancelada']);
        Appointment::reserveSlot(['client_name' => 'C'] + $attributes);
        $this->assertSame(2, Appointment::count());
    }

    public function test_admin_cannot_move_an_appointment_onto_a_taken_slot(): void
    {
        $barber = $this->barber();
        $taken = Appointment::create(['barber_id' => $barber->id, 'client_name' => 'A', 'appointment_date' => '2026-11-19', 'appointment_time' => '11:00', 'status' => 'pendiente']);
        $other = Appointment::create(['barber_id' => $barber->id, 'client_name' => 'B', 'appointment_date' => '2026-11-19', 'appointment_time' => '12:00', 'status' => 'pendiente']);

        $this->actingAs($this->admin())
            ->put(route('appointments.update', $other), ['barber_id' => $barber->id, 'client_name' => 'B', 'appointment_date' => '2026-11-19', 'appointment_time' => '11:00', 'status' => 'pendiente'])
            ->assertSessionHasErrors('appointment_time');

        $this->assertSame('12:00', substr((string) $other->fresh()->appointment_time, 0, 5));
        $this->assertNotNull($taken->fresh());
    }

    public function test_turn_numbers_are_never_repeated(): void
    {
        $barber = $this->barber('llegada');
        $service = app(TurnService::class);
        foreach (range(1, 5) as $i) {
            $service->createFor($barber, ['client_name' => 'C'.$i, 'client_phone' => '300 000 000'.$i], notify: false);
        }

        $numbers = Turn::where('barber_id', $barber->id)->pluck('turn_number')->all();
        $this->assertSame([1, 2, 3, 4, 5], $numbers);
    }

    // --- Idempotencia y recuperación ----------------------------------------------------------

    public function test_retrying_with_the_same_key_does_not_duplicate_the_booking(): void
    {
        $barber = $this->barber();
        $key = (string) Str::uuid();
        $payload = $this->bookingPayload($barber, ['idempotency_key' => $key]);

        $first = $this->postJson(route('public.booking.appointment'), $payload)->assertOk()->assertJsonPath('status', 'done');
        // Reintento (se perdió la respuesta): misma clave → misma reserva, ninguna nueva.
        $second = $this->postJson(route('public.booking.appointment'), $payload)->assertOk();

        $this->assertSame($first->json('redirect'), $second->json('redirect'));
        $this->assertSame(1, Appointment::count());
    }

    public function test_the_browser_can_ask_whether_a_lost_booking_was_saved(): void
    {
        $barber = $this->barber();
        $key = (string) Str::uuid();

        $this->getJson(route('public.booking.status', $key))->assertOk()->assertJson(['status' => 'none']);

        $this->postJson(route('public.booking.appointment'), $this->bookingPayload($barber, ['idempotency_key' => $key]))->assertOk();

        $this->getJson(route('public.booking.status', $key))->assertOk()->assertJson(['status' => 'done'])->assertSessionHas('booking_success');
        $this->get(route('public.booking.status', 'no-es-uuid'))->assertNotFound();
    }

    public function test_validation_errors_come_back_as_json_per_field(): void
    {
        $barber = $this->barber();

        $this->postJson(route('public.booking.appointment'), $this->bookingPayload($barber, ['client_name' => '', 'idempotency_key' => (string) Str::uuid()]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('client_name');
        $this->assertSame(0, Appointment::count());
    }

    public function test_failed_booking_with_a_key_is_not_remembered(): void
    {
        $barber = $this->barber();
        $key = (string) Str::uuid();

        $this->postJson(route('public.booking.appointment'), $this->bookingPayload($barber, ['client_phone' => '', 'idempotency_key' => $key]))->assertStatus(422);
        // Tras corregir el dato, la misma clave sí crea la reserva.
        $this->postJson(route('public.booking.appointment'), $this->bookingPayload($barber, ['idempotency_key' => $key]))->assertOk()->assertJsonPath('status', 'done');
        $this->assertSame(1, Appointment::count());
    }

    public function test_session_token_endpoint_returns_a_fresh_csrf_token(): void
    {
        $this->getJson(route('session.token'))->assertOk()->assertJsonStructure(['token'])->assertHeader('Cache-Control', 'no-store, private');
    }

    // --- Control de acceso --------------------------------------------------------------------

    public function test_barber_cannot_touch_another_barbers_appointment(): void
    {
        $mine = $this->barber();
        $other = $this->barber();
        $appointment = Appointment::create(['barber_id' => $other->id, 'client_name' => 'Ajena', 'appointment_date' => '2026-11-19', 'appointment_time' => '11:00', 'status' => 'pendiente']);

        // Los barberos no entran al panel (solo el admin inicia sesión); aun así, si tuvieran sesión, no pueden tocar citas ajenas.
        $this->actingAs($mine)->patch(route('appointments.status', $appointment), ['status' => 'cancelada'])->assertForbidden();
        $this->actingAs($mine)->delete(route('appointments.destroy', $appointment))->assertForbidden();
        $this->assertSame('pendiente', $appointment->fresh()->status);
    }

    public function test_guests_cannot_reach_private_routes_or_json(): void
    {
        $this->get(route('dashboard'))->assertRedirect(route('login'));
        $this->getJson(route('staff-notifications.feed'))->assertUnauthorized();
        $this->get(route('services.index'))->assertRedirect(route('login'));
    }

    public function test_only_admin_users_can_log_in_and_brute_force_is_throttled(): void
    {
        $barber = $this->barber();
        $barber->update(['password' => 'secreta-123']);

        $this->post(route('login.store'), ['email' => $barber->email, 'password' => 'secreta-123'])->assertSessionHasErrors('email');
        $this->assertGuest();

        $admin = $this->admin();
        foreach (range(1, 5) as $i) {
            $this->post(route('login.store'), ['email' => $admin->email, 'password' => 'mala-'.$i]);
        }
        // Con 5 fallos queda bloqueado aunque ahora ponga la contraseña correcta.
        $this->post(route('login.store'), ['email' => $admin->email, 'password' => 'password'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_demo_credentials_are_never_shown_outside_local(): void
    {
        $this->get(route('login'))->assertOk()->assertDontSee('Acceso de prueba');
    }

    public function test_tracking_link_requires_the_secret_token_and_expires(): void
    {
        $barber = $this->barber();
        $appointment = Appointment::create(['barber_id' => $barber->id, 'client_name' => 'Ana', 'client_phone' => '3115550199', 'appointment_date' => '2026-11-19', 'appointment_time' => '11:00', 'status' => 'pendiente']);

        $this->get(route('track.show', ['type' => 'cita', 'token' => $appointment->public_token]))->assertOk()->assertDontSee('3115550199');
        // Con el id numérico o un token inventado no se llega a nada.
        $this->get('/seguimiento/cita/'.$appointment->id)->assertNotFound();
        $this->get(route('track.show', ['type' => 'cita', 'token' => Str::random(40)]))->assertNotFound();

        $this->travelTo(Carbon::parse('2026-12-25 10:00:00')); // más de 30 días después
        $this->get(route('track.show', ['type' => 'cita', 'token' => $appointment->public_token]))->assertNotFound();
    }

    public function test_cancelling_twice_is_harmless(): void
    {
        $barber = $this->barber();
        $appointment = Appointment::create(['barber_id' => $barber->id, 'client_name' => 'Ana', 'appointment_date' => '2026-11-19', 'appointment_time' => '11:00', 'status' => 'pendiente']);
        $url = route('track.cancel', ['type' => 'cita', 'token' => $appointment->public_token]);

        $this->post($url)->assertSessionHas('track_success');
        $this->post($url)->assertSessionHas('track_error', 'Tu cita ya estaba cancelada.');
        $this->assertSame('cancelada', $appointment->fresh()->status);
    }

    // --- Errores y observabilidad -------------------------------------------------------------

    public function test_every_response_carries_a_request_id(): void
    {
        $id = $this->get('/')->headers->get('X-Request-Id');
        $this->assertNotEmpty($id);
        $this->assertSame('abc12345-proxy', $this->get('/', ['X-Request-Id' => 'abc12345-proxy'])->headers->get('X-Request-Id'));
        // Un id con caracteres raros no se acepta (no se puede inyectar en los registros).
        $this->assertNotSame('<script>', $this->get('/', ['X-Request-Id' => '<script>'])->headers->get('X-Request-Id'));
    }

    public function test_server_errors_do_not_leak_details(): void
    {
        config(['app.debug' => false]);
        \Illuminate\Support\Facades\Route::get('/__falla', fn () => throw new \RuntimeException('SQLSTATE secreto /var/www/.env'));
        \Illuminate\Support\Facades\Route::get('/__falla-json', fn () => throw new \RuntimeException('SQLSTATE secreto'));

        $html = $this->get('/__falla')->assertStatus(500);
        $html->assertSee('Algo falló de nuestro lado')->assertDontSee('SQLSTATE')->assertDontSee('.env');

        $this->getJson('/__falla-json')->assertStatus(500)
            ->assertJsonMissingPath('exception')
            ->assertJsonStructure(['message', 'request_id'])
            ->assertDontSee('SQLSTATE');
    }

    public function test_error_pages_render_without_database(): void
    {
        foreach ([401, 403, 419, 429, 500, 503] as $code) {
            $html = view('errors.'.$code)->render();
            $this->assertStringContainsString('<h1>', $html);
            $this->assertStringNotContainsString('SiteSetting', $html);
        }
    }

    public function test_browser_errors_are_logged_without_tokens_or_query_strings(): void
    {
        Log::shouldReceive('channel')->with('browser')->once()->andReturnSelf();
        Log::shouldReceive('warning')->once()->withArgs(function ($message, $context) {
            return $message === 'navegador.error'
                && str_contains($context['pagina'], '/seguimiento/cita/[token]')
                && ! str_contains($context['mensaje'], 'telefono=')
                && $context['linea'] === 12;
        });
        Log::shouldReceive('shareContext')->andReturnNull();

        $token = Str::random(40);
        $this->call('POST', '/errores-cliente', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'type' => 'error', 'message' => 'TypeError en /reservar?telefono=3001234567', 'line' => 12,
            'page' => '/seguimiento/cita/'.$token,
        ]))->assertNoContent();
    }

    public function test_client_error_endpoint_survives_garbage_and_is_rate_limited(): void
    {
        $this->call('POST', '/errores-cliente', [], [], [], ['CONTENT_TYPE' => 'application/json'], 'no es json')->assertNoContent();
        foreach (range(1, 19) as $i) {
            $this->call('POST', '/errores-cliente', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{}');
        }
        $this->call('POST', '/errores-cliente', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{}')->assertStatus(429);
    }

    public function test_audit_log_failure_never_breaks_the_action(): void
    {
        Log::shouldReceive('channel')->with('audit')->andThrow(new \RuntimeException('disco lleno'));
        Log::shouldReceive('channel')->andReturnSelf();
        Log::shouldReceive('shareContext', 'info', 'notice', 'warning', 'error', 'debug')->andReturnNull();

        $barber = $this->barber();
        $appointment = Appointment::create(['barber_id' => $barber->id, 'client_name' => 'Ana', 'appointment_date' => '2026-11-19', 'appointment_time' => '11:00', 'status' => 'pendiente']);

        $this->actingAs($this->admin())->patch(route('appointments.status', $appointment), ['status' => 'confirmada'])->assertRedirect();
        $this->assertSame('confirmada', $appointment->fresh()->status);
    }

    public function test_health_reports_a_stopped_scheduler(): void
    {
        $this->getJson('/salud')->assertStatus(503)->assertJson(['ok' => false, 'base_de_datos' => true, 'tarea_programada' => false]);

        $this->artisan('barberia:tick')->assertSuccessful();
        $this->getJson('/salud')->assertOk()->assertJson(['ok' => true]);

        $this->travel(10)->minutes();
        $this->getJson('/salud')->assertStatus(503);
    }

    public function test_a_failing_tick_step_does_not_stop_the_notifications(): void
    {
        $this->mock(\App\Services\TurnQueueService::class, function ($mock) {
            $mock->shouldReceive('advanceAll')->andThrow(new \RuntimeException('fila rota'));
            $mock->shouldReceive('remindUpcoming')->once();
        });
        $this->mock(\App\Services\ClientNotifier::class, fn ($mock) => $mock->shouldReceive('processDue')->once()->andReturn(0));

        $this->artisan('barberia:tick')->assertFailed();
    }

    public function test_phone_numbers_are_masked_in_logs(): void
    {
        $this->assertSame('***4567', \App\Support\Pii::phone('+57 300 123 4567'));
        $this->assertNull(\App\Support\Pii::phone(''));
    }
}
