{{-- Redes y contacto con los logos oficiales (SVG en public/images/redes). $class: clase del contenedor. --}}
@php
    // Solo se muestran las redes con un perfil real: un enlace a la portada de Instagram o Facebook no lleva a ningún lado.
    $isProfile = fn ($url) => filled($url) && trim((string) parse_url($url, PHP_URL_PATH), '/') !== '';
    $socials = array_filter([
        !empty($siteSettings['whatsapp']) ? ['WhatsApp', 'https://wa.me/'.$siteSettings['whatsapp'], 'whatsapp'] : null,
        $isProfile($siteSettings['instagram_url'] ?? null) ? ['Instagram', $siteSettings['instagram_url'], 'instagram'] : null,
        $isProfile($siteSettings['facebook_url'] ?? null) ? ['Facebook', $siteSettings['facebook_url'], 'facebook'] : null,
        $isProfile($siteSettings['tiktok_url'] ?? null) ? ['TikTok', $siteSettings['tiktok_url'], 'tiktok'] : null,
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
