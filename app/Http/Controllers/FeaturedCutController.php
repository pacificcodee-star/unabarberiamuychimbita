<?php

namespace App\Http\Controllers;

use App\Models\FeaturedCut;
use App\Models\Service;
use App\Rules\ImageUpload;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class FeaturedCutController extends Controller
{
    public function index()
    {
        // La lista de cortes ahora vive en la pestaña "Animaciones".
        return redirect()->route('animations.index');
    }

    public function create()
    {
        return view('featured-cuts.form', [
            'cut' => null,
            'services' => Service::active()->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validatedData($request, true);
        $data['image_path'] = $request->hasFile('image') ? $request->file('image')->store('featured-cuts', 'public') : null;
        $data['video_path'] = $request->hasFile('video') ? $request->file('video')->store('featured-cuts/videos', 'public') : null;

        foreach ([1, 2, 3] as $n) {
            if ($request->hasFile('gallery_'.$n)) {
                $data['gallery_'.$n.'_path'] = $request->file('gallery_'.$n)->store('featured-cuts', 'public');
            }
        }

        $data['active'] = $request->boolean('active', true);
        FeaturedCut::create($this->onlyColumns($data));

        return redirect()->route('animations.index')->with('success', 'Corte destacado agregado al inicio.');
    }

    public function edit(FeaturedCut $featured_cut)
    {
        return view('featured-cuts.form', [
            'cut' => $featured_cut,
            'services' => Service::active()->orderBy('name')->get(),
        ]);
    }

    public function update(Request $request, FeaturedCut $featured_cut)
    {
        $data = $this->validatedData($request, false);
        $data['active'] = $request->boolean('active');

        if ($request->hasFile('image')) {
            $newPath = $request->file('image')->store('featured-cuts', 'public');
            if ($featured_cut->image_path) {
                Storage::disk('public')->delete($featured_cut->image_path);
            }
            $data['image_path'] = $newPath;
        }

        if ($request->hasFile('video')) {
            $data['video_path'] = $request->file('video')->store('featured-cuts/videos', 'public');
            if ($featured_cut->video_path) {
                Storage::disk('public')->delete($featured_cut->video_path);
            }
        } elseif ($request->boolean('remove_video') && $featured_cut->video_path) {
            if (! $featured_cut->image_path && ! $request->hasFile('image')) {
                return back()->withInput()->withErrors(['video' => 'Para quitar el video primero sube una imagen: el corte necesita al menos una de las dos.']);
            }
            Storage::disk('public')->delete($featured_cut->video_path);
            $data['video_path'] = null;
        }

        foreach ([1, 2, 3] as $n) {
            $field = 'gallery_'.$n.'_path';
            $old = $featured_cut->{$field};

            if ($request->hasFile('gallery_'.$n)) {
                $data[$field] = $request->file('gallery_'.$n)->store('featured-cuts', 'public');
                if ($old) {
                    Storage::disk('public')->delete($old);
                }
            } elseif ($request->boolean('remove_gallery_'.$n) && $old) {
                Storage::disk('public')->delete($old);
                $data[$field] = null;
            }
        }

        $featured_cut->update($this->onlyColumns($data));

        return redirect()->route('animations.index')->with('success', 'Corte destacado actualizado.');
    }

    public function destroy(FeaturedCut $featured_cut)
    {
        foreach (array_filter([$featured_cut->image_path, $featured_cut->video_path, ...$featured_cut->galleryPaths()]) as $path) {
            Storage::disk('public')->delete($path);
        }
        $featured_cut->delete();

        return redirect()->route('animations.index')->with('success', 'Corte destacado retirado del inicio.');
    }

    private function validatedData(Request $request, bool $imageRequired): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'subtitle' => ['nullable', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:500'],
            'image' => [$imageRequired ? 'required_without:video' : 'nullable', 'nullable', 'file', new ImageUpload, 'max:20480'],
            'video' => ['nullable', 'file', 'mimetypes:video/mp4,video/webm,video/quicktime', 'max:51200'],
            'gallery_1' => ['nullable', 'file', new ImageUpload, 'max:20480'],
            'gallery_2' => ['nullable', 'file', new ImageUpload, 'max:20480'],
            'gallery_3' => ['nullable', 'file', new ImageUpload, 'max:20480'],
            'service_id' => ['nullable', 'integer', 'exists:services,id'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:999'],
        ], [
            'title.required' => 'Escribe el nombre del corte.',
            'image.required_without' => 'Sube una imagen o un video del corte.',
            'video.mimetypes' => 'El video debe ser MP4, WebM o MOV.',
            'video.max' => 'El video puede pesar hasta 50 MB.',
        ]);
    }

    private function onlyColumns(array $data): array
    {
        return array_intersect_key($data, array_flip((new FeaturedCut)->getFillable()));
    }
}
