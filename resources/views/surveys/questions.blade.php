@extends('layouts.app')

@section('title', 'Preguntas de la encuesta')

@section('actions')
    <a href="{{ route('surveys.questions.create') }}" class="btn btn-gold"><i class="bi bi-plus-lg me-1" aria-hidden="true"></i> Nueva pregunta</a>
@endsection

@section('content')
    @include('surveys._tabs')

    <div class="alert alert-info small">
        <i class="bi bi-info-circle me-1" aria-hidden="true"></i>
        Los cambios aplican a las encuestas que empiecen desde ahora: las que ya empezaron conservan sus preguntas originales.
        Las preguntas <strong>experiencia</strong>, <strong>comentario</strong> y <strong>publicar</strong> sostienen la calificación y la publicación: puedes cambiar su texto, pero no su tipo.
    </div>

    <div class="card">
        <div class="table-responsive">
            <table class="table mb-0 align-middle">
                <thead><tr><th scope="col">Orden</th><th scope="col">Pregunta</th><th scope="col">Tipo</th><th scope="col">Condición</th><th scope="col">Estado</th><th scope="col"><span class="visually-hidden">Acciones</span></th></tr></thead>
                <tbody>
                @foreach($questions as $question)
                    <tr class="{{ $question->active ? '' : 'text-muted' }}">
                        <td class="text-nowrap">
                            <form method="POST" action="{{ route('surveys.questions.move', $question) }}" class="d-inline">@csrf<input type="hidden" name="direction" value="up"><button class="btn btn-sm btn-outline-dark" @disabled($loop->first) aria-label="Subir «{{ $question->text }}»"><i class="bi bi-arrow-up" aria-hidden="true"></i></button></form>
                            <form method="POST" action="{{ route('surveys.questions.move', $question) }}" class="d-inline">@csrf<input type="hidden" name="direction" value="down"><button class="btn btn-sm btn-outline-dark" @disabled($loop->last) aria-label="Bajar «{{ $question->text }}»"><i class="bi bi-arrow-down" aria-hidden="true"></i></button></form>
                        </td>
                        <td>{{ $question->text }}<div class="small text-muted"><code>{{ $question->key }}</code>{{ $question->is_optional ? ' · opcional' : '' }}</div></td>
                        <td class="small">{{ \App\Models\SurveyQuestion::TYPES[$question->type] ?? $question->type }}</td>
                        <td class="small">
                            @if($question->show_if)
                                Si <code>{{ $question->show_if['question'] }}</code> {{ ($question->show_if['operator'] ?? 'in') === 'filled' ? 'tiene respuesta' : 'es '.implode(' o ', $question->show_if['values'] ?? []) }}
                            @else — @endif
                        </td>
                        <td>{!! $question->active ? '<span class="badge text-bg-success">Activa</span>' : '<span class="badge text-bg-secondary">Inactiva</span>' !!}</td>
                        <td class="text-end"><a href="{{ route('surveys.questions.edit', $question) }}" class="btn btn-sm btn-outline-dark">Editar</a></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endsection
