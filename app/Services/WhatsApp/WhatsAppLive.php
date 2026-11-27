<?php

namespace App\Services\WhatsApp;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Pusher\Pusher;
use Throwable;

final class WhatsAppLive
{
    public static function channel(int $conversationId): string
    {
        return 'private-whatsapp.chat.'.$conversationId;
    }

    public function changed(int $conversationId): void
    {
        if (! config('reverb.chat_enabled')) {
            return;
        }

        DB::afterCommit(function () use ($conversationId): void {
            try {
                // Solo se avisa del cambio. El contenido se lee por la ruta autenticada del chat.
                $this->client()->trigger(self::channel($conversationId), 'chat.updated', []);
            } catch (Throwable $e) {
                Log::warning('Canal en vivo de WhatsApp no disponible', ['error' => class_basename($e)]);
            }
        });
    }

    public function authorize(int $conversationId, string $socket): array
    {
        return json_decode($this->client()->authorizeChannel(self::channel($conversationId), $socket), true, flags: JSON_THROW_ON_ERROR);
    }

    private function client(): Pusher
    {
        $app = config('reverb.apps.apps.0');

        return new Pusher($app['key'], $app['secret'], $app['app_id'], $app['options'], new Client([
            'connect_timeout' => 0.2,
            'timeout' => 0.5,
        ]));
    }
}
