<?php

namespace App\Support;

use App\Models\SiteSetting;
use App\Services\WhatsAppService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class WhatsAppHistoryLink
{
    private const SESSION_KEY = 'whatsapp_history_challenge';

    private const COMMAND = 'Vincular historial ';

    public function __construct(private WhatsAppService $whatsApp) {}

    public function available(): bool
    {
        return (bool) config('services.evolution.bot_enabled') && $this->whatsApp->isConfigured()
            && filled(SiteSetting::currentData()['whatsapp'] ?? null);
    }

    public function begin(Request $request): void
    {
        abort_unless($this->available(), 404);
        $token = Str::random(32);
        $request->session()->put(self::SESSION_KEY, $token);
        Cache::put($this->key($token), ['phone' => null], now()->addMinutes(10));
    }

    public function chatUrl(Request $request): ?string
    {
        $token = $request->session()->get(self::SESSION_KEY);
        if (! $this->available() || ! is_string($token) || ! Cache::has($this->key($token))) {
            return null;
        }

        $business = $this->whatsApp->normalizePhone((string) SiteSetting::currentData()['whatsapp']);

        return 'https://wa.me/'.$business.'?text='.rawurlencode(self::COMMAND.$token);
    }

    public function acknowledge(string $message, string $sender): ?string
    {
        if (! preg_match('/^vincular historial ([A-Za-z0-9]{32})$/i', trim($message), $match)) {
            return null;
        }

        $key = $this->key($match[1]);
        $phone = $this->whatsApp->normalizePhone($sender);
        $confirmed = Cache::lock($key.':lock', 15)->get(function () use ($key, $phone) {
            $challenge = Cache::get($key);
            if (! $challenge || ! preg_match('/^[1-9][0-9]{9,14}$/', $phone)) {
                return false;
            }

            if ($challenge['phone'] !== null) {
                return $challenge['phone'] === $phone;
            }

            Cache::put($key, ['phone' => $phone], now()->addMinutes(5));

            return true;
        });

        return $confirmed
            ? 'Confirmamos tu WhatsApp. Vuelve a Mis servicios en el dispositivo donde empezaste y pulsa Actualizar mi historial.'
            : 'Este enlace de vinculación ya no está disponible. Empieza de nuevo desde Mis servicios.';
    }

    public function finish(Request $request): void
    {
        $token = $request->session()->get(self::SESSION_KEY);
        if (! is_string($token)) {
            throw ValidationException::withMessages(['historial' => 'Inicia la vinculación desde Mis servicios.']);
        }

        $key = $this->key($token);
        $linked = Cache::lock($key.':lock', 30)->get(function () use ($request, $key) {
            $phone = Cache::get($key)['phone'] ?? null;
            if (! is_string($phone)) {
                return false;
            }

            ClientServiceAccess::connectWhatsApp($request, $phone);
            Cache::forget($key);
            $request->session()->forget(self::SESSION_KEY);

            return true;
        });

        if (! $linked) {
            throw ValidationException::withMessages(['historial' => 'Envía el mensaje desde WhatsApp y vuelve a intentarlo. Si el enlace venció, inicia la vinculación otra vez.']);
        }
    }

    private function key(string $token): string
    {
        return 'whatsapp-history:'.hash('sha256', $token);
    }
}
