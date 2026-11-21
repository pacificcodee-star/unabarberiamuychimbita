<?php

namespace App\Services\Surveys;

use App\Models\Appointment;
use App\Models\ConsentRecord;
use App\Models\Review;
use App\Models\StaffNotification;
use App\Models\SurveyAnswer;
use App\Models\SurveyInvitation;
use App\Models\SurveyQuestion;
use App\Models\SurveySession;
use App\Models\Turn;
use App\Services\ClientNotifier;
use App\Support\ReviewModerator;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Encuesta de satisfacción después del servicio, la misma para la web y para WhatsApp.
 *
 * Flujo: servicio atendido → invitación programada (sale N minutos después, solo si sigue atendido)
 * → el cliente responde pregunta por pregunta (cada respuesta se guarda al instante)
 * → al terminar: si autorizó publicar y pasó la moderación objetiva, su opinión sale en la web.
 */
class SurveyService
{
    /** Estado que significa "servicio terminado" en cada modelo. */
    public const DONE = ['appointment' => 'atendida', 'turn' => 'atendido'];

    /** Preguntas con significado especial (sus claves no se pueden cambiar en el panel). */
    public const KEY_RATING = 'experiencia';

    public const KEY_COMMENT = 'comentario';

    public const KEY_PUBLISH = 'publicar';

    public function __construct(private ClientNotifier $notifier)
    {
    }

    // ---------------------------------------------------------------------------------------------
    // Invitaciones
    // ---------------------------------------------------------------------------------------------

    /** Lo llaman el observador de modelos y la automatización: crea o cancela según el nuevo estado. */
    public function statusChanged(Appointment|Turn $subject, ?string $previous): void
    {
        try {
            if ($this->isDone($subject)) {
                $this->invite($subject);
            } elseif ($previous !== null && $previous === (self::DONE[$subject->getMorphClass()] ?? null)) {
                $this->cancel($subject);
            }
        } catch (Throwable $e) {
            // La encuesta nunca debe frenar el cierre del servicio ni el cobro.
            report($e);
        }
    }

    public function isDone(Appointment|Turn $subject): bool
    {
        return $subject->status === (self::DONE[$subject->getMorphClass()] ?? null);
    }

    public function invite(Appointment|Turn $subject): ?SurveyInvitation
    {
        if (! config('encuestas.enabled') || ! $this->isDone($subject)) {
            return null;
        }

        $existing = SurveyInvitation::where('subject_type', $subject->getMorphClass())->where('subject_id', $subject->getKey())->first();
        if ($existing) {
            // Ya había una: solo se reactiva si se canceló (p. ej. lo marcaron atendido otra vez).
            if ($existing->status === 'cancelada') {
                $existing->update(['status' => 'programada', 'send_after' => now()->addMinutes($this->delay()), 'expires_at' => now()->addDays($this->expireDays())]);
                $this->queueInvitation($subject, $existing);
            }

            return $existing;
        }

        $date = $subject instanceof Turn ? $subject->turn_date : $subject->appointment_date;
        try {
            $invitation = SurveyInvitation::create([
                'subject_type' => $subject->getMorphClass(),
                'subject_id' => $subject->getKey(),
                'barber_id' => $subject->barber_id,
                'service_id' => $subject->service_id,
                'service_date' => $date ?? now()->toDateString(),
                'status' => 'programada',
                'send_after' => now()->addMinutes($this->delay()),
                'expires_at' => now()->addDays($this->expireDays()),
            ]);
        } catch (UniqueConstraintViolationException) {
            return SurveyInvitation::where('subject_type', $subject->getMorphClass())->where('subject_id', $subject->getKey())->first();
        }

        $this->queueInvitation($subject, $invitation);

        return $invitation;
    }

    /** El servicio dejó de estar atendido (p. ej. el cliente no llegó): no se invita. */
    public function cancel(Appointment|Turn $subject): void
    {
        SurveyInvitation::where('subject_type', $subject->getMorphClass())->where('subject_id', $subject->getKey())
            ->whereIn('status', ['programada', 'enviada'])
            ->update(['status' => 'cancelada', 'updated_at' => now()]);
        $this->notifier->cancelPending($subject, ['survey_invite']);
    }

