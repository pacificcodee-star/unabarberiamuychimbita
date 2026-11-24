<?php

namespace App\Jobs;

use App\Models\WhatsAppMessage;
use App\Services\WhatsApp\WhatsAppInbox;
use App\Services\WhatsApp\WhatsappBotService;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ProcessWhatsAppMessage implements ShouldQueue
{
    use Queueable;

    // El flujo puede confirmar o crear reservas. No se ejecuta dos veces por un fallo al enviar.
    public int $tries = 1;

    public function __construct(private int $messageId, private bool $notifyTeam) {}

    public function handle(WhatsappBotService $bot, WhatsAppInbox $inbox): void
    {
        $message = WhatsAppMessage::with('conversation')->find($this->messageId);
        if (! $message?->conversation) {
            return;
        }

        // Un cliente escribe "hola" y enseguida "1": si ambas peticiones se atienden a la vez, las
        // dos leen el mismo paso del menú y el bot contesta dos veces (o pisa el flujo). Se atiende
        // de a un mensaje por conversación; conversaciones distintas siguen en paralelo.
        $lock = Cache::lock('whatsapp:conv:'.$message->conversation_id, 30);
        $locked = true;

        try {
            $lock->block(5);
        } catch (LockTimeoutException) {
            // La conversación lleva más de 5 s ocupada: se sigue igual para no dejar al cliente sin
            // respuesta, aunque es raro (un turno normal tarda menos de un segundo).
            $locked = false;
            Log::warning('WhatsApp bot: conversación ocupada, se procesa igual', [
                'conversation' => $message->conversation_id,
            ]);
        }

        $started = microtime(true);

        try {
            $bot->handle($message->conversation, $message);
        } finally {
            if ($this->notifyTeam) {
                $inbox->notifyIncoming($message);
            }

            if ($locked) {
                $lock->release();
            }

            Log::info('WhatsApp bot: mensaje procesado', [
                'conversation' => $message->conversation_id,
                'ms' => (int) ((microtime(true) - $started) * 1000),
            ]);
        }
    }
}
