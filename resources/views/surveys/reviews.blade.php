@extends('layouts.app')

@section('title', 'Opiniones')

@section('content')
    @include('surveys._tabs')

    <div class="alert alert-info small">
        <i class="bi bi-shield-check me-1" aria-hidden="true"></i>
        Una opinión solo se publica con autorización del cliente. Se puede ocultar únicamente por motivos objetivos (spam, datos personales, insultos o amenazas, o si no corresponde a la barbería), nunca por ser negativa.
    </div>

    <nav class="segmented" aria-label="Filtrar por estado">
        <a href="{{ route('surveys.reviews') }}" class="{{ $status ? '' : 'is-active' }}" @if(! $status) aria-current="true" @endif>Todas</a>
        @foreach(\App\Models\Review::MODERATION as $key => $label)
            <a href="{{ route('surveys.reviews', ['estado' => $key]) }}" class="{{ $status === $key ? 'is-active' : '' }}" @if($status === $key) aria-current="true" @endif>{{ $label }} <span class="segmented-count">{{ $counts[$key] ?? 0 }}</span></a>
        @endforeach
    </nav>

    <div class="row g-3">
        @forelse($reviews as $review)
            <div class="col-lg-6">
                <div class="card h-100"><div class="card-body">
                    <div class="d-flex justify-content-between gap-2">
                        <div>
                            <strong>{{ $review->client_name }}</strong>
                            <span class="small text-muted">· se muestra como «{{ $review->publicName() }}»</span>
                            <div class="small">{{ str_repeat('★', $review->rating) }}<span class="text-muted">{{ str_repeat('★', 5 - $review->rating) }}</span>
                                · {{ $review->isVerified() ? 'Encuesta verificada ('.($review->source === 'encuesta_whatsapp' ? 'WhatsApp' : 'web').')' : 'Formulario de la web' }}
                                @if($review->barber) · {{ $review->barber->name }}@endif @if($review->service) · {{ $review->service->name }}@endif</div>
                        </div>
                        <span class="badge text-bg-{{ ['aprobada' => 'success', 'revision' => 'warning', 'pendiente' => 'secondary', 'oculta' => 'dark', 'retirada' => 'light'][$review->moderation_status] ?? 'light' }} align-self-start">{{ \App\Models\Review::MODERATION[$review->moderation_status] ?? 'Sin estado' }}</span>
                    </div>
                    <p class="mt-2 mb-2">{{ $review->comment }}</p>
                    @if($review->moderation_reason)<p class="small text-muted mb-2"><i class="bi bi-info-circle me-1" aria-hidden="true"></i>{{ $review->moderation_reason }}</p>@endif
                    <div class="small text-muted mb-2">Recibida {{ $review->created_at->format('d/m/Y H:i') }}@if($review->survey_session_id) · <a href="{{ route('surveys.show', $review->survey_session_id) }}">ver encuesta y autorización</a>@endif</div>

                    <div class="d-flex flex-wrap gap-2">
                        @if($review->moderation_status !== 'aprobada' && $review->moderation_status !== 'retirada')
                            <form method="POST" action="{{ route('surveys.reviews.moderate', $review) }}">@csrf<input type="hidden" name="action" value="publicar"><button class="btn btn-sm btn-success"><i class="bi bi-eye me-1" aria-hidden="true"></i> Publicar</button></form>
                        @endif
                        @if($review->moderation_status !== 'oculta' && $review->moderation_status !== 'retirada')
                            <form method="POST" action="{{ route('surveys.reviews.moderate', $review) }}" class="d-flex gap-1">@csrf<input type="hidden" name="action" value="ocultar">
                                <label class="visually-hidden" for="reason-{{ $review->id }}">Motivo para ocultar</label>
                                <select name="reason" id="reason-{{ $review->id }}" class="form-select form-select-sm" required>
                                    <option value="">Motivo para ocultar…</option>
                                    @foreach(\App\Http\Controllers\SurveyAdminController::HIDE_REASONS as $reason)<option>{{ $reason }}</option>@endforeach
                                </select>
                                <button class="btn btn-sm btn-outline-dark text-nowrap">Ocultar</button>
                            </form>
                        @endif
                        @if($review->moderation_status !== 'retirada')
                            <form method="POST" action="{{ route('surveys.reviews.moderate', $review) }}" onsubmit="return confirm('¿El cliente pidió retirar su opinión? Se revoca su autorización y no se podrá volver a publicar.');">@csrf<input type="hidden" name="action" value="retirar"><button class="btn btn-sm btn-outline-danger">Retirar (pedido del cliente)</button></form>
                        @endif
                        <form method="POST" action="{{ route('surveys.reviews.destroy', $review) }}" class="ms-auto" onsubmit="return confirm('¿Eliminar esta opinión para siempre? Sale de la página y del promedio, y no se puede deshacer.');">@csrf @method('DELETE')<button class="btn btn-sm btn-danger"><i class="bi bi-trash me-1" aria-hidden="true"></i> Eliminar</button></form>
                    </div>
                </div></div>
            </div>
        @empty
            <div class="col-12"><div class="card"><div class="card-body text-center text-muted py-4">No hay opiniones con este filtro.</div></div></div>
        @endforelse
    </div>
    @if($reviews->hasPages())<div class="mt-3">{{ $reviews->links() }}</div>@endif
@endsection
