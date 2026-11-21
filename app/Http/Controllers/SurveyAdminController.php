<?php

namespace App\Http\Controllers;

use App\Models\ConsentRecord;
use App\Models\Review;
use App\Models\Service;
use App\Models\SurveyInvitation;
use App\Models\SurveyQuestion;
use App\Models\SurveySession;
use App\Models\User;
use App\Services\Surveys\SurveyService;
use App\Services\Surveys\SurveyStats;
use App\Support\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Panel de satisfacción: estadísticas, respuestas, preguntas y moderación de opiniones. Solo administradores. */
class SurveyAdminController extends Controller
{
    /** Únicos motivos para ocultar una opinión. Una mala calificación no es motivo. */
    public const HIDE_REASONS = [
        'Spam o publicidad',
        'Contiene datos personales de alguien',
        'Insultos graves o amenazas',
        'No corresponde a un servicio de la barbería',
    ];

    public function index(Request $request)
    {
        $filters = $request->validate([
            'barber_id' => ['nullable', 'integer'],
            'service_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'estado' => ['nullable', Rule::in(array_merge(array_keys(SurveyInvitation::STATUSES), ['atencion']))],
        ]);
        $stats = new SurveyStats($filters);

        $list = ($filters['estado'] ?? null) === 'atencion'
            ? $stats->needsAttentionQuery()
            : $stats->invitations()->when($filters['estado'] ?? null, fn ($q, $v) => $q->where('status', $v));

        $invitations = $list->with(['barber', 'service', 'subject', 'session.answers'])
            ->latest('service_date')->latest('id')->paginate(20)->withQueryString();

        $barbers = User::barbers()->orderBy('name')->get(['id', 'name']);
        $services = Service::orderBy('name')->get(['id', 'name']);

        return view('surveys.index', [
            'summary' => $stats->summary(),
            'byBarber' => $stats->breakdown('barber'),
            'byService' => $stats->breakdown('service'),
            'monthly' => $stats->monthly(),
            'invitations' => $invitations,
            'barbers' => $barbers->keyBy('id'),
            'services' => $services->keyBy('id'),
            'minSample' => (int) config('encuestas.min_sample', 5),
        ]);
    }

    public function show(SurveySession $session)
    {
        $session->load(['answers', 'invitation.subject', 'invitation.barber', 'invitation.service', 'review']);

        return view('surveys.show', ['session' => $session, 'consents' => $session->review
            ? ConsentRecord::where('subject_type', 'review')->where('subject_id', $session->review->id)->get()
            : collect()]);
    }

    // --- Preguntas ---------------------------------------------------------------------------

    public function questions()
    {
        return view('surveys.questions', ['questions' => SurveyQuestion::orderBy('sort_order')->orderBy('id')->get()]);
    }

    public function createQuestion()
    {
        return view('surveys.question-form', ['question' => new SurveyQuestion(['type' => 'choice', 'active' => true]), 'others' => SurveyQuestion::orderBy('sort_order')->get()]);
    }

    public function storeQuestion(Request $request)
    {
        $data = $this->validatedQuestion($request);
        $data['key'] = $this->uniqueKey($data['text']);
        $data['sort_order'] = (int) SurveyQuestion::max('sort_order') + 10;
        $question = SurveyQuestion::create($data);
        AuditLog::record('encuesta.pregunta_creada', $question);

        return redirect()->route('surveys.questions')->with('success', 'Pregunta creada. Aplica a las encuestas que empiecen desde ahora.');
    }

    public function editQuestion(SurveyQuestion $question)
    {
        return view('surveys.question-form', ['question' => $question, 'others' => SurveyQuestion::whereKeyNot($question->id)->orderBy('sort_order')->get()]);
    }

    public function updateQuestion(Request $request, SurveyQuestion $question)
    {
        $data = $this->validatedQuestion($request, $question);
        $question->update($data);
        AuditLog::record('encuesta.pregunta_editada', $question);

        return redirect()->route('surveys.questions')->with('success', 'Pregunta actualizada. Las encuestas ya empezadas conservan la versión con la que empezaron.');
    }

    public function moveQuestion(Request $request, SurveyQuestion $question)
    {
        $direction = $request->validate(['direction' => ['required', Rule::in(['up', 'down'])]])['direction'];
        $all = SurveyQuestion::orderBy('sort_order')->orderBy('id')->get()->values();
        $index = $all->search(fn ($q) => $q->id === $question->id);
        $swapWith = $direction === 'up' ? $index - 1 : $index + 1;
        if (isset($all[$swapWith])) {
            $ordered = $all->all();
            [$ordered[$index], $ordered[$swapWith]] = [$ordered[$swapWith], $ordered[$index]];
            foreach (array_values($ordered) as $i => $q) {
                $q->update(['sort_order' => ($i + 1) * 10]);
            }
        }

        return back();
    }

