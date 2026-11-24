<?php

namespace App\Http\Controllers;

use App\Models\SiteSetting;
use App\Rules\ImageUpload;
use App\Support\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Rules\Password as PasswordRule;

/** Configuración del negocio: datos de contacto, horarios, redes, medios de pago y logo. Los textos de la página están en config/sitio.php. */
class SiteSettingController extends Controller
{
    public function edit()
    {
        $settings = SiteSetting::currentData();

        return view('site-settings.edit', compact('settings'));
    }

    public function update(Request $request)
    {
        $validated = $request->validate([
            'data.address' => ['required', 'string', 'max:200'],
            'data.city' => ['required', 'string', 'max:120'],
            'data.phone' => ['required', 'string', 'max:40'],
            'data.email' => ['required', 'email', 'max:160'],
            'data.whatsapp' => ['required', 'regex:/^[0-9]{10,15}$/'],
            // Horario por día: "Cerrado" o un rango de horas (abre / cierra).
            'hours' => ['required', 'array'],
            'hours.*.closed' => ['nullable', 'boolean'],
            'hours.*.open' => ['nullable', 'date_format:H:i'],
            'hours.*.close' => ['nullable', 'date_format:H:i'],
            'data.booking_start' => ['required', 'date_format:H:i'],
            'data.booking_end' => ['required', 'date_format:H:i', 'after:data.booking_start'],
            'data.map_url' => ['required', 'url', 'max:500'],
            'data.map_coords' => ['nullable', 'regex:/^\s*-?\d{1,2}(\.\d+)?\s*,\s*-?\d{1,3}(\.\d+)?\s*$/'],
            'data.instagram_url' => ['nullable', 'url', 'max:300'],
            'data.facebook_url' => ['nullable', 'url', 'max:300'],
            'data.tiktok_url' => ['nullable', 'url', 'max:300'],
            'data.account_nequi' => ['nullable', 'string', 'max:120'],
            'data.account_daviplata' => ['nullable', 'string', 'max:120'],
            'data.account_transfer' => ['nullable', 'string', 'max:255'],
            'data.account_breb' => ['nullable', 'string', 'max:120'],
            'logo' => ['nullable', 'file', new ImageUpload, 'max:20480'],
        ], [
            'data.whatsapp.regex' => 'El WhatsApp debe tener solo números, con indicativo (ej. 573001234567).',
            'data.instagram_url.url' => 'El enlace de Instagram debe empezar por https://',
            'data.facebook_url.url' => 'El enlace de Facebook debe empezar por https://',
            'data.tiktok_url.url' => 'El enlace de TikTok debe empezar por https://',
            'data.map_coords.regex' => 'Las coordenadas deben ser «latitud,longitud», por ejemplo 1.8067,-78.7647.',
            'data.booking_end.after' => 'La hora final de reservas debe ser después de la hora inicial.',
        ]);

        // Cada día abierto necesita hora de apertura y de cierre, y el cierre va después de la apertura.
        $hours = [];
        $hourErrors = [];
        foreach (SiteSetting::DAYS as $day => [$dayName]) {
            $row = $request->input('hours.'.$day, []);
            if (filter_var($row['closed'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                $hours['hours_'.$day] = 'Cerrado';

                continue;
            }
            $open = (string) ($row['open'] ?? '');
            $close = (string) ($row['close'] ?? '');
            if ($open === '' || $close === '') {
                $hourErrors['hours.'.$day] = "{$dayName}: elige la hora en que abre y la hora en que cierra, o márcalo como cerrado.";
            } elseif ($close <= $open) {
                $hourErrors['hours.'.$day] = "{$dayName}: la hora de cierre debe ser después de la hora de apertura.";
            } else {
                $hours['hours_'.$day] = SiteSetting::rangeText($open, $close);
            }
        }
        if ($hourErrors) {
            throw ValidationException::withMessages($hourErrors);
        }

        $record = SiteSetting::query()->firstOrCreate([], ['data' => []]);
        $data = $record->data ?? [];

        foreach ($validated['data'] as $key => $value) {
            $data[$key] = trim((string) $value);
        }
        $data = array_merge($data, $hours);
        // El teléfono para enlaces (tel:) se arma solo, a partir del teléfono visible.
        $data['phone_link'] = '+'.ltrim(preg_replace('/\D+/', '', $data['phone']), '+');

        foreach (array_keys(SiteSetting::PAYMENT_METHODS) as $method) {
            $data['payment_'.$method] = $request->boolean('data.payment_'.$method);
        }

        if ($request->hasFile('logo')) {
            $oldPath = $data['logo_path'] ?? null;
            $data['logo_path'] = $request->file('logo')->store('site', 'public');
            if ($oldPath) {
                Storage::disk('public')->delete($oldPath);
            }
        } elseif ($request->boolean('remove_logo') && ! empty($data['logo_path'])) {
            Storage::disk('public')->delete($data['logo_path']);
            $data['logo_path'] = '';
        }

        $record->data = $data;
        $record->save();

        return redirect()->route('site-settings.edit')->with('success', 'Configuración del negocio guardada.');
    }

    /**
     * Cuenta del administrador: correo de ingreso y contraseña.
     * Siempre pide la contraseña actual; la nueva es opcional (si se deja vacía, no cambia).
     */
    public function updateAccount(Request $request)
    {
        $user = $request->user();

        $data = $request->validateWithBag('account', [
            'email' => ['required', 'email', 'max:150', Rule::unique('users', 'email')->ignore($user->id)],
            'current_password' => ['required', 'current_password'],
            'password' => ['nullable', 'confirmed', PasswordRule::min(10)->letters()->numbers()],
        ], [
            'current_password.required' => 'Escribe tu contraseña actual para guardar los cambios.',
            'current_password.current_password' => 'La contraseña actual no es correcta.',
            'email.unique' => 'Ese correo ya lo usa otra cuenta.',
            'password.confirmed' => 'Las dos contraseñas nuevas no coinciden.',
            'password.min' => 'La contraseña nueva debe tener al menos 10 caracteres.',
            'password.letters' => 'La contraseña nueva debe tener al menos una letra.',
            'password.numbers' => 'La contraseña nueva debe tener al menos un número.',
        ]);

        $user->email = Str::lower(trim($data['email']));
        $emailChanged = $user->isDirty('email');
        $passwordChanged = filled($data['password'] ?? null);
        if ($passwordChanged) {
            $user->password = $data['password'];
            $user->remember_token = Str::random(60);
        }
        $user->save();
        if ($emailChanged) {
            AuditLog::record('auth.correo_cambiado', $user);
        }

        if ($passwordChanged) {
            // Se cierran las demás sesiones abiertas con esta cuenta; esta sigue abierta.
            if (config('session.driver') === 'database') {
                DB::table(config('session.table', 'sessions'))
                    ->where('user_id', $user->getKey())
                    ->where('id', '!=', $request->session()->getId())
                    ->delete();
            }
            $request->session()->regenerate();
            AuditLog::record('auth.contrasena_cambiada', $user);
        }

        return redirect()->to(route('site-settings.edit').'#cuenta-admin')
            ->with('success', $passwordChanged ? 'Cuenta actualizada y contraseña cambiada.' : 'Cuenta actualizada.');
    }
}
