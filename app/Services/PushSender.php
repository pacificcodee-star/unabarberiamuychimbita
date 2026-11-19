<?php

namespace App\Services;

use App\Models\PushSubscription;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Envía notificaciones push reales (Web Push estándar con claves VAPID) usando minishlink/web-push.
 * El mensaje viaja cifrado hasta el navegador del cliente y llega aunque la página esté cerrada.
 */
class PushSender
{
    private $client = null;

    public function isConfigured(): bool
    {
        return class_exists(\Minishlink\WebPush\WebPush::class)
            && filled(config('services.webpush.public_key'))
            && filled(config('services.webpush.private_key'));
    }

    /**
     * @param  array{title:string,body:string,url?:string,tag?:string}  $payload
     * @return array{ok:bool,gone:bool,error:?string}  gone = la suscripción ya no existe y debe borrarse
     */
    public function send(PushSubscription $subscription, array $payload, int $ttlSeconds = 600): array
    {
        if (! $this->isConfigured()) {
            return ['ok' => false, 'gone' => false, 'error' => 'Web Push sin configurar (faltan las claves VAPID o el paquete minishlink/web-push).'];
        }

        try {
            $report = $this->client()->sendOneNotification(
                \Minishlink\WebPush\Subscription::create([
                    'endpoint' => $subscription->endpoint,
                    'keys' => ['p256dh' => $subscription->p256dh, 'auth' => $subscription->auth],
                    'contentEncoding' => $subscription->content_encoding ?: 'aes128gcm',
                ]),
                json_encode($payload, JSON_UNESCAPED_UNICODE),
                [
                    'TTL' => max(60, $ttlSeconds),
                    'urgency' => 'high',
                    // Mismo "topic" = el servicio push reemplaza un aviso idéntico que aún no se entregó.
                    'topic' => substr(hash('sha256', (string) ($payload['tag'] ?? $payload['title'])), 0, 32),
                ]
            );

            if ($report->isSuccess()) {
                return ['ok' => true, 'gone' => false, 'error' => null];
            }

            $status = $report->getResponse()?->getStatusCode();

            return [
                'ok' => false,
                'gone' => $report->isSubscriptionExpired() || in_array($status, [404, 410], true),
                'error' => trim(($status ? "HTTP {$status}: " : '').$report->getReason()),
            ];
        } catch (Throwable $e) {
            Log::error('Web Push: error al enviar', ['subscription' => $subscription->id, 'error' => $e->getMessage()]);

            return ['ok' => false, 'gone' => false, 'error' => $e->getMessage()];
        }
    }

    private function client()
    {
        if ($this->client === null) {
            $this->client = new \Minishlink\WebPush\WebPush([
                'VAPID' => [
                    'subject' => (string) config('services.webpush.subject'),
                    'publicKey' => (string) config('services.webpush.public_key'),
                    'privateKey' => (string) config('services.webpush.private_key'),
                ],
            ], [], 10);
            $this->client->setReuseVAPIDHeaders(true);
        }

        return $this->client;
    }
}
