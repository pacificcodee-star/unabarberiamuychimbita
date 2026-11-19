<?php

namespace App\Http\Controllers;

use App\Services\WhatsApp\WhatsAppInbox;
use App\Services\WhatsApp\WhatsappBotService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Webhook de Evolution API: aquí entran los mensajes de WhatsApp del negocio.
 *
 * Eventos que llegan (ver docs/WHATSAPP.md):
 *  - messages.upsert  → mensaje entrante: se guarda en la bandeja y actúa el bot
 *  - messages.update  → estado de lo que enviamos (entregado / leído)
 *  - connection.update / qrcode.updated → estado de la conexión
 */
class EvolutionWebhookController extends Controller
{
    public function __invoke(
        Request $request,
        WhatsAppInbox $inbox,
        WhatsappBotService $bot,
    ) {
        if (! $this->authorized($request)) {
            Log::warning('Webhook de Evolution rechazado: apikey inválida', ['ip' => $request->ip()]);

            return response()->json(['error' => 'apikey inválida'], 401);
        }

        $event = (string) $request->input('event', '');
        $data = $request->input('data');
        $data = is_array($data) ? $data : [];

        try {
            if ($event === 'messages.upsert') {
                $this->incoming($data, $inbox, $bot);
            } elseif ($event === 'messages.update') {
                $inbox->applyStatusUpdate($data);
            } elseif ($event === 'connection.update') {
                $this->connection($data);
            } elseif ($event === 'qrcode.updated') {
                Cache::put('evolution.connection_state', 'connecting', now()->addDay());
            } elseif (in_array($event, ['logout_instance', 'remove_instance'], true)) {
                Cache::put('evolution.connection_state', 'close', now()->addDay());
            }
        } catch (Throwable $e) {
            // Siempre 200: un error aquí haría que Evolution reenvíe el mismo mensaje 10 veces.
            Log::error('Error procesando webhook de Evolution', ['event' => $event, 'error' => $e->getMessage()]);
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Seguridad: Evolution incluye su apikey global en el cuerpo ("apikey") y en la
     * cabecera de cada evento. Se acepta si coincide con EVOLUTION_WEBHOOK_SECRET
     * (secreto propio) o con EVOLUTION_APIKEY. Sin ninguno configurado no se acepta nada.
     */
    protected function authorized(Request $request): bool
    {
        $given = (string) ($request->input('apikey') ?: $request->header('apikey', ''));

        if ($given === '') {
            return false;
        }

        foreach ([config('services.evolution.webhook_secret'), config('services.evolution.apikey')] as $expected) {
            if (filled($expected) && hash_equals((string) $expected, $given)) {
                return true;
            }
        }

        return false;
    }

    protected function incoming(array $data, WhatsAppInbox $inbox, WhatsappBotService $bot): void
    {
        $stored = $inbox->recordIncoming($data);

        if (! $stored) {
            return; // mensaje nuestro, de grupo o ya procesado (reintento del webhook)
        }

        // Mensaje viejo (reintento o historial): se guarda, pero el bot no contesta.
        $timestamp = $data['messageTimestamp'] ?? null;
        if (is_numeric($timestamp) && $timestamp < now()->subMinutes(5)->getTimestamp()) {
            return;
        }

        $bot->handle($stored['conversation'], $stored['message']);
    }

    protected function connection(array $data): void
    {
        $state = $data['state'] ?? ($data['instance']['state'] ?? null);

        if (is_string($state) && $state !== '') {
            Cache::put('evolution.connection_state', $state, now()->addDay());
        }
    }
}
