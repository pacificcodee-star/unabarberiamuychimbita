<?php

namespace App\Jobs;

use App\Models\WhatsAppMessage;
use App\Services\WhatsApp\WhatsAppInbox;
use App\Services\WhatsApp\WhatsappBotService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

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

        try {
            $bot->handle($message->conversation, $message);
        } finally {
            if ($this->notifyTeam) {
                $inbox->notifyIncoming($message);
            }
        }
    }
}
