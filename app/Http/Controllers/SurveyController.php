<?php

namespace App\Http\Controllers;

use App\Models\SurveyInvitation;
use App\Services\Surveys\SurveyService;
use Illuminate\Http\Request;

/**
 * Encuesta web del cliente. Se entra con el enlace secreto de su cita o turno (el mismo del
 * seguimiento), así nadie puede abrir la encuesta de otra persona cambiando un número.
 */
class SurveyController extends Controller
{
    public function __construct(private SurveyService $surveys)
    {
    }

    public function show(string $type, string $token)
    {
        // La encuesta se responde solo por WhatsApp mientras la versión web esté apagada.
        if (! config('encuestas.web')) {
            return $this->unavailable();
        }
        $invitation = $this->surveys->invitationFor($type, $token);
        if (! $invitation) {
            return $this->unavailable();
        }
        $invitation->loadMissing(['subject', 'barber', 'service', 'session.answers']);
        $session = $this->surveys->session($invitation, 'web');
        $subject = $invitation->subject;

        return response()
            ->view('public.survey', [
                'type' => $type,
                'token' => $token,
                'state' => $this->surveys->state($session),
                'firstName' => trim(explode(' ', (string) $subject?->client_name)[0] ?? ''),
                'barberName' => $invitation->barber?->name,
                'serviceName' => $invitation->service?->name,
                'serviceDate' => $invitation->service_date,
            ])
            ->header('Referrer-Policy', 'no-referrer') // el enlace secreto no se filtra a otros sitios
            ->header('X-Robots-Tag', 'noindex, nofollow')
            ->header('Cache-Control', 'no-store, private');
    }

    /**
     * Muestra un aviso dentro de la encuesta cuando el enlace es inválido o la encuesta fue cancelada.
     * Se mantiene el código 404 para que los buscadores no lo indexen.
     */
    public function unavailable()
    {
        return response()
            ->view('public.survey', ['unavailable' => true], 404)
            ->header('X-Robots-Tag', 'noindex, nofollow')
            ->header('Cache-Control', 'no-store, private');
    }

    public function state(string $type, string $token)
    {
        $session = $this->surveys->session($this->invitation($type, $token), 'web');

        return response()->json($this->surveys->state($session));
    }

    public function answer(Request $request, string $type, string $token)
    {
        $data = $request->validate([
            'question' => ['required', 'string', 'max:40', 'regex:/^[a-z0-9_]+$/'],
            'value' => ['nullable', 'max:600'],
        ]);
        $session = $this->surveys->session($this->invitation($type, $token), 'web');
        $session = $this->surveys->answer($session, $data['question'], $data['value'] ?? null, 'web');

        return response()->json($this->surveys->state($session));
    }

    private function invitation(string $type, string $token): SurveyInvitation
    {
        abort_unless(config('encuestas.web'), 404);
        $invitation = $this->surveys->invitationFor($type, $token);
        abort_unless($invitation, 404);

        return $invitation->loadMissing(['subject', 'barber', 'service', 'session.answers']);
    }
}
