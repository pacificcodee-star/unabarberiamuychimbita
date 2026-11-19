<?xml version="1.0" encoding="UTF-8"?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
    @foreach ([route('landing'), route('public.services'), route('public.barbers'), route('public.contact'), route('public.booking')] as $publicUrl)
        <url><loc>{{ $publicUrl }}</loc></url>
    @endforeach
</urlset>
