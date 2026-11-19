<?php

namespace App\Http\Controllers;

use App\Models\Service;
use App\Rules\ImageUpload;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class ServiceController extends Controller
{
    public function index(Request $request)
    {
        $query = Service::query()->orderBy('name');

        if ($request->filled('search')) {
            $query->where('name', 'like', '%'.$request->input('search').'%');
        }

        $services = $query->paginate(12)->withQueryString();

        // Cuántas veces se ha realizado cada servicio y cuánto ha dejado (todo el tiempo), igual que en Informes.
        $stats = \App\Models\SaleItem::query()
            ->whereIn('service_id', $services->pluck('id'))
            ->groupBy('service_id')
            ->select('service_id', \Illuminate\Support\Facades\DB::raw('SUM(quantity) as qty'), \Illuminate\Support\Facades\DB::raw('SUM(subtotal) as total'))
            ->get()
            ->keyBy('service_id');

        return view('services.index', compact('services', 'stats'));
    }

    public function create()
    {
        return view('services.create');
    }

    public function store(Request $request)
    {
        $data = $this->validatedData($request);
        $data['active'] = $request->boolean('active', true);
        if ($request->hasFile('image')) {
            $data['image_path'] = $request->file('image')->store('services', 'public');
        }
        unset($data['image']);

        Service::create($data);

        return redirect()
            ->route('services.index')
            ->with('success', 'Servicio creado correctamente.');
    }

    public function edit(Service $service)
    {
        return view('services.edit', compact('service'));
    }

    public function update(Request $request, Service $service)
    {
        $data = $this->validatedData($request);
        $data['active'] = $request->boolean('active');

        if ($request->hasFile('image')) {
            $newPath = $request->file('image')->store('services', 'public');
            if ($service->image_path) {
                Storage::disk('public')->delete($service->image_path);
            }
            $data['image_path'] = $newPath;
        }
        unset($data['image']);

        $service->update($data);

        return redirect()
            ->route('services.index')
            ->with('success', 'Servicio actualizado correctamente.');
    }

    public function destroy(Service $service)
    {
        if ($service->saleItems()->exists() || $service->appointments()->exists()) {
            $service->update(['active' => false]);

            return redirect()
                ->route('services.index')
                ->with('success', 'El servicio tiene registros asociados, por eso se desactivó en lugar de eliminarse.');
        }

        $service->delete();
        if ($service->image_path) {
            Storage::disk('public')->delete($service->image_path);
        }

        return redirect()
            ->route('services.index')
            ->with('success', 'Servicio eliminado correctamente.');
    }

    private function validatedData(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'category' => ['nullable', 'string', Rule::in(array_keys(Service::CATEGORIES))],
            'description' => ['nullable', 'string', 'max:500'],
            'image' => ['nullable', 'file', new ImageUpload, 'max:20480'],
            'price' => ['required', 'numeric', 'min:0'],
            'duration_minutes' => ['required', 'integer', 'min:5', 'max:480'],
        ], [
            'category.in' => 'Elige una categoría válida.',
        ]);

        $data['category'] = filled($data['category'] ?? null) ? $data['category'] : Service::guessCategory($data['name']);

        return $data;
    }
}
