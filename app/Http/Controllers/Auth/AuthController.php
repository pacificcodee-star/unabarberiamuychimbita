<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Support\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    public function showLogin()
    {
        if (Auth::check()) {
            return redirect()->route('dashboard');
        }

        return view('auth.login');
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        // Contra fuerza bruta: 5 intentos fallidos por correo + IP; después se espera.
        $throttleKey = 'login:'.Str::lower($credentials['email']).'|'.$request->ip();
        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            Log::warning('auth.login_bloqueado', ['ip' => $request->ip()]);

            return back()
                ->withErrors(['email' => 'Demasiados intentos. Espera '.RateLimiter::availableIn($throttleKey).' segundos e inténtalo de nuevo.'])
                ->onlyInput('email');
        }

        // Solo el administrador tiene acceso al panel.
        if (Auth::attempt([...$credentials, 'role' => 'admin', 'active' => true], $request->boolean('remember'))) {
            RateLimiter::clear($throttleKey);
            $request->session()->regenerate();
            AuditLog::record('auth.ingreso');

            return redirect()->intended(route('dashboard'));
        }

        RateLimiter::hit($throttleKey, 60);
        // Sin el correo ni la contraseña en el registro: solo la IP.
        Log::notice('auth.login_fallido', ['ip' => $request->ip()]);

        return back()
            ->withErrors(['email' => 'El correo o la contraseña no son correctos.'])
            ->onlyInput('email');
    }

    public function logout(Request $request)
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
