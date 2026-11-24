<?php

namespace App\Services\Surveys;

use App\Models\Appointment;
use App\Models\SurveyInvitation;
use App\Models\SurveySession;
use App\Models\Turn;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * La misma encuesta, pero dentro del chat de WhatsApp: una pregunta por mensaje.
 * Usa el mismo motor (SurveyService) y la misma base que la web, así se puede empezar en un canal
 * y terminar en el otro.
 *
 * Solo toma el mensaje si es una respuesta válida a la pregunta en curso (o un comando de la
 * encuesta). Cualquier otra cosa ("hola", "reservar"…) sigue al bot normal y la encuesta queda
 * en pausa para retomarla después.
 */
class SurveyConversation
{
    private const SKIP = ['omitir', 'saltar', 'siguiente', 'no quiero responder', 'paso'];

    private const BACK = ['atras', 'volver', 'anterior', 'corregir'];

    private const PAUSE = ['salir', 'despues', 'luego', 'mas tarde', 'pausa'];

    private const START = ['encuesta', 'opinar', 'calificar'];

    private const BOT_WORDS = ['hola', 'menu', 'reservar', 'reserva', 'cita', 'turno', 'cancelar', 'asesor', 'horario', 'precio', 'precios', 'servicios', 'ayuda'];

    public function __construct(private SurveyService $surveys)
    {
    }

    /** Devuelve la respuesta del bot, o null si el mensaje no es para la encuesta. */
    public function handle(string $phone, string $text): ?string
    {
        if (! config('encuestas.enabled') || ! config('encuestas.whatsapp')) {
            return null;
        }
        $invitation = $this->openInvitation($phone);
        if (! $invitation) {
            return null;
        }

        $t = $this->normalize($text);
        $session = $invitation->session;

        if (! $session) {
            // Solo empieza si el mensaje responde la primera pregunta (1–5) o pide la encuesta.
            if (! in_array($t, self::START, true) && ! preg_match('/^[1-5]$/', $t)) {
                return null;
            }
            $session = $this->surveys->session($invitation, 'whatsapp');
            if (in_array($t, self::START, true)) {
                return $this->ask($session, 'Gracias por darnos un minuto. 🙌');
            }
        }

        if ($session->status !== 'en_curso') {
            return null;
        }
        $session->loadMissing('answers');
        $question = $this->surveys->nextQuestion($session);
        if (! $question) {
            return null;
        }

        if (in_array($t, self::PAUSE, true)) {
            return 'Listo, dejamos la encuesta en pausa. Escribe *encuesta* cuando quieras seguir (tus respuestas quedaron guardadas).';
        }
        if (in_array($t, self::BACK, true)) {
            $this->surveys->undoLast($session);

            return $this->ask($session, 'Sin problema, corrijamos la anterior.');
        }

        if (in_array($t, self::SKIP, true)) {
            if (! $question['optional']) {
                return $this->ask($session, 'Esta pregunta necesita una respuesta.');
            }
            $value = null;
        } else {
            $value = $this->parse($question, $text, $t);
            if ($value === false) {
                // No es una respuesta: si parece un pedido para el bot, lo dejamos pasar (encuesta en pausa).
                if ($this->looksLikeBotRequest($t)) {
                    return null;
                }

                return $this->ask($session, 'No te entendí. 🙂');
            }
        }

        try {
            $session = $this->surveys->answer($session, $question['key'], $value, 'whatsapp');
        } catch (ValidationException $e) {
            return $this->ask($session, collect($e->errors())->flatten()->first());
        }

        if ($session->status === 'completada') {
            return $this->thanks($session);
        }

        return $this->ask($session);
    }

    private function openInvitation(string $phone): ?SurveyInvitation
    {
        $local = substr(preg_replace('/\D/', '', $phone), -10);
        if (strlen($local) < 7) {
            return null;
        }

        return SurveyInvitation::query()
            ->with('session.answers')
            ->where('status', 'enviada')
            ->where('expires_at', '>', now())
            ->whereHasMorph('subject', [Appointment::class, Turn::class], fn ($q) => $q->where('client_phone', 'like', '%'.$local)->where('whatsapp_consent', true))
            ->latest('sent_at')
            ->first();
    }

    /** @return string|false|null valor válido, false si no es una respuesta */
    private function parse(array $question, string $raw, string $t): string|false|null
    {
        if ($question['type'] === 'stars') {
            if (preg_match('/^([1-5])(\s*(estrellas?|de 5))?$/', $t, $m)) {
                return $m[1];
            }

            return false;
        }
        if ($question['type'] === 'text') {
            // En una pregunta abierta cualquier texto vale, salvo que sea claramente otro pedido.
            return $this->looksLikeBotRequest($t) ? false : trim($raw);
        }
        $options = $question['options'] ?? [];
        if (preg_match('/^\d{1,2}$/', $t) && isset($options[(int) $t - 1])) {
            return $options[(int) $t - 1]['value'];
        }
        foreach ($options as $option) {
            if ($t === $this->normalize($option['label']) || $t === $this->normalize($option['value'])) {
                return $option['value'];
            }
        }

        return false;
    }

    private function ask(SurveySession $session, ?string $preface = null): string
    {
        $session->load('answers');
        $question = $this->surveys->nextQuestion($session);
        if (! $question) {
            return $this->thanks($session);
        }
        $visible = $this->surveys->visibleQuestions($session);
        $position = array_search($question['key'], array_column($visible, 'key'), true) + 1;

        $lines = [];
        if ($preface) {
            $lines[] = $preface;
            $lines[] = '';
        }
        $lines[] = '*Pregunta '.$position.' de '.count($visible).'*';
        $lines[] = $question['text'];
        if ($question['type'] === 'stars') {
            $lines[] = '';
            $lines[] = 'Responde con un número del *1* (malo) al *5* (excelente).';
        } elseif ($question['type'] === 'text') {
            $lines[] = '';
            $lines[] = 'Escríbelo en un mensaje'.($question['optional'] ? ', o responde *omitir*.' : '.');
        } else {
            $lines[] = '';
            foreach ($question['options'] as $i => $option) {
                $lines[] = ($i + 1).'. '.$option['label'];
            }
            if ($question['optional']) {
                $lines[] = '';
                $lines[] = 'O responde *omitir*.';
            }
        }
        $lines[] = '';
        $lines[] = '_*atrás* para corregir · *salir* para seguir después_';

        return implode("\n", $lines);
    }

    private function thanks(SurveySession $session): string
    {
        $session->loadMissing('review');
        $text = '¡Gracias por tu tiempo! 💈 Tus respuestas nos ayudan a mejorar.';
        // Solo se dice que aparece en la página si de verdad se muestra (aprobada, 4 o 5 estrellas).
        if ($session->review?->approved && $session->review->rating >= \App\Models\Review::MIN_FEATURED_RATING) {
            $text .= "\n\nTu opinión ya aparece en nuestra página.";
        } elseif ($session->review && ! $session->review->approved) {
            $text .= "\n\nRevisaremos tu comentario antes de publicarlo.";
        }

        return $text;
    }

    private function looksLikeBotRequest(string $t): bool
    {
        foreach (self::BOT_WORDS as $word) {
            if ($t === $word || Str::startsWith($t, $word.' ')) {
                return true;
            }
        }

        return false;
    }

    private function normalize(string $text): string
    {
        return trim((string) Str::of(Str::ascii(mb_strtolower($text, 'UTF-8')))->squish(), " .,!¡?¿*");
    }
}
