<?php

namespace App\Http\Controllers;

use App\Models\FeaturedCut;
use App\Models\SiteSetting;
use App\Rules\ImageUpload;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Pestaña "Animaciones" del panel: imagen o video de la pantalla de carga,
 * textos de la espiral y los cortes (imágenes o videos) que giran en ella.
 */
class AnimationController extends Controller
{
    public function index()
    {
        $settings = SiteSetting::currentData();
        $cuts = Schema::hasTable('featured_cuts')
            ? FeaturedCut::with('service')->orderBy('sort_order')->orderBy('id')->get()
            : collect();

        return view('animations.index', compact('settings', 'cuts'));
    }

    public function updateLoader(Request $request)
    {
        $request->validate([
            'loader_image' => ['nullable', 'file', new ImageUpload, 'max:20480'],
            'loader_video' => ['nullable', 'file', 'mimetypes:video/mp4,video/webm,video/quicktime', 'max:51200'],
            'hero_image' => ['nullable', 'file', new ImageUpload, 'max:20480'],
            'spiral_image' => ['nullable', 'file', new ImageUpload, 'max:20480'],
            'contact_image' => ['nullable', 'file', new ImageUpload, 'max:20480'],
        ], [
            'loader_video.mimetypes' => 'El video debe ser MP4, WebM o MOV.',
            'loader_video.max' => 'El video puede pesar hasta 50 MB.',
        ]);

        $record = SiteSetting::query()->firstOrCreate([], ['data' => SiteSetting::defaults()]);
        $data = array_merge(SiteSetting::defaults(), $record->data ?? []);

        foreach (['loader_image' => 'loader_image_path', 'loader_video' => 'loader_video_path', 'hero_image' => 'hero_image_path', 'spiral_image' => 'spiral_image_path', 'contact_image' => 'contact_image_path'] as $input => $key) {
            $old = $data[$key] ?? '';
            if ($request->hasFile($input)) {
                $data[$key] = $request->file($input)->store('site', 'public');
                if ($old) {
                    Storage::disk('public')->delete($old);
                }
            } elseif ($request->boolean('remove_'.$input) && $old) {
                Storage::disk('public')->delete($old);
                $data[$key] = '';
            }
        }

        $record->data = $data;
        $record->save();

        return redirect()->route('animations.index')->with('success', 'Imágenes actualizadas.');
    }

    /** Sube varias imágenes y/o videos a la vez: cada archivo se convierte en un corte de la espiral. */
    public function storeCuts(Request $request)
    {
        $request->validate([
            'images' => ['nullable', 'array'],
            'images.*' => ['file', new ImageUpload, 'max:20480'],
            'videos' => ['nullable', 'array'],
            'videos.*' => ['file', 'mimetypes:video/mp4,video/webm,video/quicktime', 'max:51200'],
            'media' => ['nullable', 'array'],
            'media.*' => ['file', 'mimetypes:image/jpeg,image/png,image/webp,image/avif,image/gif,video/mp4,video/webm,video/quicktime', 'max:51200'],
        ], [
            'media.*.mimetypes' => 'Solo se aceptan imágenes (JPG, PNG, WebP) o videos (MP4, WebM, MOV).',
            'media.*.max' => 'Cada archivo puede pesar hasta 50 MB.',
            'images.*.max' => 'Cada imagen puede pesar hasta 20 MB.',
            'videos.*.mimetypes' => 'Los videos deben ser MP4, WebM o MOV.',
            'videos.*.max' => 'Cada video puede pesar hasta 50 MB.',
        ]);

        $files = array_values(array_filter([...($request->file('media') ?? []), ...($request->file('images') ?? []), ...($request->file('videos') ?? [])]));
        if (! $files) {
            return back()->withErrors(['images' => 'Elige al menos una imagen o un video.']);
        }

        $order = (int) FeaturedCut::max('sort_order');
        foreach ($files as $file) {
            $isVideo = str_starts_with((string) $file->getMimeType(), 'video/');
            $name = trim(preg_replace('/[-_]+/', ' ', pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME)));
            FeaturedCut::create([
                'title' => $name !== '' ? mb_substr(mb_convert_case($name, MB_CASE_TITLE, 'UTF-8'), 0, 120) : 'Corte '.($order + 1),
                'image_path' => $isVideo ? null : $file->store('featured-cuts', 'public'),
                'video_path' => $isVideo ? $file->store('featured-cuts/videos', 'public') : null,
                'sort_order' => ++$order,
                'active' => true,
            ]);
        }

        return redirect()->route('animations.index')->with('success', count($files).' archivo(s) agregados a la espiral. Puedes editar el nombre y el servicio de cada uno.');
    }

    public function updateSpiralText(Request $request)
    {
        // Los textos de la espiral ahora están en el código (config/sitio.php).
        return redirect()->route('animations.index');

        $validated = $request->validate([
            'home_spiral_title' => ['required', 'string', 'max:80'],
            'home_spiral_accent' => ['required', 'string', 'max:120'],
            'home_spiral_note' => ['nullable', 'string', 'max:160'],
        ]);

        $record = SiteSetting::query()->firstOrCreate([], ['data' => SiteSetting::defaults()]);
        $record->data = array_merge(SiteSetting::defaults(), $record->data ?? [], array_map(fn ($v) => (string) $v, $validated));
        $record->save();

        return redirect()->route('animations.index')->with('success', 'Textos de la espiral actualizados.');
    }
}
