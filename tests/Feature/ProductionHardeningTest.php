<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\ConsentRecord;
use App\Models\Review;
use App\Models\Turn;
use App\Models\User;
use App\Support\ImageSanitizer;
use Carbon\Carbon;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Endurecimiento para producción: estados, contraseñas, Ley 1581, archivos, cabeceras y ataques web. */
class ProductionHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-18 10:00:00'));
    }

    private function admin(array $extra = []): User
    {
        return User::factory()->create(['role' => 'admin', 'active' => true] + $extra);
    }

    private function barber(string $mode = 'citas'): User
    {
        return User::factory()->create(['role' => 'barbero', 'attention_mode' => $mode, 'active' => true]);
    }

    private function appointment(User $barber, array $extra = []): Appointment
    {
        return Appointment::create($extra + ['barber_id' => $barber->id, 'client_name' => 'Ana', 'client_phone' => '3001234567', 'appointment_date' => '2026-11-19', 'appointment_time' => '11:00', 'status' => 'pendiente']);
    }

    // --- Máquina de estados ------------------------------------------------------------------

    public function test_a_cancelled_appointment_cannot_jump_to_attended(): void
    {
        $appointment = $this->appointment($this->barber(), ['status' => 'cancelada']);

        $this->actingAs($this->admin())->patch(route('appointments.status', $appointment), ['status' => 'atendida'])
            ->assertSessionHas('error');
        $this->assertSame('cancelada', $appointment->fresh()->status);
    }

    public function test_reactivating_a_cancelled_appointment_rechecks_the_slot(): void
    {
        $barber = $this->barber();
        $cancelled = $this->appointment($barber, ['status' => 'cancelada', 'client_name' => 'Primera']);
        $this->appointment($barber, ['client_name' => 'Nueva dueña del horario']);

        $this->actingAs($this->admin())->patch(route('appointments.status', $cancelled), ['status' => 'pendiente'])
            ->assertSessionHas('error', 'No se puede reactivar: ese horario ya lo tiene otra cita.');
        $this->assertSame('cancelada', $cancelled->fresh()->status);
        $this->assertSame(1, Appointment::where('status', '!=', 'cancelada')->count());
    }

    public function test_reactivating_works_when_the_slot_is_free(): void
    {
        $cancelled = $this->appointment($this->barber(), ['status' => 'cancelada']);

        $this->actingAs($this->admin())->patch(route('appointments.status', $cancelled), ['status' => 'confirmada'])
            ->assertSessionHas('success');
        $this->assertSame('confirmada', $cancelled->fresh()->status);
    }

    public function test_a_cancelled_turn_cannot_be_marked_as_finished(): void
    {
        $barber = $this->barber('llegada');
        $turn = Turn::create(['barber_id' => $barber->id, 'client_name' => 'Ana', 'turn_date' => now()->toDateString(), 'turn_number' => 1, 'status' => 'cancelado']);

        $this->actingAs($this->admin())->patch(route('turns.status', $turn), ['status' => 'atendido'])->assertSessionHas('error');
        $this->assertSame('cancelado', $turn->fresh()->status);
    }

    public function test_state_rules_cover_every_status(): void
    {
        $this->assertEqualsCanonicalizing(array_keys(Appointment::STATUSES), array_keys(Appointment::TRANSITIONS));
        $this->assertEqualsCanonicalizing(array_keys(Turn::STATUSES), array_keys(Turn::TRANSITIONS));
    }

    // --- Recuperación de contraseña -----------------------------------------------------------

    public function test_password_reset_link_is_only_sent_to_active_admins_and_does_not_reveal_accounts(): void
    {
        Notification::fake();
        $admin = $this->admin(['email' => 'jefe@barberia.test']);
        $barber = $this->barber();

        foreach (['jefe@barberia.test', $barber->email, 'nadie@barberia.test'] as $email) {
            $this->post(route('password.email'), ['email' => $email])
                ->assertSessionHas('status', \App\Http\Controllers\Auth\PasswordResetController::SENT_MESSAGE);
        }

        Notification::assertSentTo($admin, ResetPassword::class);
        Notification::assertNotSentTo($barber, ResetPassword::class);
    }

    public function test_reset_token_is_single_use_expires_and_closes_sessions(): void
    {
        $admin = $this->admin();
        $token = Password::createToken($admin);
        $payload = ['token' => $token, 'email' => $admin->email, 'password' => 'NuevaClave2026', 'password_confirmation' => 'NuevaClave2026'];

        $this->post(route('password.update'), $payload)->assertRedirect(route('login'));
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('NuevaClave2026', $admin->fresh()->password));

        // El mismo enlace no sirve dos veces.
        $this->post(route('password.update'), ['password' => 'OtraClave2026', 'password_confirmation' => 'OtraClave2026'] + $payload)
            ->assertSessionHasErrors('email');

        // Un token nuevo vence a los 60 minutos.
        $expired = Password::createToken($admin);
        $this->travel(61)->minutes();
        $this->post(route('password.update'), ['token' => $expired] + $payload)->assertSessionHasErrors('email');
    }

    public function test_weak_passwords_are_rejected(): void
    {
        $admin = $this->admin();
        $token = Password::createToken($admin);

        $this->post(route('password.update'), ['token' => $token, 'email' => $admin->email, 'password' => '1234', 'password_confirmation' => '1234'])
            ->assertSessionHasErrors('password');
    }

    // --- Sesión ---------------------------------------------------------------------------------

    public function test_login_regenerates_the_session_and_logout_invalidates_it(): void
    {
        $admin = $this->admin(['password' => 'ClaveSegura2026']);
        $this->get(route('login'));
        $before = session()->getId();

        $this->post(route('login.store'), ['email' => $admin->email, 'password' => 'ClaveSegura2026'])->assertRedirect(route('dashboard'));
        $this->assertNotSame($before, session()->getId());
        $this->assertAuthenticatedAs($admin);

        $this->post(route('logout'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_requests_without_csrf_token_are_rejected(): void
    {
        // Laravel salta la verificación CSRF en pruebas; aquí se fuerza la real.
        $this->app->instance(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class, new class($this->app, $this->app['encrypter']) extends \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });
        $barber = $this->barber();

        // Sin token: 419, y no se crea nada.
        $response = $this->call('POST', route('public.booking.appointment'), ['barber_id' => $barber->id, 'client_name' => 'X']);
        $this->assertSame(419, $response->getStatusCode());
        $this->assertSame(0, Appointment::count());
    }

    // --- Ley 1581: autorizaciones y revocación --------------------------------------------------

    public function test_bookings_and_reviews_leave_proof_of_authorization(): void
    {
        $barber = $this->barber();
        $this->post(route('public.booking.appointment'), [
            'barber_id' => $barber->id, 'client_name' => 'Ana', 'client_phone' => '300 123 4567', 'payment_method' => 'efectivo',
            'appointment_date' => '2026-11-19', 'appointment_time' => '10:30', 'whatsapp_consent' => '1',
        ])->assertSessionHas('booking_success');
        $this->post(route('public.reviews.store'), ['client_name' => 'Ana', 'rating' => 5, 'comment' => 'Muy buena atención y buen corte.']);

        $appointment = Appointment::firstOrFail();
        $records = ConsentRecord::where('subject_type', 'appointment')->where('subject_id', $appointment->id)->pluck('purpose')->all();
        $this->assertEqualsCanonicalizing(['tratamiento_reserva', 'avisos_whatsapp'], $records);
        $this->assertSame(1, ConsentRecord::where('purpose', 'publicacion_resena')->where('subject_type', 'review')->count());

        $record = ConsentRecord::where('purpose', 'avisos_whatsapp')->firstOrFail();
        $this->assertSame('casilla_web', $record->channel);
        $this->assertSame(ConsentRecord::version(), $record->text_version);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $record->evidence_hash);
    }

    public function test_whatsapp_consent_is_not_recorded_when_the_box_is_unchecked(): void
    {
        $barber = $this->barber();
        $this->post(route('public.booking.appointment'), [
            'barber_id' => $barber->id, 'client_name' => 'Ana', 'client_phone' => '300 123 4567', 'payment_method' => 'efectivo',
            'appointment_date' => '2026-11-19', 'appointment_time' => '10:30', 'whatsapp_consent' => '0',
        ]);

        $this->assertSame(0, ConsentRecord::where('purpose', 'avisos_whatsapp')->count());
        $this->assertSame(1, ConsentRecord::where('purpose', 'tratamiento_reserva')->count());
    }

    public function test_whatsapp_consent_checkbox_is_never_prechecked(): void
    {
        $this->barber();
        $html = $this->get(route('public.booking'))->getContent();
        $this->assertDoesNotMatchRegularExpression('/name="whatsapp_consent"[^>]*value="1"[^>]*checked/', $html);
    }

    public function test_writing_baja_revokes_whatsapp_notices(): void
    {
        config([
            'services.whatsapp.driver' => 'evolution', 'services.evolution.url' => 'https://evolution.test', 'services.evolution.apikey' => 'k',
            'services.evolution.webhook_secret' => 'secreto-webhook', 'services.evolution.instance' => 'barberia', 'services.evolution.bot_enabled' => true,
        ]);
        Http::fake(['evolution.test/*' => Http::response(['created' => true])]);
        $appointment = $this->appointment($this->barber(), ['whatsapp_consent' => true]);
        ConsentRecord::grant($appointment, 'avisos_whatsapp', 'casilla_web');

        $this->postJson('/webhook/evolution', [
            'event' => 'messages.upsert', 'instance' => 'barberia', 'apikey' => 'secreto-webhook',
            'data' => ['key' => ['remoteJid' => '573001234567@s.whatsapp.net', 'fromMe' => false, 'id' => 'MSG-BAJA'], 'pushName' => 'Ana', 'message' => ['conversation' => 'BAJA'], 'messageTimestamp' => now()->getTimestamp()],
        ])->assertOk();

        $this->assertFalse($appointment->fresh()->whatsapp_consent);
        $this->assertNotNull(ConsentRecord::where('purpose', 'avisos_whatsapp')->first()->revoked_at);
    }

    // --- Archivos --------------------------------------------------------------------------------

    public function test_gps_location_is_removed_from_photos(): void
    {
        $copy = tempnam(sys_get_temp_dir(), 'gps').'.jpg';
        copy(base_path('tests/fixtures/foto-con-gps.jpg'), $copy);
        $this->assertArrayHasKey('GPSLatitude', @exif_read_data($copy) ?: []);

        $this->assertTrue(ImageSanitizer::stripGps($copy));
        $exif = @exif_read_data($copy) ?: [];
        $this->assertArrayNotHasKey('GPSLatitude', $exif);
        $this->assertSame('PruebaCam', $exif['Make'] ?? null); // el resto del EXIF queda
        $this->assertFalse(ImageSanitizer::stripGps($copy));   // segunda pasada: nada que hacer
        @unlink($copy);
    }

    public function test_svg_and_disguised_files_are_rejected_as_images(): void
    {
        $rule = new \App\Rules\ImageUpload;
        $svg = tempnam(sys_get_temp_dir(), 'svg');
        file_put_contents($svg, '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
        $php = tempnam(sys_get_temp_dir(), 'php');
        file_put_contents($php, '<?php echo "hola";');

        foreach ([[$svg, 'logo.svg'], [$php, 'foto.jpg']] as [$path, $name]) {
            $failed = false;
            $rule->validate('image', new \Illuminate\Http\UploadedFile($path, $name, null, null, true), function () use (&$failed) { $failed = true; });
            $this->assertTrue($failed, $name.' debió rechazarse');
        }
    }

    // --- Cabeceras, XSS, inyección, CORS ---------------------------------------------------------

    public function test_security_headers_are_sent(): void
    {
        $response = $this->get('/');
        $response->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $this->assertStringContainsString("object-src 'none'", $response->headers->get('Content-Security-Policy'));
        $this->assertStringContainsString("frame-ancestors 'self'", $response->headers->get('Content-Security-Policy'));
        $this->assertStringContainsString("default-src 'self'", $response->headers->get('Content-Security-Policy-Report-Only'));
        $this->assertStringContainsString('camera=()', $response->headers->get('Permissions-Policy'));
    }

    public function test_review_content_is_escaped(): void
    {
        Review::create(['client_name' => '<img src=x onerror=alert(1)>', 'rating' => 5, 'comment' => '<script>alert("xss")</script> muy bueno', 'approved' => true]);

        $html = $this->get(route('public.reviews'))->assertOk()->getContent();
        $this->assertStringNotContainsString('<script>alert("xss")</script>', $html);
        $this->assertStringNotContainsString('<img src=x onerror', $html);
    }

    public function test_reviews_strip_html_when_saved(): void
    {
        $this->post(route('public.reviews.store'), ['client_name' => '<b>Ana</b>', 'rating' => 5, 'comment' => '<script>x()</script>Excelente servicio siempre.']);
        $review = Review::firstOrFail();
        $this->assertSame('Ana', $review->client_name);
        $this->assertStringNotContainsString('<script>', $review->comment);
    }

    public function test_sql_injection_attempts_are_rejected_by_validation(): void
    {
        $this->barber();
        $this->getJson(route('public.booking.slots', ['barber_id' => "1 OR 1=1", 'date' => "2026-11-19' OR '1'='1"]))->assertStatus(422);
        $this->get('/seguimiento/cita/'.Str::random(39)."'")->assertNotFound();
    }

    public function test_cors_is_not_open_to_other_sites(): void
    {
        $response = $this->get('/reservar/horarios', ['Origin' => 'https://sitio-malicioso.test']);
        $this->assertNotSame('*', $response->headers->get('Access-Control-Allow-Origin'));
        $this->assertNotSame('https://sitio-malicioso.test', $response->headers->get('Access-Control-Allow-Origin'));
    }

    public function test_public_endpoints_are_rate_limited(): void
    {
        foreach (range(1, 60) as $i) {
            $this->getJson(route('public.booking.slots'));
        }
        $this->getJson(route('public.booking.slots'))->assertStatus(429);
    }
}
