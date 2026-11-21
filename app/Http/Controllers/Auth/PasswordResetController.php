<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\AuditLog;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;

/**
 * Recuperación de contraseña del panel (solo administradores activos).
 * - El enlace lleva un token de un solo uso que Laravel guarda con hash y vence en 60 minutos.
 * - La respuesta es siempre la misma, exista o no el correo (no se revela quién tiene cuenta).
 * - Al cambiarla se cierran todas las sesiones abiertas de esa cuenta.
 */
class PasswordResetController extends Controller
{
    public const SENT_MESSAGE = 'Si el correo pertenece a una cuenta del equipo, te enviamos un enlace para crear una contraseña nueva. Revisa tu bandeja (y el spam). El enlace vence en 60 minutos.';

    public function request()
    {
        return view('auth.forgot-password');
    }

    public function email(Request $request)
    {
        $data = $request->validate(['email' => ['required', 'email', 'max:150']]);

        $status = Password::sendResetLink(['email' => $data['email'], 'role' => 'admin', 'active' => true]);
        Log::notice('auth.recuperacion_solicitada', ['resultado' => $status]);

        return back()->with('status', self::SENT_MESSAGE);
    }

    public function edit(Request $request, string $token)
    {
        return view('auth.reset-password', ['token' => $token, 'email' => (string) $request->query('email', '')]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email', 'max:150'],
            'password' => ['required', 'confirmed', PasswordRule::min(10)->letters()->numbers()],
        ], [
            'password.confirmed' => 'Las dos contraseñas no coinciden.',
        ]);

        $status = Password::reset(
            ['email' => $data['email'], 'password' => $data['password'], 'password_confirmation' => $request->input('password_confirmation'), 'token' => $data['token'], 'role' => 'admin', 'active' => true],
            function (User $user, string $password) {
                $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
                // Fuera todas las sesiones abiertas de esa cuenta (por si alguien más la usaba).
                if (config('session.driver') === 'database') {
                    DB::table(config('session.table', 'sessions'))->where('user_id', $user->getKey())->delete();
                }
                event(new PasswordReset($user));
                AuditLog::record('auth.contrasena_cambiada', $user);
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            Log::notice('auth.recuperacion_rechazada', ['resultado' => $status]);

            return back()->withInput($request->only('email'))
                ->withErrors(['email' => 'El enlace no es válido o ya venció. Pide uno nuevo.']);
        }

        return redirect()->route('login')->with('status', 'Listo: tu contraseña se cambió. Ya puedes ingresar.');
    }
}
