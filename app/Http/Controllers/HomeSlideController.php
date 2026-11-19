<?php

namespace App\Http\Controllers;

use App\Models\HomeSlide;
use App\Rules\ImageUpload;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class HomeSlideController extends Controller
{
    public function index()
    {
        $slides = HomeSlide::orderBy('sort_order')->orderBy('id')->get();

        return view('home-slides.index', compact('slides'));
    }

    public function create()
    {
        abort_if(HomeSlide::count() >= 10, 403, 'El carrusel admite hasta 10 imágenes.');

        return view('home-slides.form', ['slide' => null]);
    }

    public function store(Request $request)
    {
        abort_if(HomeSlide::count() >= 10, 403, 'El carrusel admite hasta 10 imágenes.');
        $data = $this->validatedData($request, true);
        $data['image_path'] = $request->file('image')->store('home-slides', 'public');
        unset($data['image']);
        $data['active'] = $request->boolean('active', true);
        HomeSlide::create($data);

        return redirect()->route('home-slides.index')->with('success', 'Imagen agregada al carrusel.');
    }

    public function edit(HomeSlide $home_slide)
    {
        return view('home-slides.form', ['slide' => $home_slide]);
    }

    public function update(Request $request, HomeSlide $home_slide)
    {
        $data = $this->validatedData($request, false);
        $data['active'] = $request->boolean('active');

        if ($request->hasFile('image')) {
            $newPath = $request->file('image')->store('home-slides', 'public');
            Storage::disk('public')->delete($home_slide->image_path);
            $data['image_path'] = $newPath;
        }
        unset($data['image']);

        $home_slide->update($data);

        return redirect()->route('home-slides.index')->with('success', 'Imagen del carrusel actualizada.');
    }

    public function destroy(HomeSlide $home_slide)
    {
        Storage::disk('public')->delete($home_slide->image_path);
        $home_slide->delete();

        return redirect()->route('home-slides.index')->with('success', 'Imagen retirada del carrusel.');
    }

    private function validatedData(Request $request, bool $imageRequired): array
    {
        return $request->validate([
            'title' => ['nullable', 'string', 'max:120'],
            'image' => [$imageRequired ? 'required' : 'nullable', 'file', new ImageUpload, 'max:20480'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:999'],
        ]);
    }
}
