<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\ClientNotification;
use App\Models\ConsentRecord;
use App\Models\Review;
use App\Models\Service;
use App\Models\SurveyAnswer;
use App\Models\SurveyInvitation;
use App\Models\SurveyQuestion;
use App\Models\SurveySession;
use App\Models\Turn;
use App\Models\User;
use App\Services\Surveys\SurveyService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Encuestas de satisfacción y opiniones verificadas (las 15 pruebas pedidas y algunas más). */
class SurveySystemTest extends TestCase
{
    use RefreshDatabase;

    private const APIKEY = 'secreto-webhook';

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-18 15:00:00'));
        // Estas pruebas recorren el motor de la encuesta también por la web. En producción la web está
        // apagada (solo WhatsApp); ese modo se prueba aparte en los tests "only_whatsapp".
        config(['encuestas.whatsapp' => true, 'encuestas.web' => true]);
    }

    public function test_only_whatsapp_the_invitation_goes_by_whatsapp_and_asks_inside_the_chat(): void
    {
        config(['encuestas.web' => false]);
        $appointment = $this->appointment();
        $appointment->update(['status' => 'atendida']);

        $this->assertSame(1, SurveyInvitation::count());
        $invite = ClientNotification::where('type', 'survey_invite')->get();
        $this->assertSame(['whatsapp'], $invite->pluck('channel')->all(), 'Sin aviso del navegador: solo WhatsApp.');
        $this->assertStringContainsString('del *1 al 5*', $invite->first()->body);
        $this->assertStringNotContainsString('/encuesta/', $invite->first()->body, 'Sin enlace a la encuesta web.');
    }

    public function test_only_whatsapp_no_invitation_without_whatsapp_consent(): void
    {
        config(['encuestas.web' => false]);
        $this->appointment(['whatsapp_consent' => false])->update(['status' => 'atendida']);

        $this->assertSame(0, SurveyInvitation::count());
        $this->assertSame(0, ClientNotification::where('type', 'survey_invite')->count());
    }

    public function test_only_whatsapp_the_web_survey_link_shows_a_whatsapp_notice(): void
    {
        $appointment = $this->finishedAndSent();
        config(['encuestas.web' => false]);

        $this->get($this->url($appointment))->assertNotFound()->assertSee('Cuéntanos tu experiencia por WhatsApp');
        $this->postJson(route('survey.answer', ['type' => 'cita', 'token' => $appointment->public_token]), ['question' => 'experiencia', 'value' => 5])
            ->assertNotFound();
    }

    private function appointment(array $extra = []): Appointment
    {
        $barber = User::factory()->create(['role' => 'barbero', 'attention_mode' => 'citas', 'active' => true, 'name' => 'Carlos']);
        $service = Service::create(['name' => 'Corte clásico', 'price' => 18000, 'duration_minutes' => 30, 'active' => true]);

        return Appointment::create($extra + [
            'barber_id' => $barber->id, 'service_id' => $service->id, 'client_name' => 'Ana Gómez', 'client_phone' => '3001234567',
            'whatsapp_consent' => true, 'appointment_date' => '2026-11-18', 'appointment_time' => '14:00', 'status' => 'confirmada',
        ]);
    }

    /** Servicio atendido y pasado el margen: la invitación queda enviada. */
    private function finishedAndSent(array $extra = []): Appointment
    {
        $appointment = $this->appointment($extra);
        $appointment->update(['status' => 'atendida']);
        $this->travel(31)->minutes();
        app(SurveyService::class)->tick();

        return $appointment->fresh();
    }

    private function url(Appointment $a, string $name = 'survey.show'): string
    {
        return route($name, ['type' => 'cita', 'token' => $a->public_token]);
    }

    private function answerWeb(Appointment $a, string $key, $value)
    {
        return $this->postJson($this->url($a, 'survey.answer'), ['question' => $key, 'value' => $value]);
    }

    private function fullHappyPath(Appointment $a, string $publish = 'si_nombre', string $comment = 'Excelente corte, muy buena atención de Carlos.')
    {
        $this->answerWeb($a, 'experiencia', 5)->assertOk();
        $this->answerWeb($a, 'lo_mejor', 'corte')->assertOk();
        $this->answerWeb($a, 'atencion_barbero', 5)->assertOk();
        $this->answerWeb($a, 'expectativas', 'si')->assertOk();
        $this->answerWeb($a, 'recomendaria', 'si')->assertOk();
        $this->answerWeb($a, 'comentario', $comment)->assertOk();

        return $this->answerWeb($a, 'publicar', $publish);
    }

    // 1. Servicio sin terminar → no hay encuesta.
    public function test_no_survey_while_the_service_is_not_finished(): void
    {
        $appointment = $this->appointment();
        $appointment->update(['status' => 'confirmada']);
        $this->assertSame(0, SurveyInvitation::count());
        $this->get($this->url($appointment))->assertNotFound();
    }

    // 2. Servicio terminado → invitación elegible, programada con margen y con su aviso.
    public function test_finishing_a_service_creates_an_eligible_invitation(): void
    {
        $appointment = $this->appointment();
        $appointment->update(['status' => 'atendida']);

        $invitation = SurveyInvitation::firstOrFail();
        $this->assertSame('programada', $invitation->status);
        $this->assertTrue($invitation->send_after->eq(now()->addMinutes(30)));
        $this->assertSame(2, ClientNotification::where('type', 'survey_invite')->count()); // push + WhatsApp (tiene consentimiento)

        // Si el cliente abre el enlace antes del aviso, igual puede responder (el servicio ya terminó).
        $this->get($this->url($appointment))->assertOk()->assertSee('¿Cómo te fue');
        $this->travel(31)->minutes();
        app(SurveyService::class)->tick();
        $this->assertSame('enviada', $invitation->fresh()->status);
        $this->get($this->url($appointment))->assertOk()->assertSee('¿Cómo te fue');
    }

    public function test_whatsapp_invite_is_not_queued_without_consent(): void
    {
        $this->appointment(['whatsapp_consent' => false])->update(['status' => 'atendida']);
        $this->assertSame(['push'], ClientNotification::where('type', 'survey_invite')->pluck('channel')->all());
    }

    public function test_turns_and_automatic_finishing_also_invite(): void
    {
        $barber = User::factory()->create(['role' => 'barbero', 'attention_mode' => 'llegada', 'active' => true]);
        $turn = Turn::create(['barber_id' => $barber->id, 'client_name' => 'Luis', 'client_phone' => '3005550000', 'turn_date' => now()->toDateString(), 'turn_number' => 1, 'status' => 'atendiendo']);
        $turn->update(['status' => 'atendido']);
        $this->assertSame(1, SurveyInvitation::where('subject_type', 'turn')->count());
    }

    public function test_reverting_a_finished_service_cancels_the_invitation(): void
    {
        $appointment = $this->appointment();
        $appointment->update(['status' => 'atendida']);
        $appointment->update(['status' => 'cancelada']); // el cliente no llegó: se corrige

        $this->assertSame('cancelada', SurveyInvitation::firstOrFail()->status);
        $this->assertSame(0, ClientNotification::where('type', 'survey_invite')->where('status', ClientNotification::PENDING)->count());
    }

    // 3. Ya se envió → no se duplica.
    public function test_the_invitation_is_never_duplicated(): void
    {
        $appointment = $this->appointment();
        $appointment->update(['status' => 'atendida']);
        app(SurveyService::class)->invite($appointment->fresh());
        app(SurveyService::class)->statusChanged($appointment->fresh(), 'confirmada');

        $this->assertSame(1, SurveyInvitation::count());
        $this->assertSame(2, ClientNotification::where('type', 'survey_invite')->count());
    }

    // 4. Respuesta web → se guarda; 8. con autorización → se publica.
    public function test_web_answers_are_saved_and_an_authorized_review_is_published(): void
    {
        $appointment = $this->finishedAndSent();
        $this->fullHappyPath($appointment)->assertOk()->assertJson(['status' => 'completada', 'published' => true]);

        $session = SurveySession::firstOrFail();
        $this->assertSame('completada', $session->status);
        $this->assertSame(7, $session->answers()->count()); // "qué mejorar" no aplicó
        $this->assertSame('completada', SurveyInvitation::first()->status);

        $review = Review::firstOrFail();
        $this->assertTrue($review->approved);
        $this->assertSame('encuesta_web', $review->source);
        $this->assertSame('Ana G.', $review->publicName());
        $this->assertSame(1, ConsentRecord::where('purpose', 'publicacion_resena')->where('subject_type', 'review')->count());

        $this->get(route('public.reviews'))->assertSee('Excelente corte, muy buena atención de Carlos.')->assertSee('Ana G.')->assertDontSee('Ana Gómez')->assertSee('Servicio verificado');
        $this->get('/')->assertSee('Excelente corte, muy buena atención de Carlos.');
    }

    public function test_anonymous_publication_hides_the_name(): void
    {
        $appointment = $this->finishedAndSent();
        $this->fullHappyPath($appointment, 'si_anonimo');

        $this->get(route('public.reviews'))->assertSee('Cliente verificado')->assertDontSee('Ana G.');
    }

    // 9. Sin autorización → queda privada.
    public function test_without_authorization_the_review_stays_private(): void
    {
        $appointment = $this->finishedAndSent();
        $this->fullHappyPath($appointment, 'no')->assertJson(['status' => 'completada', 'published' => null]);

        $this->assertSame(0, Review::count());
        $this->get(route('public.reviews'))->assertDontSee('Excelente corte, muy buena atención de Carlos.');
    }

    // 7. Sin opiniones → la sección no se renderiza.
    public function test_without_reviews_the_public_section_is_not_rendered(): void
    {
        $this->get('/')->assertOk()->assertDontSee('cx-proof', false);
        $this->get(route('public.reviews'))->assertDontSee('class="rv-score"', false);
    }

    // 10. Opinión negativa → se conserva y se publica igual (misma moderación).
    public function test_negative_reviews_are_kept_and_count_in_the_average_but_their_card_is_not_shown(): void
    {
        $appointment = $this->finishedAndSent();
        $this->answerWeb($appointment, 'experiencia', 1)->assertOk();
        $this->answerWeb($appointment, 'lo_mejor', null)->assertOk();
        $this->answerWeb($appointment, 'atencion_barbero', 2)->assertOk();
        $this->answerWeb($appointment, 'expectativas', 'no')->assertOk()->assertJsonFragment(['key' => 'que_mejorar']);
        $this->answerWeb($appointment, 'que_mejorar', 'Me dejaron el lado izquierdo disparejo.')->assertOk();
        $this->answerWeb($appointment, 'recomendaria', 'no')->assertOk();
        $this->answerWeb($appointment, 'comentario', 'El corte quedó disparejo y esperé mucho.')->assertOk();
        // Se aprueba con las mismas reglas, pero la tarjeta solo se muestra con 4 o 5 estrellas:
        // al cliente no se le dice que ya aparece en la página.
        $this->answerWeb($appointment, 'publicar', 'si_nombre')->assertJson(['published' => false]);

        $review = Review::firstOrFail();
        $this->assertSame(1, $review->rating);
        $this->assertTrue($review->approved);
        $this->get(route('public.reviews'))->assertDontSee('El corte quedó disparejo y esperé mucho.');
        // El equipo recibe un aviso para reaccionar.
        $this->assertDatabaseHas('staff_notifications', ['type' => 'survey_attention']);
        // La respuesta interna ("qué mejorar") nunca se publica.
        $this->get(route('public.reviews'))->assertDontSee('lado izquierdo disparejo');
    }

    public function test_objective_moderation_holds_reviews_with_private_data_or_links(): void
    {
        $appointment = $this->finishedAndSent();
        $this->fullHappyPath($appointment, 'si_nombre', 'Llámenme al 300 555 1234 o vean www.mipromo.com')->assertJson(['published' => false]);

        $review = Review::firstOrFail();
        $this->assertFalse($review->approved);
        $this->assertSame('revision', $review->moderation_status);
        $this->get(route('public.reviews'))->assertDontSee('mipromo');
    }

    public function test_admin_cannot_hide_a_review_without_an_objective_reason(): void
    {
        $appointment = $this->finishedAndSent();
        $this->fullHappyPath($appointment);
        $admin = User::factory()->create(['role' => 'admin', 'active' => true]);
        $review = Review::firstOrFail();

        $this->actingAs($admin)->post(route('surveys.reviews.moderate', $review), ['action' => 'ocultar', 'reason' => 'No me gustó'])->assertSessionHasErrors('reason');
        $this->assertTrue($review->fresh()->approved);

        $this->actingAs($admin)->post(route('surveys.reviews.moderate', $review), ['action' => 'retirar'])->assertSessionHas('success');
        $this->assertFalse($review->fresh()->approved);
        $this->assertNotNull(ConsentRecord::where('subject_type', 'review')->first()->revoked_at);
        $this->actingAs($admin)->post(route('surveys.reviews.moderate', $review), ['action' => 'publicar'])->assertSessionHas('error');
    }

    public function test_admin_can_delete_a_published_review(): void
    {
        $appointment = $this->finishedAndSent();
        $this->fullHappyPath($appointment);
        $admin = User::factory()->create(['role' => 'admin', 'active' => true]);
        $review = Review::firstOrFail();
        $this->assertTrue($review->approved);

        $this->actingAs($admin)->get(route('surveys.reviews'))->assertOk()->assertSee(route('surveys.reviews.destroy', $review), false);
        $this->actingAs($admin)->delete(route('surveys.reviews.destroy', $review))
            ->assertRedirect(route('surveys.reviews'))->assertSessionHas('success', 'Opinión eliminada.');

        $this->assertNull(Review::find($review->id));
        // Las respuestas de la encuesta se conservan; la autorización queda como revocada.
        $this->assertSame(1, \App\Models\SurveySession::count());
        $this->assertNotNull(ConsentRecord::where('subject_type', 'review')->where('subject_id', $review->id)->first()->revoked_at);
        $this->get(route('public.reviews'))->assertDontSee('class="rv-score"', false);
    }

    public function test_barbers_cannot_delete_reviews(): void
    {
        $appointment = $this->finishedAndSent();
        $this->fullHappyPath($appointment);
        $barber = User::factory()->create(['role' => 'barbero', 'active' => true]);

        $this->actingAs($barber)->delete(route('surveys.reviews.destroy', Review::firstOrFail()))->assertForbidden();
        $this->assertSame(1, Review::count());
    }

    // 6 y 15. Interrupción → conserva el progreso; lo confirmado no se pierde.
    public function test_progress_survives_interruptions(): void
    {
        $appointment = $this->finishedAndSent();
        $this->answerWeb($appointment, 'experiencia', 4)->assertOk();
        $this->answerWeb($appointment, 'lo_mejor', 'ambiente')->assertOk();

        // Vuelve más tarde (otra visita): sigue en la tercera pregunta.
        $this->travel(2)->days();
        $state = $this->getJson($this->url($appointment, 'survey.state'))->assertOk()->json();
        $this->assertSame('atencion_barbero', $state['next']);
        $this->assertSame(2, $state['answered']);
        $this->get($this->url($appointment))->assertSee('Continuar');
    }

    // 14. Eventos duplicados → sin respuestas repetidas.
    public function test_duplicate_submissions_do_not_create_duplicate_answers(): void
    {
        $appointment = $this->finishedAndSent();
        $this->answerWeb($appointment, 'experiencia', 4);
        $this->answerWeb($appointment, 'experiencia', 4);
        $this->answerWeb($appointment, 'experiencia', 5); // corrección

        $this->assertSame(1, SurveyAnswer::where('question_key', 'experiencia')->count());
        $this->assertSame('5', SurveyAnswer::where('question_key', 'experiencia')->value('value'));
    }

    public function test_invalid_answers_are_rejected(): void
    {
        $appointment = $this->finishedAndSent();
        $this->answerWeb($appointment, 'experiencia', 9)->assertStatus(422);
        $this->answerWeb($appointment, 'experiencia', null)->assertStatus(422); // obligatoria
        $this->answerWeb($appointment, 'que_mejorar', 'texto')->assertStatus(422); // no aplica todavía
        $this->answerWeb($appointment, 'expectativas', 'quizas')->assertStatus(422);
        $this->assertSame(0, SurveyAnswer::count());
    }

    public function test_completed_surveys_cannot_be_changed(): void
    {
        $appointment = $this->finishedAndSent();
        $this->fullHappyPath($appointment);
        $this->answerWeb($appointment, 'experiencia', 1)->assertStatus(422);
        $this->assertSame('5', SurveyAnswer::where('question_key', 'experiencia')->value('value'));
    }

    // 11. Identificadores modificados → sin acceso.
    public function test_guessing_ids_or_tokens_gives_no_access(): void
    {
        $appointment = $this->finishedAndSent();
        $this->get('/encuesta/cita/'.$appointment->id)->assertNotFound();
        $this->get(route('survey.show', ['type' => 'cita', 'token' => Str::random(40)]))->assertNotFound();
        $this->get(route('survey.show', ['type' => 'turno', 'token' => $appointment->public_token]))->assertNotFound();
        $session = SurveySession::first();
        $this->assertNull($session);
        $this->get('/encuestas')->assertRedirect(route('login'));
        $this->getJson('/encuestas/opiniones')->assertUnauthorized();
    }

    public function test_expired_invitations_can_still_be_answered_without_error_page(): void
    {
        $appointment = $this->finishedAndSent();
        $this->travel(15)->days();
        app(SurveyService::class)->tick();
        $this->assertSame('vencida', SurveyInvitation::first()->status);
        $this->get($this->url($appointment))->assertOk()->assertSee('¿Cómo te fue')->assertDontSee('no está en la carta');
        $this->fullHappyPath($appointment)->assertOk();
        $this->assertSame('completada', SurveyInvitation::first()->status);
    }

    public function test_unavailable_survey_shows_friendly_notice_instead_of_error_page(): void
    {
        $appointment = $this->appointment();
        $appointment->update(['status' => 'confirmada']);
        $this->get($this->url($appointment))->assertNotFound()
            ->assertSee('Esta encuesta no está disponible')->assertDontSee('no está en la carta');
        // Enlace recortado al copiarlo: mismo aviso.
        $this->get('/encuesta/cita/'.substr($appointment->public_token, 0, 20))->assertNotFound()
            ->assertSee('Esta encuesta no está disponible')->assertDontSee('no está en la carta');
    }

    // 12. Cambiar preguntas → las encuestas empezadas conservan su versión.
    public function test_editing_questions_does_not_change_started_surveys(): void
    {
        $appointment = $this->finishedAndSent();
        $this->answerWeb($appointment, 'experiencia', 4)->assertOk();

        $admin = User::factory()->create(['role' => 'admin', 'active' => true]);
        $question = SurveyQuestion::where('key', 'atencion_barbero')->first();
        $this->actingAs($admin)->put(route('surveys.questions.update', $question), ['text' => '¿Qué tan puntual fue tu barbero?', 'type' => 'stars', 'is_optional' => '0', 'active' => '1'])->assertRedirect();
        SurveyQuestion::where('key', 'lo_mejor')->update(['active' => false]);

        $state = $this->getJson($this->url($appointment, 'survey.state'))->json();
        $texts = array_column($state['questions'], 'text');
        $this->assertContains('¿Cómo calificarías la atención de tu barbero?', $texts);
        $this->assertNotContains('¿Qué tan puntual fue tu barbero?', $texts);
        $this->assertContains('lo_mejor', array_column($state['questions'], 'key'));

        // Una encuesta nueva sí usa la versión nueva.
        $this->auth = null;
        $other = $this->finishedAndSent(['client_phone' => '3009998888', 'appointment_time' => '15:00']);
        $newTexts = array_column($this->getJson($this->url($other, 'survey.state'))->json('questions'), 'text');
        $this->assertContains('¿Qué tan puntual fue tu barbero?', $newTexts);
    }

    public function test_admin_can_create_reorder_and_condition_questions(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'active' => true]);
        $this->actingAs($admin)->post(route('surveys.questions.store'), [
            'text' => '¿Volverías con el mismo barbero?', 'type' => 'choice', 'options_text' => "si | Sí\nno | No",
            'is_optional' => '1', 'active' => '1', 'condition_question' => 'recomendaria', 'condition_operator' => 'in', 'condition_values' => 'si, tal_vez',
        ])->assertRedirect(route('surveys.questions'));

        $q = SurveyQuestion::where('text', '¿Volverías con el mismo barbero?')->firstOrFail();
        $this->assertSame(['question' => 'recomendaria', 'operator' => 'in', 'values' => ['si', 'tal_vez']], $q->show_if);
        $this->assertSame([['value' => 'si', 'label' => 'Sí'], ['value' => 'no', 'label' => 'No']], $q->options);

        $last = SurveyQuestion::orderByDesc('sort_order')->first();
        $this->actingAs($admin)->post(route('surveys.questions.move', $q), ['direction' => 'up']);
        $this->assertNotSame($q->id, SurveyQuestion::orderByDesc('sort_order')->first()->id);
        $this->assertSame($q->id, $last->id);

        // Las preguntas especiales no cambian de tipo.
        $publish = SurveyQuestion::where('key', 'publicar')->first();
        $this->actingAs($admin)->put(route('surveys.questions.update', $publish), ['text' => '¿Podemos publicarla?', 'type' => 'text', 'options_text' => "si_nombre | Sí\nno | No", 'active' => '1']);
        $this->assertSame('consent', $publish->fresh()->type);
    }

    public function test_admin_pages_render_with_and_without_data(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'active' => true]);
        $this->actingAs($admin)->get(route('surveys.index'))->assertOk()->assertSee('Aún sin respuestas');

        $appointment = $this->finishedAndSent();
        $this->fullHappyPath($appointment);
        $session = SurveySession::first();

        $this->actingAs($admin)->get(route('surveys.index'))->assertOk()->assertSee('5,0 / 5')->assertSee('Muestra insuficiente');
        $this->actingAs($admin)->get(route('surveys.show', $session))->assertOk()->assertSee('Excelente corte');
        $this->actingAs($admin)->get(route('surveys.reviews'))->assertOk();
        $this->actingAs($admin)->get(route('surveys.questions'))->assertOk();
        $this->actingAs($admin)->get(route('surveys.questions.create'))->assertOk();
        $this->actingAs($admin)->get(route('surveys.index', ['estado' => 'atencion']))->assertOk();
    }

    // 5. WhatsApp: se responde en el chat y se sincroniza con la misma base.
    public function test_whatsapp_answers_sync_with_the_same_survey(): void
    {
        $this->whatsappConfig();
        $appointment = $this->finishedAndSent();

        $this->write('5')->assertOk();            // experiencia (empieza la encuesta)
        $this->write('2')->assertOk();            // lo mejor → la atención
        $this->write('5')->assertOk();            // atención del barbero
        $this->write('si')->assertOk();           // expectativas
        $this->write('1')->assertOk();            // recomendaría → Sí
        $this->write('Muy buen servicio, volveré.')->assertOk();
        $this->write('1')->assertOk();            // publicar con nombre

        $session = SurveySession::firstOrFail();
        $this->assertSame('whatsapp', $session->channel);
        $this->assertSame('completada', $session->status);
        $this->assertSame('atencion', $session->answerFor('lo_mejor')->value);
        $this->assertSame('encuesta_whatsapp', Review::firstOrFail()->source);
        $this->assertTrue(Review::first()->approved);

        // La web muestra la misma encuesta ya respondida.
        $this->getJson($this->url($appointment, 'survey.state'))->assertJson(['status' => 'completada']);
    }

    public function test_whatsapp_survey_can_go_back_skip_and_pause_without_hijacking_the_bot(): void
    {
        $this->whatsappConfig();
        $this->finishedAndSent();

        $this->write('4');
        $this->write('omitir');                    // lo mejor es opcional
        $this->write('atras');                     // vuelve a "lo mejor"
        $this->assertNull(SurveySession::first()->fresh('answers')->answerFor('lo_mejor'));
        $this->write('hola');                      // no es respuesta: lo atiende el bot, la encuesta queda en pausa
        $this->assertSame('en_curso', SurveySession::first()->status);
        $this->assertSame(1, SurveySession::first()->answers()->count());
    }

    public function test_messages_are_not_taken_by_the_survey_when_disabled(): void
    {
        $this->whatsappConfig();
        config(['encuestas.whatsapp' => false]);
        $this->finishedAndSent();
        $this->write('5');
        $this->assertSame(0, SurveySession::count());
    }

    // 13. WhatsApp falla → la aplicación sigue funcionando.
    public function test_whatsapp_failure_does_not_break_the_service_or_the_survey(): void
    {
        config(['services.whatsapp.driver' => 'evolution', 'services.evolution.url' => 'https://evolution.test', 'services.evolution.apikey' => 'k', 'services.evolution.instance' => 'barberia']);
        Http::fake(['evolution.test/*' => Http::response('caído', 500)]);

        $appointment = $this->finishedAndSent();
        app(\App\Services\ClientNotifier::class)->processDue();

        $this->assertSame('atendida', $appointment->fresh()->status);
        $this->get($this->url($appointment))->assertOk();
        $this->fullHappyPath($appointment)->assertOk();
    }

    public function test_tick_runs_the_survey_step(): void
    {
        $appointment = $this->appointment();
        $appointment->update(['status' => 'atendida']);
        $this->travel(31)->minutes();
        $this->artisan('barberia:tick')->assertSuccessful();
        $this->assertSame('enviada', SurveyInvitation::first()->status);
    }

    // --------------------------------------------------------------------------------------------

    private function whatsappConfig(): void
    {
        config([
            'services.whatsapp.driver' => 'evolution', 'services.evolution.url' => 'https://evolution.test', 'services.evolution.apikey' => 'k',
            'services.evolution.webhook_secret' => self::APIKEY, 'services.evolution.instance' => 'barberia', 'services.evolution.bot_enabled' => true,
            'encuestas.whatsapp' => true,
        ]);
        Http::fake(['evolution.test/*' => Http::response(['created' => true])]);
    }

    private function write(string $text)
    {
        return $this->postJson('/webhook/evolution', [
            'event' => 'messages.upsert', 'instance' => 'barberia', 'apikey' => self::APIKEY,
            'data' => ['key' => ['remoteJid' => '573001234567@s.whatsapp.net', 'fromMe' => false, 'id' => 'MSG-'.uniqid()], 'pushName' => 'Ana', 'message' => ['conversation' => $text], 'messageTimestamp' => now()->getTimestamp()],
        ]);
    }
}
