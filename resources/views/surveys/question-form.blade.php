@extends('layouts.app')

@section('title', $question->exists ? 'Editar pregunta' : 'Nueva pregunta')

@section('actions')
    <a href="{{ route('surveys.questions') }}" class="btn btn-outline-dark"><i class="bi bi-arrow-left me-1" aria-hidden="true"></i> Volver</a>
@endsection

@section('content')
    @php
        $special = in_array($question->key, ['experiencia', 'comentario', 'publicar'], true);
        $optionsText = old('options_text', collect($question->options ?? [])->map(fn ($o) => $o['value'].' | '.$o['label'])->implode("\n"));
        $cond = $question->show_if ?? [];
    @endphp
    <div class="card"><div class="card-body">
        <form method="POST" action="{{ $question->exists ? route('surveys.questions.update', $question) : route('surveys.questions.store') }}" class="row g-3">
            @csrf
            @if($question->exists) @method('PUT') @endif

            <div class="col-12">
                <label class="form-label" for="q-text">Pregunta</label>
                <input type="text" id="q-text" name="text" value="{{ old('text', $question->text) }}" class="form-control @error('text') is-invalid @enderror" maxlength="255" required>
                @error('text')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-12">
                <label class="form-label" for="q-help">Texto de ayuda <span class="text-muted small">(opcional)</span></label>
                <input type="text" id="q-help" name="help" value="{{ old('help', $question->help) }}" class="form-control" maxlength="255">
            </div>
            <div class="col-md-6">
                <label class="form-label" for="q-type">Tipo de respuesta</label>
                <select id="q-type" name="type" class="form-select" @disabled($special)>
                    @foreach(\App\Models\SurveyQuestion::TYPES as $key => $label)<option value="{{ $key }}" @selected(old('type', $question->type) === $key)>{{ $label }}</option>@endforeach
                </select>
                @if($special)<input type="hidden" name="type" value="{{ $question->type }}"><div class="form-text">Esta pregunta sostiene la calificación o la publicación: su tipo no se cambia.</div>@endif
            </div>
            <div class="col-md-6 d-flex align-items-end gap-4">
                <div class="form-check"><input type="hidden" name="is_optional" value="0"><input class="form-check-input" type="checkbox" name="is_optional" id="q-optional" value="1" @checked(old('is_optional', $question->is_optional))><label class="form-check-label" for="q-optional">Se puede omitir</label></div>
                <div class="form-check"><input type="hidden" name="active" value="0"><input class="form-check-input" type="checkbox" name="active" id="q-active" value="1" @checked(old('active', $question->active ?? true))><label class="form-check-label" for="q-active">Activa</label></div>
            </div>
            <div class="col-12">
                <label class="form-label" for="q-options">Opciones <span class="text-muted small">(para "Opciones" y "Autorización": una por línea, formato <code>valor | Texto</code>)</span></label>
                <textarea id="q-options" name="options_text" rows="5" class="form-control @error('options_text') is-invalid @enderror" placeholder="si | Sí&#10;no | No">{{ $optionsText }}</textarea>
                @error('options_text')<div class="invalid-feedback">{{ $message }}</div>@enderror
                <div class="form-text">Si cambias el <code>valor</code> de una opción, las estadísticas anteriores la verán como otra opción. Para corregir solo el texto, cambia lo que va después de la barra.</div>
            </div>

            <fieldset class="col-12">
                <legend class="form-label fs-6">Mostrar solo si… <span class="text-muted small">(opcional)</span></legend>
                <div class="row g-2">
                    <div class="col-md-4">
                        <label class="form-label small" for="q-cond-question">Pregunta anterior</label>
                        <select id="q-cond-question" name="condition_question" class="form-select">
                            <option value="">Siempre se muestra</option>
                            @foreach($others as $other)<option value="{{ $other->key }}" @selected(old('condition_question', $cond['question'] ?? '') === $other->key)>{{ \Illuminate\Support\Str::limit($other->text, 50) }}</option>@endforeach
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small" for="q-cond-op">Condición</label>
                        <select id="q-cond-op" name="condition_operator" class="form-select">
                            <option value="in" @selected(old('condition_operator', $cond['operator'] ?? 'in') === 'in')>la respuesta es…</option>
                            <option value="filled" @selected(old('condition_operator', $cond['operator'] ?? '') === 'filled')>tiene respuesta</option>
                        </select>
                    </div>
                    <div class="col-md-5">
                        <label class="form-label small" for="q-cond-values">Valores (separados por coma)</label>
                        <input type="text" id="q-cond-values" name="condition_values" value="{{ old('condition_values', implode(', ', $cond['values'] ?? [])) }}" class="form-control" placeholder="parcial, no">
                    </div>
                </div>
            </fieldset>

            <div class="col-12"><button class="btn btn-gold"><i class="bi bi-check2 me-1" aria-hidden="true"></i> Guardar</button></div>
        </form>
    </div></div>
@endsection
