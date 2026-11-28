<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessWhatsAppMessage;
use App\Services\WhatsApp\WhatsAppInbox;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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
                foreach (array_is_list($data) ? $data : [$data] as $item) {
                    if (is_array($item)) {
                        $this->incoming($item, $inbox);
                    }
                }
            } elseif ($event === 'messages.update') {
                foreach (array_is_list($data) ? $data : [$data] as $item) {
                    if (is_array($item)) {
                        $inbox->applyStatusUpdate($item);
                    }
                }
            } elseif ($event === 'connection.update') {
                $this->connection($data);
            } elseif ($event === 'qrcode.updated') {
                Cache::put('evolution.connection_state', 'connecting', now()->addDay());
            } elseif (in_array($event, ['logout_instance', 'remove_instance'], true)) {
                Cache::put('evolution.connection_state', 'close', now()->addDay());
            }
        } catch (Throwable $e) {
            Log::error('Error procesando webhook de Evolution', ['event' => $event, 'error' => $e->getMessage()]);
            if ($event === 'messages.upsert' && config('services.evolution.queue_incoming')) {
                return response()->json(['ok' => false], 503);
            }
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
        $given = array_filter([
            (string) $request->header('apikey', ''),
            (string) $request->input('apikey', ''),
        ]);

        if ($given === []) {
            return false;
        }

        foreach ([config('services.evolution.webhook_secret'), config('services.evolution.apikey')] as $expected) {
            if (filled($expected)) {
                foreach ($given as $candidate) {
                    if (hash_equals((string) $expected, $candidate)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    protected function incoming(array $data, WhatsAppInbox $inbox): void
    {
        if (! empty($data['key']['fromMe'])) {
            $inbox->recordPhoneReply($data);

            return;
        }

        $queued = (bool) config('services.evolution.queue_incoming');
        $receive = function () use ($data, $inbox, $queued) {
            $stored = $inbox->recordIncoming($data);
            $timestamp = $data['messageTimestamp'] ?? null;
            if (! $stored || (is_numeric($timestamp) && $timestamp < now()->subMinutes(5)->getTimestamp())) {
                return;
            }

            $id = $stored['message']->id;
            $respondAt = now()->addSeconds((int) config('services.evolution.reply_delay_seconds', 3));
            if ($queued) {
                // En el VPS el trabajo y el mensaje comparten la misma transacción de base de datos.
                // El delay lo aplica la cola: el worker queda libre para atender otras conversaciones.
                ProcessWhatsAppMessage::dispatch($id, $stored['notifyTeam'], $respondAt)
                    ->delay($respondAt)
                    ->beforeCommit();
            } elseif (app()->runningUnitTests()) {
                // En las pruebas no hay una respuesta HTTP que esperar (la app se reutiliza entre
                // peticiones), así que se procesa en línea como siempre.
                ProcessWhatsAppMessage::dispatchSync($id, $stored['notifyTeam'], $respondAt);
            } else {
                // Sin cola: se contesta a Evolution ENSEGUIDA y el bot trabaja después (Laravel
                // cierra la petición HTTP y luego ejecuta el trabajo). Antes se hacía dispatchSync:
                // con varios clientes escribiendo a la vez el webhook tardaba más de lo que
                // Evolution espera, Evolution reintentaba el mismo evento y el cliente recibía
                // la respuesta repetida o minutos después.
                ProcessWhatsAppMessage::dispatchAfterResponse($id, $stored['notifyTeam'], $respondAt);
            }
        };

        if ($queued) {
            DB::transaction($receive);
        } else {
            $receive();
        }
    }

    protected function connection(array $data): void
    {
        $state = $data['state'] ?? ($data['instance']['state'] ?? null);

        if (is_string($state) && $state !== '') {
            Cache::put('evolution.connection_state', $state, now()->addDay());
        }
    }
}
