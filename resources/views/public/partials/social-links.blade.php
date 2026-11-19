{{-- Redes y contacto con los logos oficiales (SVG en public/images/redes). $class: clase del contenedor. --}}
@php
    $socials = array_filter([
        !empty($siteSettings['whatsapp']) ? ['WhatsApp', 'https://wa.me/'.$siteSettings['whatsapp'], 'whatsapp'] : null,
        ['Instagram', $siteSettings['instagram_url'] ?: 'https://www.instagram.com/', 'instagram'],
        ['Facebook', $siteSettings['facebook_url'] ?: 'https://www.facebook.com/', 'facebook'],
        ['TikTok', $siteSettings['tiktok_url'] ?: 'https://www.tiktok.com/', 'tiktok'],
    ]);
@endphp
<div class="{{ $class ?? 'social-links' }} social-brand">
    @foreach($socials as [$label, $url, $logo])
        <a href="{{ $url }}" target="_blank" rel="noopener noreferrer" aria-label="{{ $label }}" title="{{ $label }}">
            <img src="{{ asset('images/redes/'.$logo.'.svg') }}" alt="" width="24" height="24" loading="lazy" decoding="async">
        </a>
    @endforeach
    @if(!empty($siteSettings['email']))
        <a href="mailto:{{ $siteSettings['email'] }}" aria-label="Correo" title="Correo" class="is-mail">
            <svg viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="14" rx="2.5"/><path d="m3.5 7 8.5 6.5L20.500 7"/></svg>
        </a>
    @endif
</div>
