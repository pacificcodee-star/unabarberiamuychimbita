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

    /**
     * Certificados para hablar por HTTPS con los servicios push (Google, Microsoft, Mozilla, Apple).
     * El PHP de XAMPP en Windows no trae ninguno configurado y todos los envíos fallaban con
     * "cURL error 60": si PHP no tiene curl.cainfo, se usa WEBPUSH_CA_BUNDLE o el que trae XAMPP.
     */
    private function caBundle(): string|bool
    {
        $configured = (string) config('services.webpush.ca_bundle', '');
        if ($configured !== '' && is_file($configured)) {
            return $configured;
        }

        if (filled(ini_get('curl.cainfo')) || filled(ini_get('openssl.cafile'))) {
            return true;
        }

        foreach (['C:/xampp/apache/bin/curl-ca-bundle.crt', '/etc/ssl/certs/ca-certificates.crt', '/etc/pki/tls/certs/ca-bundle.crt'] as $path) {
            if (is_file($path)) {
                return $path;
            }
        }

        return true;
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
            ], [], new \GuzzleHttp\Client(['timeout' => 10, 'connect_timeout' => 5, 'verify' => $this->caBundle()]));
            // web-push v11 recibe un cliente HTTP (PSR-18), ya no un número de segundos:
            // con el entero de antes cada envío fallaba y ningún aviso push salía.
            $this->client->setReuseVAPIDHeaders(true);
        }

        return $this->client;
    }
}
