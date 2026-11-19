<?php

namespace App\Http\Controllers;

use App\Models\FeaturedCut;
use App\Models\HomeSlide;
use App\Models\Review;
use App\Models\Service;
use App\Models\SiteSetting;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class LandingController extends Controller
{
    public function index()
    {
        $slides = HomeSlide::where('active', true)->orderBy('sort_order')->orderBy('id')->get();
        $gallerySlides = $slides->count() > 3
            ? $slides->skip(3)->take(3)->values()
            : $slides->take(3);

        // Si la migración aún no se ha ejecutado, el inicio sigue funcionando con la portada normal.
        $featuredCuts = Schema::hasTable('featured_cuts')
            ? FeaturedCut::visible()->with('service')->get()
            : collect();

        $heroScenes = $this->heroScenes($featuredCuts, $slides);

        $featuredServices = Service::active()->orderBy('price')->take(6)->get();
        $servicesCount = Service::active()->count();
        $featuredBarbers = User::barbers()->where('active', true)->orderBy('name')->take(4)->get();
        $barbersCount = User::barbers()->where('active', true)->count();

        return view('landing', compact(
            'slides',
            'gallerySlides',
            'featuredServices',
            'servicesCount',
            'featuredBarbers',
            'barbersCount',
            'featuredCuts',
            'heroScenes'
        ));
    }

    /**
     * Escenas de la portada inmersiva. Usa los "Cortes destacados" del admin;
     * si aún no hay, las arma con los barberos (sus fotos) y las imágenes del inicio.
     */
    private function heroScenes(Collection $featuredCuts, Collection $slides): Collection
    {
        $settings = SiteSetting::currentData();
        $slidePaths = $slides->pluck('image_path')->values();
        $words = collect(preg_split('/[·|,\/]+/u', (string) ($settings['tagline'] ?? '')))
            ->map(fn ($word) => trim($word))->filter()->values();
        $headline = fn (int $i, string $fallback) => $words->isNotEmpty() ? $words[$i % $words->count()] : $fallback;
        $floats = fn (int $i) => $slidePaths->isEmpty() ? [] : collect(range(0, min(2, $slidePaths->count() - 1)))
            ->map(fn ($k) => $slidePaths[($i * 3 + $k) % $slidePaths->count()])->unique()->values()->all();
        $isCutout = fn (?string $path) => in_array(strtolower(pathinfo((string) $path, PATHINFO_EXTENSION)), ['png', 'webp', 'avif', 'gif'], true);

        if ($featuredCuts->isNotEmpty()) {
            return $featuredCuts->values()->map(fn (FeaturedCut $cut, int $i) => [
                'headline' => $cut->title,
                'kicker' => $i === 0 ? $settings['home_eyebrow'] : 'Corte destacado',
                'title' => $cut->title,
                'subtitle' => $cut->subtitle,
                'description' => $cut->description,
                'image' => $cut->image_path,
                'image_url' => $cut->image_path ? asset('storage/'.$cut->image_path) : (! empty($settings['logo_path']) ? asset('storage/'.$settings['logo_path']) : asset('images/logo-una-barberia.png')),
                'video_url' => $cut->video_path ? asset('storage/'.$cut->video_path) : null,
                'placeholder' => false,
                'cutout' => $cut->image_path ? $cut->hasCutoutImage() : false,
                'gallery' => $cut->galleryPaths(),
                'cta_url' => route('public.booking', $cut->service_id ? ['servicio' => $cut->service_id] : []),
                'cta_label' => $cut->service_id ? 'Reservar este corte' : 'Reservar cita o turno',
            ]);
        }

        // Sin cortes destacados: usamos los servicios de tipo corte (con sus imágenes y datos).
        $services = Service::active()->orderBy('price')->get();
        $cuts = $services->filter(fn (Service $service) => in_array($service->categoryKey(), ['corte', 'combo'], true))->values();
        if ($cuts->count() < 2) {
            $cuts = $services->values();
        }
        // Primero los que tienen imagen; los que no, toman una foto del inicio para no quedar vacíos.
        $cuts = $cuts->sortByDesc(fn (Service $service) => (bool) $service->image_path)->values()->take(FeaturedCut::MAX);

        $scenes = $cuts->map(function (Service $service, int $i) use ($settings, $floats, $isCutout, $slidePaths) {
            $image = $service->image_path ?: ($slidePaths->isNotEmpty() ? $slidePaths[$i % $slidePaths->count()] : null);
            $logo = ! empty($settings['logo_path']) ? asset('storage/'.$settings['logo_path']) : asset('images/logo-una-barberia.png');

            return [
                'headline' => $service->name,
                'kicker' => $i === 0 ? $settings['home_eyebrow'] : 'Nuestros cortes',
                'title' => $service->name,
                'subtitle' => format_money($service->price).' · '.$service->duration_minutes.' min',
                'description' => $service->description ?: ($i === 0 ? $settings['home_description'] : null),
                'image' => $image,
                'image_url' => $image ? asset('storage/'.$image) : $logo,
                'video_url' => null,
                'placeholder' => ! $image,
                'cutout' => $image ? $isCutout($image) : true,
                'gallery' => $floats($i),
                'cta_url' => route('public.booking', ['servicio' => $service->id]),
                'cta_label' => 'Reservar este corte',
            ];
        })->values();

        return $scenes;
    }

    public function about()
    {
        return view('public.about');
    }

    public function services()
    {
        $services = Service::active()->orderBy('price')->get();

        return view('public.services', compact('services'));
    }

    public function barbers()
    {
        $barbers = User::barbers()->where('active', true)->orderBy('name')->get();

        return view('public.barbers', compact('barbers'));
    }

    public function reviews()
    {
        $reviews = Review::approved()->latest()->get();
        $reviewCount = $reviews->count();
        $averageRating = $reviewCount > 0 ? round((float) $reviews->avg('rating'), 1) : 5.0;

        return view('public.reviews', compact('reviews', 'reviewCount', 'averageRating'));
    }

    public function contact()
    {
        return view('public.contact');
    }
}
