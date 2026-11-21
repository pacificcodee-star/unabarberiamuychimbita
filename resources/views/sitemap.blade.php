{!! '<'.'?xml version="1.0" encoding="UTF-8"?>' !!}
@php
    // Páginas públicas indexables, con su prioridad. lastmod = última vez que cambió el código de la vista.
    $pages = [
        ['landing', 'landing', '1.0', 'weekly'],
        ['public.booking', 'public/booking', '0.9', 'weekly'],
        ['public.services', 'public/services', '0.9', 'weekly'],
        ['public.barbers', 'public/barbers', '0.8', 'monthly'],
        ['public.reviews', 'public/reviews', '0.7', 'weekly'],
        ['public.about', 'public/about', '0.6', 'monthly'],
        ['public.contact', 'public/contact', '0.8', 'monthly'],
        ['legal.notice', 'public/legal/aviso-legal', '0.2', 'yearly'],
        ['legal.privacy', 'public/legal/privacidad', '0.2', 'yearly'],
        ['legal.cookies', 'public/legal/cookies', '0.2', 'yearly'],
    ];
@endphp
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
@foreach ($pages as [$route, $view, $priority, $freq])
    @php $file = resource_path('views/'.$view.'.blade.php'); @endphp
    <url>
        <loc>{{ route($route) }}</loc>
        @if(file_exists($file))<lastmod>{{ date('Y-m-d', filemtime($file)) }}</lastmod>@endif
        <changefreq>{{ $freq }}</changefreq>
        <priority>{{ $priority }}</priority>
    </url>
@endforeach
</urlset>