    /** Lo llama la tarea programada: marca enviadas, vence las viejas y limpia las incompletas antiguas. */
    public function tick(): int
    {
        $sent = SurveyInvitation::where('status', 'programada')->where('send_after', '<=', now())
            ->update(['status' => 'enviada', 'sent_at' => now(), 'updated_at' => now()]);

        SurveyInvitation::whereIn('status', ['programada', 'enviada'])->where('expires_at', '<', now())
            ->update(['status' => 'vencida', 'updated_at' => now()]);

        // Conservación: las encuestas que nadie terminó se borran pasado el plazo.
        SurveySession::where('status', 'en_curso')
            ->where('last_activity_at', '<', now()->subDays((int) config('encuestas.retention_incomplete_days', 180)))
            ->delete();

        return $sent;
    }

    private function queueInvitation(Appointment|Turn $subject, SurveyInvitation $invitation): void
    {
        $name = Str::of((string) $subject->client_name)->trim()->explode(' ')->first();
        $url = $this->surveyUrl($subject);
        $brand = config('sitio.brand_name', config('app.name'));
        $count = SurveyQuestion::active()->count();

        $body = '¡Hola'.($name ? ', '.$name : '').'! ¿Cómo te fue hoy en '.$brand.'? 💈'."\n\n"
            .'Cuéntanos en '.$count.' preguntas rápidas (menos de un minuto): '.$url;
        if (config('encuestas.whatsapp')) {
            $body .= "\n\n".'O responde aquí mismo: del *1 al 5*, ¿cómo calificarías tu experiencia?';
        }
        $body .= "\n\n".'Tu opinión solo se publica si tú lo autorizas.';

        $this->notifier->queue(
            $subject,
            'survey_invite',
            '¿Cómo te fue hoy?',
            $body,
            $invitation->send_after,
            ttlMinutes: 24 * 60,
            url: route('survey.show', $this->routeParams($subject), false),
        );
    }

    // ---------------------------------------------------------------------------------------------
    // Acceso
    // ---------------------------------------------------------------------------------------------

    public function routeParams(Appointment|Turn $subject): array
    {
        return ['type' => $subject instanceof Turn ? 'turno' : 'cita', 'token' => $subject->public_token];
    }

    public function surveyUrl(Appointment|Turn $subject): string
    {
        return route('survey.show', $this->routeParams($subject));
    }

    /** Invitación abierta para el enlace secreto del servicio, o null (404). */
    public function invitationFor(string $type, string $token): ?SurveyInvitation
    {
        $model = $type === 'turno' ? Turn::class : Appointment::class;
        $subject = $model::where('public_token', $token)->first();
        if (! $subject) {
            return null;
        }
        $invitation = SurveyInvitation::where('subject_type', $subject->getMorphClass())->where('subject_id', $subject->getKey())->first();

        return $invitation && $invitation->isOpen() && $invitation->send_after->lte(now()) ? $invitation : null;
    }

