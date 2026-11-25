<?php

namespace App\Support;

use App\Services\WhatsAppService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class WhatsAppHistoryLink
{
    private const SESSION_KEY = 'whatsapp_history_link';

    public function __construct(private WhatsAppService $whatsApp) {}

    public function respondToCommand(string $message, string $sender): ?string
    {
        // También reconoce el mensaje preparado por versiones anteriores de la página.
        if (! preg_match('/^vincular (?:mi )?historial(?: [A-Za-z0-9]{32})?[.!]?$/i', trim($message))) {
            return null;
        }

        $phone = $this->whatsApp->normalizePhone($sender);
        if (! preg_match('/^[1-9][0-9]{9,14}$/', $phone)) {
            return 'No pude identificar tu número. Escribe de nuevo desde el WhatsApp donde hiciste tus reservas.';
        }

        $token = Str::random(48);
        Cache::put($this->key($token), $phone, now()->addMinutes(10));
        $path = route('my-services.whatsapp.open', ['token' => $token], absolute: false);
        $url = rtrim((string) config('app.url'), '/').$path;

        return 'Aquí está tu enlace privado para vincular este historial. Ábrelo en el navegador donde quieras consultar tus reservas. Es de un solo uso y vence en 10 minutos.'."\n\n".$url;
    }

    public function stage(Request $request, string $token): void
    {
        abort_unless(is_string(Cache::get($this->key($token))), 404);
        // Una vista previa de WhatsApp solo abre el enlace. El acceso se concede al confirmar.
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

            ClientServiceAccess::connectWhatsApp($request, $phone);
            Cache::forget($key);
            $request->session()->forget(self::SESSION_KEY);

            return true;
        });
        abort_unless($linked, 404);
    }

    private function sessionToken(Request $request): string
    {
        $token = $request->session()->get(self::SESSION_KEY);
        abort_unless(is_string($token), 404);

        return $token;
    }

    private function key(string $token): string
    {
        return 'whatsapp:history-link:'.hash('sha256', $token);
    }
}