    private function validatedQuestion(Request $request, ?SurveyQuestion $question = null): array
    {
        $data = $request->validate([
            'text' => ['required', 'string', 'max:255'],
            'help' => ['nullable', 'string', 'max:255'],
            'type' => ['required', Rule::in(array_keys(SurveyQuestion::TYPES))],
            'options_text' => ['nullable', 'string', 'max:2000'],
            'is_optional' => ['nullable', 'boolean'],
            'active' => ['nullable', 'boolean'],
            'condition_question' => ['nullable', 'string', 'max:40'],
            'condition_operator' => ['nullable', Rule::in(['in', 'filled'])],
            'condition_values' => ['nullable', 'string', 'max:255'],
        ]);

        // Las preguntas especiales conservan su tipo: de ellas dependen la calificación y la publicación.
        if ($question && in_array($question->key, [SurveyService::KEY_RATING, SurveyService::KEY_COMMENT, SurveyService::KEY_PUBLISH], true)) {
            $data['type'] = $question->type;
        }

        $options = null;
        if (in_array($data['type'], ['choice', 'consent'], true)) {
            $lines = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', (string) ($data['options_text'] ?? '')))));
            if (count($lines) < 2) {
                abort(back()->withInput()->withErrors(['options_text' => 'Escribe al menos dos opciones, una por línea.']));
            }
            // "valor | Texto" o solo "Texto" (el valor se genera). Se conservan los valores existentes.
            $options = array_map(function ($line) {
                [$value, $label] = str_contains($line, '|') ? array_map('trim', explode('|', $line, 2)) : [\Illuminate\Support\Str::slug($line, '_'), $line];

                return ['value' => \Illuminate\Support\Str::limit(\Illuminate\Support\Str::slug($value, '_'), 40, ''), 'label' => \Illuminate\Support\Str::limit($label, 80, '')];
            }, $lines);
        }

        $showIf = null;
        if (filled($data['condition_question'] ?? null)) {
            $showIf = ['question' => $data['condition_question'], 'operator' => $data['condition_operator'] ?: 'in'];
            if ($showIf['operator'] === 'in') {
                $showIf['values'] = array_values(array_filter(array_map('trim', explode(',', (string) ($data['condition_values'] ?? '')))));
            }
        }

        return [
            'text' => $data['text'],
            'help' => $data['help'] ?? null,
            'type' => $data['type'],
            'options' => $options,
            'is_optional' => (bool) ($data['is_optional'] ?? false),
            'active' => (bool) ($data['active'] ?? false),
            'show_if' => $showIf,
        ];
    }

    private function uniqueKey(string $text): string
    {
        $base = \Illuminate\Support\Str::limit(\Illuminate\Support\Str::slug($text, '_'), 30, '') ?: 'pregunta';
        $key = $base;
        for ($i = 2; SurveyQuestion::where('key', $key)->exists(); $i++) {
            $key = $base.'_'.$i;
        }

        return $key;
    }

    // --- Opiniones -----------------------------------------------------------------------------

    public function reviews(Request $request)
    {
        $status = $request->validate(['estado' => ['nullable', Rule::in(array_keys(Review::MODERATION))]])['estado'] ?? null;
        $reviews = Review::with(['barber', 'service'])
            ->when($status, fn ($q, $v) => $q->where('moderation_status', $v))
            ->latest()->paginate(20)->withQueryString();

        return view('surveys.reviews', ['reviews' => $reviews, 'status' => $status, 'counts' => Review::selectRaw('moderation_status, count(*) as total')->groupBy('moderation_status')->pluck('total', 'moderation_status')]);
    }

    public function moderate(Request $request, Review $review)
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(['publicar', 'ocultar', 'retirar'])],
            // Ocultar solo por motivos objetivos (nunca por ser una opinión negativa).
            'reason' => $request->input('action') === 'ocultar'
                ? ['required', Rule::in(self::HIDE_REASONS)]
                : ['nullable', 'string', 'max:255'],
        ], ['reason.required' => 'Elige el motivo objetivo para ocultarla.', 'reason.in' => 'Elige uno de los motivos de la lista.']);
        $data['reason'] = $data['reason'] ?? null;

        // Publicar exige que haya autorización registrada cuando la opinión viene de una encuesta.
        if ($data['action'] === 'publicar' && $review->isVerified()
            && ! ConsentRecord::where('purpose', 'publicacion_resena')->where('subject_type', 'review')->where('subject_id', $review->id)->whereNull('revoked_at')->exists()) {
            return back()->with('error', 'No hay autorización vigente del cliente para publicar esta opinión.');
        }
        if ($data['action'] === 'publicar' && $review->moderation_status === 'retirada') {
            return back()->with('error', 'El cliente pidió retirar esta opinión: no se puede volver a publicar.');
        }

        match ($data['action']) {
            'publicar' => $review->update(['approved' => true, 'moderation_status' => 'aprobada', 'published_at' => now(), 'moderation_reason' => null]),
            'ocultar' => $review->update(['approved' => false, 'moderation_status' => 'oculta', 'moderation_reason' => $data['reason'] ?: 'Oculta por el equipo.']),
            'retirar' => $review->update(['approved' => false, 'moderation_status' => 'retirada', 'withdrawn_at' => now(), 'moderation_reason' => $data['reason'] ?: 'Retirada a pedido del cliente.']),
        };
        if ($data['action'] === 'retirar') {
            ConsentRecord::revoke('publicacion_resena', 'review', [$review->id], 'solicitud_titular');
        }
        AuditLog::record('opinion.'.$data['action'], $review);

        return back()->with('success', match ($data['action']) {
            'publicar' => 'Opinión publicada.',
            'ocultar' => 'Opinión oculta del sitio.',
            'retirar' => 'Opinión retirada y autorización revocada.',
        });
    }
}
