@extends('layouts.app')

@section('title', 'Respuestas de la encuesta')

@section('actions')
    <a href="{{ route('surveys.index') }}" class="btn btn-outline-dark"><i class="bi bi-arrow-left me-1" aria-hidden="true"></i> Volver</a>
@endsection

@section('content')
    @include('surveys._tabs')
    @php($invitation = $session->invitation)

    <div class="card mb-3"><div class="card-body">
        <div class="row g-3">
            <div class="col-md-3"><div class="small text-muted">Cliente</div><div class="fw-semibold">{{ $invitation->subject?->client_name ?? '—' }}</div></div>
            <div class="col-md-3"><div class="small text-muted">Barbero</div><div class="fw-semibold">{{ $invitation->barber?->name ?? '—' }}</div></div>
            <div class="col-md-3"><div class="small text-muted">Servicio</div><div class="fw-semibold">{{ $invitation->service?->name ?? '—' }} · {{ $invitation->service_date->format('d/m/Y') }}</div></div>
            <div class="col-md-3"><div class="small text-muted">Respondida por</div><div class="fw-semibold">{{ $session->channel === 'whatsapp' ? 'WhatsApp' : 'Página web' }} · {{ $session->status === 'completada' ? 'Completa '.$session->completed_at?->format('d/m/Y H:i') : 'Sin terminar' }}</div></div>
        </div>
    </div></div>

    <div class="card mb-3">
        <div class="table-responsive">
            <table class="table mb-0">
                <thead><tr><th scope="col">Pregunta</th><th scope="col">Respuesta</th><th scope="col">Canal</th></tr></thead>
                <tbody>
                @forelse($session->answers->sortBy(fn ($a) => array_search($a->question_key, array_column($session->questions, 'key'))) as $answer)
                    <tr>
                        <td>{{ $answer->question_text }}</td>
                        <td>@if($answer->value === null)<span class="text-muted">Omitida</span>@elseif($answer->type === 'stars'){{ str_repeat('★', (int) $answer->value) }}<span class="text-muted">{{ str_repeat('★', 5 - (int) $answer->value) }}</span> {{ $answer->value }}/5 @else {{ $answer->value_label ?? $answer->value }} @endif</td>
                        <td class="small text-muted">{{ $answer->channel === 'whatsapp' ? 'WhatsApp' : 'Web' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="3" class="text-muted text-center py-4">Aún no hay respuestas.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    @if($session->review)
        <div class="card"><div class="card-body">
            <h2 class="h6 fw-bold">Opinión pública</h2>
            <p class="mb-1"><strong>Estado:</strong> {{ \App\Models\Review::MODERATION[$session->review->moderation_status] ?? $session->review->moderation_status }}@if($session->review->moderation_reason) — {{ $session->review->moderation_reason }}@endif</p>
            <p class="mb-1"><strong>Se muestra como:</strong> {{ $session->review->publicName() }}</p>
            @foreach($consents as $consent)
                <p class="small text-muted mb-0">Autorización de publicación: {{ $consent->accepted_at->format('d/m/Y H:i') }} por {{ str_replace('_', ' ', $consent->channel) }} (versión {{ $consent->text_version }}, huella {{ substr($consent->evidence_hash, 0, 12) }}…)@if($consent->revoked_at) · <strong>revocada</strong> {{ $consent->revoked_at->format('d/m/Y') }}@endif</p>
            @endforeach
            <a href="{{ route('surveys.reviews') }}" class="btn btn-sm btn-outline-dark mt-2">Gestionar opiniones</a>
        </div></div>
    @endif
@endsection
