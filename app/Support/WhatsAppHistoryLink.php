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
    private const SESSION_KEY = 'whatsapp_history_link';

    private const REQUEST_KEY = 'whatsapp_history_request';

    private const APPROVE = 'Autorizo consultar mi historial en el dispositivo donde ingresé mi número ';

    public function __construct(private WhatsAppService $whatsApp) {}

    public function respondToCommand(string $message, ?string $sender): ?string
    {
        if (str_starts_with(trim($message), self::APPROVE)) {
            $code = substr(trim($message), strlen(self::APPROVE));
            $phone = $sender === null ? null : $this->whatsApp->normalizePhone($sender, international: true);
            if ($phone && preg_match('/^[A-Za-z0-9]{32}$/D', $code) && Cache::get($this->key($code)) === $phone) {
                Cache::put($this->key($code).':approved', $phone, now()->addMinutes(10));

                return 'Número confirmado. Vuelve a la web o a la app donde empezaste para ver tus servicios.';
            }

            return 'No se pudo confirmar ese dispositivo. Inicia la consulta otra vez con este número.';
        }

        // También reconoce el mensaje preparado por versiones anteriores de la página.
        if (! preg_match('/^vincular (?:mi )?historial(?: [A-Za-z0-9]{32})?[.!]?$/i', trim($message))) {
            return null;
        }

        if ($sender === null) {
            return 'Para vincular el historial con la web se requiere un número visible. Puedes consultar tus reservas en este chat desde el menú *Consultar mi cita o turno*.';
        }

        $phone = $this->whatsApp->normalizePhone($sender, international: true);
        if (! preg_match('/^[1-9][0-9]{6,14}$/', $phone)) {
            return 'No pude identificar tu número. Escribe de nuevo desde el WhatsApp donde hiciste tus reservas.';
        }

        $token = Str::random(48);
        Cache::put($this->key($token), $phone, now()->addMinutes(10));
        $path = route('my-services.whatsapp.open', ['token' => $token], absolute: false);
        $url = rtrim((string) config('app.url'), '/').$path;

        return 'Aquí está tu enlace privado para vincular este historial. Ábrelo en el navegador donde quieras consultar tus reservas. Es de un solo uso y vence en 10 minutos.'."\n\n".$url;
    }

    public function begin(Request $request, string $input): bool
    {
        $phone = $this->whatsApp->normalizePhone($input);
        if (! preg_match('/^[+0-9().\s-]+$/D', $input) || ! preg_match('/^[1-9][0-9]{6,14}$/D', $phone)) {
            throw ValidationException::withMessages(['client_phone' => 'Escribe un número de WhatsApp válido.']);
        }
        if (ClientServiceAccess::whatsappPhone($request) === $phone) {
            return true;
        }
        abort_unless(config('services.evolution.bot_enabled') && filled(SiteSetting::currentData()['whatsapp'] ?? null), 404);

        $code = Str::random(32);
        Cache::put($this->key($code), $phone, now()->addMinutes(10));
        $request->session()->forget(self::SESSION_KEY);
        $request->session()->put(self::REQUEST_KEY, $code);

        return false;
    }

    public function chatUrl(Request $request): ?string
    {
        $code = $request->session()->get(self::REQUEST_KEY);
        if (! is_string($code)) {
            return null;
        }
        $business = $this->whatsApp->normalizePhone((string) SiteSetting::currentData()['whatsapp']);

        return 'https://wa.me/'.$business.'?text='.rawurlencode(self::APPROVE.$code);
    }

    public function stage(Request $request, string $token): void
    {
        abort_unless(is_string(Cache::get($this->key($token))), 404);
        // Una vista previa de WhatsApp solo abre el enlace. El acceso se concede al confirmar.
        $request->session()->forget(self::REQUEST_KEY);
        $request->session()->put(self::SESSION_KEY, $token);
    }

    public function maskedPhone(Request $request): string
    {
        $token = $this->sessionToken($request);
        $phone = Cache::get($this->key($token));
        abort_unless(is_string($phone), 404);

        return '•••• '.substr($phone, -4);
    }

    public function finish(Request $request): void
    {
        $key = $this->key($this->sessionToken($request));
        $linked = Cache::lock($key.':lock', 30)->get(function () use ($request, $key) {
            $phone = Cache::get($key);
            if (! is_string($phone)) {
                return false;
            }

            if ($request->session()->has(self::REQUEST_KEY) && Cache::get($key.':approved') !== $phone) {
                throw ValidationException::withMessages(['whatsapp' => 'Envía el mensaje desde tu WhatsApp y vuelve a consultar.']);
            }

            ClientServiceAccess::connectWhatsApp($request, $phone);
            Cache::forget($key);
            Cache::forget($key.':approved');
            $request->session()->forget([self::SESSION_KEY, self::REQUEST_KEY]);

            return true;
        });
        abort_unless($linked, 404);
    }

    private function sessionToken(Request $request): string
    {
        $token = $request->session()->get(self::SESSION_KEY) ?? $request->session()->get(self::REQUEST_KEY);
        abort_unless(is_string($token), 404);
        if ($request->session()->has(self::REQUEST_KEY) && ! Cache::has($this->key($token))) {
            $request->session()->forget(self::REQUEST_KEY);
            throw ValidationException::withMessages(['whatsapp' => 'La confirmación venció. Ingresa tu número para volver a consultar.'])
                ->redirectTo(route('public.booking'));
        }

        return $token;
    }

    private function key(string $token): string
    {
        return 'whatsapp:history-link:'.hash('sha256', $token);
    }
}
