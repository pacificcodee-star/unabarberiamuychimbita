<?php

namespace App\Http\Controllers;

use App\Models\Service;
use App\Models\Supply;
use App\Models\User;
use App\Rules\ImageUpload;
use App\Support\BarberCuts;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class BarberController extends Controller
{
    public function index(Request $request)
    {
        // Solo barberos: no tienen acceso al panel (solo el administrador inicia sesión).
        $query = User::query()
            ->where('role', 'barbero')
            ->withSum('sales as total_sales', 'total')
            ->withSum('sales as total_commission', 'commission_amount')
            ->withSum('commissionPayments as total_paid', 'amount')
            ->withSum('supplyDeliveries as total_supplies', 'amount')
            ->with('serviceCommissions')
            ->orderBy('name');

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($sub) use ($search) {
                $sub->where('name', 'like', '%'.$search.'%')
                    ->orWhere('specialty', 'like', '%'.$search.'%');
            });
        }

        $users = $query->paginate(15)->withQueryString();

        return view('barbers.index', compact('users'));
    }

    public function create()
    {
        return view('barbers.create', $this->formOptions());
    }

    public function store(Request $request)
    {
        $data = $this->validatedData($request);
        $data['active'] = $request->boolean('active', true);
        $data['role'] = 'barbero';
        $data['commission_percentage'] = 0; // La comisión se define servicio por servicio.
        $data['attention_mode'] = $data['attention_mode'] ?? 'llegada';
        // El barbero no inicia sesión: se le asigna un correo interno y una clave aleatoria que nadie usa.
        $data['email'] = 'barbero-'.Str::lower(Str::random(12)).'@sin-acceso.local';
        $data['password'] = Str::random(40);
        if ($request->hasFile('photo')) {
            $data['photo_path'] = $request->file('photo')->store('barbers', 'public');
        }
        if ($request->hasFile('video')) {
            $data['video_path'] = $request->file('video')->store('barbers/videos', 'public');
            $this->storeAlphaVideo($request, $data['video_path']);
        }
        unset($data['photo'], $data['video'], $data['video_alpha'], $data['cuts'], $data['remove_cuts'], $data['offered_services']);

        $barber = User::create($data);
        $this->syncRates($request, $barber);
        $this->syncCuts($request, $barber);

        return redirect()
            ->route('barbers.index')
            ->with('success', 'Barbero creado correctamente.');
    }

    public function edit(User $barber)
    {
        abort_if($barber->role !== 'barbero', 404);
        $barber->load(['serviceCommissions', 'supplyPercentages', 'offeredServices']);

        return view('barbers.edit', ['barber' => $barber] + $this->formOptions());
    }

    public function update(Request $request, User $barber)
    {
        abort_if($barber->role !== 'barbero', 404);
        $data = $this->validatedData($request, $barber);
        $data['active'] = $request->boolean('active');
        $data['attention_mode'] = $data['attention_mode'] ?? 'llegada';

        if ($request->hasFile('photo')) {
            $newPath = $request->file('photo')->store('barbers', 'public');
            if ($barber->photo_path) {
                Storage::disk('public')->delete($barber->photo_path);
            }
            $data['photo_path'] = $newPath;
        }

        if ($request->hasFile('video')) {
            $newVideo = $request->file('video')->store('barbers/videos', 'public');
            if ($barber->video_path) {
                Storage::disk('public')->delete([$barber->video_path, $this->alphaPathFor($barber->video_path)]);
            }
            $data['video_path'] = $newVideo;
            $this->storeAlphaVideo($request, $newVideo);
        } elseif ($request->boolean('remove_video') && $barber->video_path) {
            Storage::disk('public')->delete([$barber->video_path, $this->alphaPathFor($barber->video_path)]);
            $data['video_path'] = null;
        }
        unset($data['photo'], $data['video'], $data['video_alpha'], $data['cuts'], $data['remove_cuts'], $data['offered_services']);

        $barber->update($data);
        $this->syncRates($request, $barber);
        $this->syncCuts($request, $barber);

        return redirect()
            ->route('barbers.index')
            ->with('success', 'Barbero actualizado correctamente.');
    }

    public function destroy(Request $request, User $barber)
    {
        abort_if($barber->role !== 'barbero' || $barber->id === $request->user()->id, 403, 'Solo se pueden eliminar barberos.');

        if ($barber->sales()->exists()) {
            $barber->update(['active' => false]);

            return redirect()
                ->route('barbers.index')
                ->with('success', 'El barbero tiene ventas registradas, por eso se desactivó en lugar de eliminarse.');
        }

        $barber->delete();
        foreach (array_filter([$barber->photo_path, $barber->video_path]) as $path) {
            Storage::disk('public')->delete($path);
        }
        BarberCuts::removeAll($barber);

        return redirect()
            ->route('barbers.index')
            ->with('success', 'Barbero eliminado correctamente.');
    }

    public function toggle(User $barber)
    {
        abort_if($barber->role !== 'barbero', 404);

        $barber->update(['active' => ! $barber->active]);

        return redirect()
            ->route('barbers.index')
            ->with('success', $barber->active ? 'Barbero habilitado correctamente.' : 'Barbero deshabilitado correctamente.');
    }

    /** Ruta del video "sin fondo" (color arriba + máscara abajo) que acompaña al video del barbero. */
    private function alphaPathFor(string $videoPath): string
    {
        return preg_replace('/\.(webm|mp4|mov)$/i', '', $videoPath).'.alpha.mp4';
    }

    /** Guarda el video sin fondo que se generó en el navegador (si el admin pulsó «Quitar fondo del video»). */
    private function storeAlphaVideo(Request $request, string $videoPath): void
    {
        if (! $request->hasFile('video_alpha')) {
            return;
        }
        $target = $this->alphaPathFor($videoPath);
        Storage::disk('public')->putFileAs(dirname($target), $request->file('video_alpha'), basename($target));
    }

    /** "Cortes realizados": quita las fotos marcadas y guarda las nuevas (archivos, sin tablas nuevas). */
    private function syncCuts(Request $request, User $barber): void
    {
        BarberCuts::remove($barber, (array) $request->input('remove_cuts', []));
        BarberCuts::store($barber, (array) $request->file('cuts', []));
    }

    /** Servicios e insumos para las tablas de % del formulario. */
    private function formOptions(): array
    {
        return [
            'services' => Service::orderBy('name')->get(),
            'supplies' => Supply::orderBy('name')->get(),
        ];
    }

    /** Guarda el % que gana por servicio y por insumo. Una casilla vacía = usar el valor general. */
    private function syncRates(Request $request, User $barber): void
    {
        $clean = fn ($rows) => collect($rows ?? [])
            ->filter(fn ($value) => $value !== null && $value !== '')
            ->mapWithKeys(fn ($value, $id) => [(int) $id => ['percentage' => min(100, max(0, (float) $value))]])
            ->all();

        // Servicios: una casilla vacía cuenta como 0 %.
        $services = collect($request->input('service_commissions', []))
            ->mapWithKeys(fn ($value, $id) => [(int) $id => ['percentage' => min(100, max(0, (float) ($value ?: 0)))]])
            ->all();
        $barber->serviceCommissions()->sync($services);
        $barber->supplyPercentages()->sync($clean($request->input('supply_percentages')));

        // Servicios que ofrece: las casillas marcadas. Si no se envía el bloque, se dejan como estaban.
        if ($request->has('offered_services_present')) {
            $barber->offeredServices()->sync(collect($request->input('offered_services', []))
                ->mapWithKeys(fn ($value, $id) => [(int) $id => []])
                ->all());
        }
    }

    private function validatedData(Request $request, ?User $user = null): array
    {
        $request->validate([
            'service_commissions' => ['nullable', 'array'],
            'service_commissions.*' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'supply_percentages' => ['nullable', 'array'],
            'supply_percentages.*' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'offered_services' => ['nullable', 'array'],
            'offered_services.*' => ['integer', 'exists:services,id'],
        ], [
            'service_commissions.*.max' => 'Cada porcentaje por servicio debe estar entre 0 y 100.',
            'supply_percentages.*.max' => 'Cada porcentaje de insumo debe estar entre 0 y 100.',
        ]);

        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:30'],
            'specialty' => ['nullable', 'string', 'max:120'],
            'bio' => ['nullable', 'string', 'max:600'],
            'photo' => ['nullable', 'file', new ImageUpload, 'max:20480'],
            'video' => ['nullable', 'file', 'mimetypes:video/webm,video/mp4,video/quicktime', 'max:51200'],
            'video_alpha' => ['nullable', 'file', 'mimetypes:video/mp4,application/octet-stream', 'max:102400'],
            'attention_mode' => ['nullable', 'in:citas,llegada'],
            'cuts' => ['nullable', 'array', 'max:20'],
            'cuts.*' => ['file', new ImageUpload, 'max:20480'],
            'remove_cuts' => ['nullable', 'array'],
            'remove_cuts.*' => ['string', 'max:255'],
        ], [
            'cuts.max' => 'Puedes subir hasta 20 fotos a la vez.',
            'cuts.*.max' => 'Cada foto de corte puede pesar máximo 20 MB.',
        ]);
    }
}
