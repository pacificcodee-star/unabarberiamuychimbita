@php
    $photoExtension = $barber->photo_path ? strtolower(pathinfo($barber->photo_path, PATHINFO_EXTENSION)) : null;
    $isCutout = in_array($photoExtension, ['png', 'webp'], true);
    $bookingType = $barber->attention_mode === 'citas' ? 'cita' : 'turno';
    $firstName = \Illuminate\Support\Str::of($barber->name)->before(' ');
@endphp
<article class="barber-card {{ $revealClass ?? 'reveal' }}">
    <div class="barber-portrait {{ $isCutout ? 'is-cutout' : '' }}">
        <span class="barber-mode-badge">
            <i class="bi {{ $barber->attention_mode === 'citas' ? 'bi-calendar2-week' : 'bi-person-walking' }}" aria-hidden="true"></i>
            {{ $barber->attentionModeLabel() }}
        </span>
        @if($barber->photo_path)
            <img src="{{ asset('storage/'.$barber->photo_path) }}" alt="Retrato de {{ $barber->name }}" width="600" height="750" loading="lazy" decoding="async">
        @else
            <span class="barber-portrait-empty" aria-hidden="true">{{ mb_strtoupper(mb_substr($barber->name, 0, 1)) }}</span>
        @endif
    </div>
    <div class="barber-card-body">
        <h3>{{ $barber->name }}</h3>
        @if($barber->specialty)
            <p class="barber-specialty">{{ $barber->specialty }}</p>
        @endif
        @if(!empty($showBio) && $barber->bio)
            <p class="barber-bio">{{ $barber->bio }}</p>
        @endif
        <a href="{{ route('public.booking', ['barbero' => $barber->id, 'tipo' => $bookingType]) }}" class="btn btn-outline-gold btn-booking-blue">
            {{ $bookingType === 'cita' ? 'Reservar con '.$firstName : 'Turno con '.$firstName }}
        </a>
    </div>
</article>