    public function session(SurveyInvitation $invitation, string $channel): SurveySession
    {
        if ($session = $invitation->session) {
            return $session;
        }
        try {
            return SurveySession::create([
                'survey_invitation_id' => $invitation->id,
                'channel' => $channel,
                'status' => 'en_curso',
                'questions' => SurveyQuestion::active()->get()->map->snapshot()->values()->all(),
                'started_at' => now(),
                'last_activity_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            return SurveySession::where('survey_invitation_id', $invitation->id)->firstOrFail();
        }
    }

    // ---------------------------------------------------------------------------------------------
    // Respuestas
    // ---------------------------------------------------------------------------------------------

    /** Preguntas que aplican según lo respondido (condiciones show_if). */
    public function visibleQuestions(SurveySession $session): array
    {
        $values = $session->answers->pluck('value', 'question_key')->all();

        return array_values(array_filter($session->questions, function (array $q) use ($values) {
            $cond = $q['show_if'] ?? null;
            if (! $cond || empty($cond['question'])) {
                return true;
            }
            $answer = $values[$cond['question']] ?? null;

            return match ($cond['operator'] ?? 'in') {
                'filled' => filled($answer),
                default => $answer !== null && in_array((string) $answer, array_map('strval', (array) ($cond['values'] ?? [])), true),
            };
        }));
    }

    public function nextQuestion(SurveySession $session): ?array
    {
        $answered = $session->answers->pluck('question_key')->all();
        foreach ($this->visibleQuestions($session) as $q) {
            if (! in_array($q['key'], $answered, true)) {
                return $q;
            }
        }

        return null;
    }

    /**
     * Guarda (o corrige) la respuesta a una pregunta. value = null → omitir (solo si es opcional).
     * Es idempotente: la misma respuesta dos veces no crea dos filas.
     */
    public function answer(SurveySession $session, string $key, mixed $value, string $channel): SurveySession
    {
        if ($session->status === 'completada') {
            throw ValidationException::withMessages(['value' => 'Esta encuesta ya está respondida. ¡Gracias!']);
        }
        $question = collect($this->visibleQuestions($session))->firstWhere('key', $key);
        if (! $question) {
            throw ValidationException::withMessages(['question' => 'Esa pregunta no está disponible.']);
        }

        [$value, $label] = $this->normalize($question, $value);

        DB::transaction(function () use ($session, $question, $value, $label, $channel) {
            SurveyAnswer::updateOrCreate(
                ['survey_session_id' => $session->id, 'question_key' => $question['key']],
                ['question_text' => Str::limit($question['text'], 250, ''), 'type' => $question['type'], 'value' => $value, 'value_label' => $label, 'channel' => $channel, 'answered_at' => now()],
            );
            // Si cambió una respuesta de la que dependían otras, se borran las que ya no aplican.
            $session->load('answers');
            $visible = array_column($this->visibleQuestions($session), 'key');
            $session->answers()->whereNotIn('question_key', $visible)->delete();
            $session->update(['last_activity_at' => now()]);
        });

        $session->load('answers');
        if ($this->nextQuestion($session) === null) {
            $this->complete($session, $channel);
        }

        return $session->fresh(['answers', 'review', 'invitation']);
    }

    /** "Atrás" en WhatsApp: borra la última respuesta para volver a contestarla. */
    public function undoLast(SurveySession $session): void
    {
        if ($session->status !== 'en_curso') {
            return;
        }
        $session->answers()->latest('answered_at')->latest('id')->first()?->delete();
        $session->load('answers');
    }

    /** @return array{0: ?string, 1: ?string} valor y texto legible */
    private function normalize(array $question, mixed $value): array
    {
        $isEmpty = $value === null || (is_string($value) && trim($value) === '');
        if ($isEmpty) {
            if (! ($question['optional'] ?? false)) {
                throw ValidationException::withMessages(['value' => 'Esta pregunta necesita una respuesta.']);
            }

            return [null, null];
        }

        switch ($question['type']) {
            case 'stars':
                $n = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 5]]);
                if ($n === false) {
                    throw ValidationException::withMessages(['value' => 'Elige de 1 a 5 estrellas.']);
                }

                return [(string) $n, $n.' de 5'];
            case 'choice':
            case 'consent':
                $option = collect($question['options'] ?? [])->firstWhere('value', (string) $value);
                if (! $option) {
                    throw ValidationException::withMessages(['value' => 'Elige una de las opciones.']);
                }

                return [(string) $option['value'], $option['label']];
            default: // text
                $text = trim(strip_tags((string) $value));
                if (mb_strlen($text) > 600) {
                    throw ValidationException::withMessages(['value' => 'Escribe máximo 600 caracteres.']);
                }
                if ($text === '') {
                    return $this->normalize($question, null);
                }

                return [$text, null];
        }
    }

    // ---------------------------------------------------------------------------------------------
    // Cierre y publicación
    // ---------------------------------------------------------------------------------------------

    public function complete(SurveySession $session, string $channel): void
    {
        $done = SurveySession::whereKey($session->id)->where('status', 'en_curso')
            ->update(['status' => 'completada', 'completed_at' => now(), 'updated_at' => now()]);
        if (! $done) {
            return; // ya estaba terminada (doble envío): nada que repetir
        }
        $session->refresh()->load(['answers', 'invitation.subject']);
        $session->invitation->update(['status' => 'completada', 'completed_at' => now()]);
        $this->notifier->cancelPending($session->invitation->subject, ['survey_invite']);

        try {
            $this->publishIfAuthorized($session, $channel);
            $this->alertIfNeeded($session);
        } catch (Throwable $e) {
            report($e); // las respuestas ya quedaron guardadas
        }
    }

    private function publishIfAuthorized(SurveySession $session, string $channel): ?Review
    {
        $consent = $session->answerFor(self::KEY_PUBLISH)?->value;
        $comment = $session->answerFor(self::KEY_COMMENT)?->value;
        $rating = (int) $session->answerFor(self::KEY_RATING)?->value;

        // Sin autorización, sin comentario o sin calificación no hay nada que publicar: queda solo para el equipo.
        if (! in_array($consent, ['si_nombre', 'si_anonimo'], true) || blank($comment) || $rating < 1) {
            return null;
        }

        $subject = $session->invitation->subject;
        $reason = ReviewModerator::check($comment);
        $publish = $reason === null && config('encuestas.auto_publish');

        $review = Review::create([
            'client_name' => Str::limit((string) $subject?->client_name, 60, ''),
            'rating' => $rating,
            'comment' => $comment,
            'approved' => $publish,
            'source' => 'encuesta_'.$channel,
            'survey_session_id' => $session->id,
            'barber_id' => $session->invitation->barber_id,
            'service_id' => $session->invitation->service_id,
            'anonymous' => $consent === 'si_anonimo',
            'moderation_status' => $publish ? 'aprobada' : ($reason ? 'revision' : 'pendiente'),
            'moderation_reason' => $reason,
            'published_at' => $publish ? now() : null,
        ]);
        ConsentRecord::grant($review, 'publicacion_resena', 'encuesta_'.$channel);

        if ($reason) {
            StaffNotification::record('survey_review', 'Opinión para revisar', 'Una opinión con autorización de publicarse quedó en revisión: '.$reason, route('surveys.reviews', ['estado' => 'revision'], false));
        }

        return $review;
    }

    /** Aviso al equipo cuando la experiencia fue mala (para reaccionar, no para ocultarla). */
    private function alertIfNeeded(SurveySession $session): void
    {
        $low = fn (string $key) => ($v = $session->answerFor($key)?->value) !== null && (int) $v > 0 && (int) $v <= 2;
        $is = fn (string $key, string $value) => $session->answerFor($key)?->value === $value;

        if ($low('experiencia') || $low('atencion_barbero') || $is('expectativas', 'no') || $is('recomendaria', 'no')) {
            $subject = $session->invitation->subject;
            StaffNotification::record(
                'survey_attention',
                'Encuesta que requiere atención',
                ($subject?->client_name ?: 'Un cliente').' no quedó satisfecho con su servicio. Revisa sus respuestas.',
                route('surveys.show', $session, false),
                $session->invitation->barber_id,
            );
        }
    }

    // ---------------------------------------------------------------------------------------------

    /** Estado para la interfaz web (sin datos personales). */
    public function state(SurveySession $session): array
    {
        $session->loadMissing('answers');
        $visible = $this->visibleQuestions($session);
        $answers = $session->answers->keyBy('question_key');
        $next = $this->nextQuestion($session);

        return [
            'status' => $session->status,
            'questions' => array_map(fn ($q) => [
                'key' => $q['key'], 'text' => $q['text'], 'help' => $q['help'] ?? null, 'type' => $q['type'],
                'options' => $q['options'] ?? [], 'optional' => (bool) ($q['optional'] ?? false),
                'answer' => $answers->has($q['key']) ? ['value' => $answers[$q['key']]->value] : null,
            ], $visible),
            'next' => $next['key'] ?? null,
            'answered' => count(array_filter($visible, fn ($q) => $answers->has($q['key']))),
            'total' => count($visible),
            'published' => $session->status === 'completada' ? $session->review?->approved : null,
            'review_status' => $session->review?->moderation_status,
        ];
    }

    private function delay(): int
    {
        return max(0, (int) config('encuestas.send_delay_minutes', 30));
    }

    private function expireDays(): int
    {
        return max(1, (int) config('encuestas.expire_days', 14));
    }
}
