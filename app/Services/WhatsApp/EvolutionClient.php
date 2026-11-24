<?php

namespace App\Services\WhatsApp;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Cliente HTTP de Evolution API (WhatsApp propio, corriendo en Northflank).
 *
 * Todas las peticiones llevan la cabecera "apikey" y el nombre de la instancia
 * en la ruta. Docs: https://doc.evolution-api.com · proyecto: docs/WHATSAPP.md
 */
class EvolutionClient
{
    /** Eventos que este sistema necesita recibir por webhook. */
    public const WEBHOOK_EVENTS = [
        'MESSAGES_UPSERT',
        'MESSAGES_UPDATE',
        'CONNECTION_UPDATE',
        'QRCODE_UPDATED',
        'LOGOUT_INSTANCE',
        'REMOVE_INSTANCE',
    ];

    public function isConfigured(): bool
    {
        return filled(config('services.evolution.url')) && filled(config('services.evolution.apikey'));
    }

    public function instance(): string
    {
        return (string) (config('services.evolution.instance') ?: 'barberia');
    }

    public function baseUrl(): string
    {
        return rtrim((string) config('services.evolution.url'), '/');
    }

    // ------------------------------------------------------------------------------------------
    // Instancia: crear, estado, QR y logout
    // ------------------------------------------------------------------------------------------

    /** Crea la instancia si aún no existe. Devuelve true si al final existe. */
    public function ensureInstance(): bool
    {
        if (! $this->isConfigured()) {
            return false;
        }

        $existing = $this->request('GET', '/instance/fetchInstances');
        $name = $this->instance();

        $found = is_array($existing) && collect($existing)->contains(function ($item) use ($name) {
            $itemName = is_array($item) ? ($item['name'] ?? ($item['instanceName'] ?? null)) : null;

            return $itemName === $name;
        });

        if ($found) {
            return true;
        }

        // Evolution v2 exige "integration"; sin ella responde 400 "Invalid integration".
        $created = $this->request('POST', '/instance/create', [], [
            'instanceName' => $name,
            'integration' => 'WHATSAPP-BAILEYS',
            'qrcode' => true,
        ]);

        if ($created !== null) {
            return true;
        }

        // 400/409 suele significar "ya existe": no es un error de verdad.
        return $this->state() !== null;
    }

    /** Estado de la instancia: 'open' | 'connecting' | 'close' | null si no se pudo consultar. */
    public function state(): ?string
    {
        $response = $this->request('GET', '/instance/connectionState/'.$this->instance());

        if (! is_array($response)) {
            return null;
        }

        return $response['instance']['state'] ?? null;
    }

    /**
     * Pide el QR de conexión (y arranca la conexión si estaba cerrada).
     *
     * Devuelve ['state' => string, 'qr' => ?string, 'pairing_code' => ?string, 'count' => ?int].
     */
    public function connect(): array
    {
        $default = ['state' => $this->state() ?? 'unknown', 'qr' => null, 'pairing_code' => null, 'count' => null];

        $response = $this->request('GET', '/instance/connect/'.$this->instance());

        if (! is_array($response)) {
            return $default;
        }

        // Instancia ya conectada: Evolution resuelve con el estado.
        if (isset($response['instance']['state'])) {
            return array_merge($default, ['state' => (string) $response['instance']['state']]);
        }

        if (! empty($response['error'])) {
            Log::warning('Evolution API: no se pudo conectar la instancia', ['message' => $response['message'] ?? null]);

            return $default;
        }

        return [
            'state' => $default['state'],
            'qr' => $response['base64'] ?? null,
            'pairing_code' => $response['pairingCode'] ?? null,
            'count' => $response['count'] ?? null,
        ];
    }

    /**
     * Desvincula el número (hay que escanear el QR de nuevo).
     *
     * Evolution devuelve 400 "instance is not connected" si la instancia está cerrada:
     * en ese caso el número ya quedó fuera, así que se da por desvinculado en vez de
     * fallar (antes eso se traducía en un 500 en el panel).
     */
    public function logout(): bool
    {
        if ($this->request('DELETE', '/instance/logout/'.$this->instance()) !== null) {
            return true;
        }

        return in_array($this->state(), ['close', null], true);
    }

    /**
     * Desvincula el número de verdad y deja la instancia lista para un QR nuevo.
     *
     * El logout de Evolution falla si la instancia está a medio vincular ("connecting") y, si la
     * conexión con WhatsApp ya estaba rota, puede no borrar las credenciales viejas: el QR nuevo
     * no funciona. Por eso aquí se borra la instancia completa, se espera a que Evolution termine
     * de limpiarla, se crea de nuevo y se vuelve a registrar el webhook.
     * La bandeja del panel (whatsapp_conversations / whatsapp_messages) no se toca.
     */
    public function resetInstance(?string $webhookUrl = null): bool
    {
        if (! $this->isConfigured() || ($this->state() === null && ! $this->reachable())) {
            return false;
        }

        $name = $this->instance();

        if (in_array($this->state(), ['open', 'connecting'], true)) {
            $this->request('DELETE', '/instance/logout/'.$name); // si falla, el borrado lo resuelve
        }

        $this->request('DELETE', '/instance/delete/'.$name);

        if (! $this->waitUntilGone()) {
            // El borrado falla cuando la sesión vieja no deja cerrar: se reinicia y se intenta otra vez.
            $this->request('POST', '/instance/restart/'.$name);
            $this->request('DELETE', '/instance/logout/'.$name);
            $this->request('DELETE', '/instance/delete/'.$name);

            if (! $this->waitUntilGone()) {
                Log::warning('Evolution API: no se pudo borrar la instancia para desvincular', ['instance' => $name]);

                return false;
            }
        }

        if (! $this->ensureInstance()) {
            return false;
        }

        if (filled($webhookUrl)) {
            $this->setWebhook((string) $webhookUrl, enabled: true);
        }

        return true;
    }

    /** ¿Responde Evolution API? */
    protected function reachable(): bool
    {
        return $this->request('GET', '/instance/fetchInstances') !== null;
    }

    /**
     * Evolution borra en segundo plano: espera (máx. ~15 s) a que la instancia desaparezca.
     * Primero mira el estado (rápido, en memoria) y al final confirma en la base de datos.
     */
    protected function waitUntilGone(int $attempts = 30): bool
    {
        for ($i = 0; $i < $attempts; $i++) {
            if ($this->state() === null) {
                break;
            }
            usleep(500_000);
        }

        for ($i = 0; $i < 10; $i++) {
            $list = $this->request('GET', '/instance/fetchInstances');

            if (is_array($list) && ! collect($list)->contains(fn ($item) => is_array($item)
                && (($item['name'] ?? ($item['instanceName'] ?? null)) === $this->instance()))) {
                return true;
            }

            usleep(500_000);
        }

        return false;
    }

    /**
     * Registra/actualiza el webhook de la instancia para que Evolution avise a tu sitio.
     * $url debe ser absoluta (https://tudominio.com/webhook/evolution).
     */
    public function setWebhook(string $url, bool $enabled = true): bool
    {
        return $this->request('POST', '/webhook/set/'.$this->instance(), [], [
            'webhook' => [
                'enabled' => $enabled,
                'url' => $url,
                'events' => self::WEBHOOK_EVENTS,
                'byEvents' => false,
                'base64' => false,
                'headers' => filled(config('services.evolution.apikey'))
                    ? ['apikey' => (string) config('services.evolution.apikey')]
                    : [],
            ],
        ]) !== null;
    }

    /**
     * Deja el webhook como debe estar (URL, activo, eventos y cabecera apikey).
     * Solo escribe si falta o difiere, así se puede llamar en cada conexión sin costo.
     */
    public function ensureWebhook(string $url): bool
    {
        $current = $this->findWebhook();
        $apikey = (string) config('services.evolution.apikey');

        $ok = is_array($current)
            && ($current['url'] ?? null) === $url
            && (bool) ($current['enabled'] ?? false)
            && ($apikey === '' || (($current['headers']['apikey'] ?? null) === $apikey))
            && array_diff(self::WEBHOOK_EVENTS, (array) ($current['events'] ?? [])) === [];

        return $ok || $this->setWebhook($url, enabled: true);
    }

    /** Configuración actual del webhook (para el panel). */
    public function findWebhook(): ?array
    {
        $response = $this->request('GET', '/webhook/find/'.$this->instance());

        return is_array($response) ? $response : null;
    }

    // ------------------------------------------------------------------------------------------
    // Mensajería
    // ------------------------------------------------------------------------------------------

    /** Envía un mensaje de texto. $number sin "+" ni espacios, ej: 573001234567. */
    public function sendText(string $number, string $text): bool
    {
        return $this->sendTextReceipt($number, $text) !== null;
    }

    public function sendTextReceipt(string $number, string $text): ?array
    {
        if (! $this->isConfigured() || $number === '' || trim($text) === '') {
            return null;
        }

        $response = $this->request('POST', '/message/sendText/'.$this->instance(), [], [
            'number' => $number,
            'text' => $text,
        ], chatSend: true);

        if ($response === null) {
            return null;
        }

        // Si WhatsApp atiende este número con su identificador nuevo (@lid), se recuerda la pareja: así se
        // reconoce la respuesta del cliente aunque llegue solo con el @lid (App\Support\WhatsAppLid).
        $sentKey = is_array($response['key'] ?? null) ? $response['key'] : [];
        if (is_string($sentKey['id'] ?? null) && $sentKey['id'] !== '') {
            Cache::put($this->sentKey($sentKey['id']), true, now()->addHour());
        }
        foreach ([$sentKey['remoteJid'] ?? null, $sentKey['remoteJidAlt'] ?? null, $sentKey['senderLid'] ?? null] as $jid) {
            \App\Support\WhatsAppLid::remember(is_string($jid) ? $jid : null, $number);
        }

        Log::info('WhatsApp (Evolution) enviado', ['to' => \App\Support\Pii::phone($number)]);

        return $response;
    }

    public function wasSentByApi(string $id): bool
    {
        return Cache::has($this->sentKey($id));
    }

    private function sentKey(string $id): string
    {
        return 'whatsapp:sent:'.hash('sha256', $id);
    }

    // ------------------------------------------------------------------------------------------
    // Bajo nivel
    // ------------------------------------------------------------------------------------------

    /**
     * Hace una petición a la API. Devuelve el JSON como array o null si algo falló.
     * Cualquier error queda en el log: nunca interrumpe la reserva ni la fila de turnos.
     */
    public function request(string $method, string $path, array $query = [], array $body = [], bool $chatSend = false): ?array
    {
        if (! $this->isConfigured()) {
            Log::info('Evolution API no configurada', ['path' => $path]);

            return null;
        }

        $url = $this->baseUrl().$path;
        $timeout = max(1, (int) config('services.evolution.'.($chatSend ? 'chat_timeout' : 'timeout'), $chatSend ? 8 : 20));

        try {
            $http = $this->withAuth($timeout);
            if ($chatSend) {
                // Solo reintenta si el proveedor rechazó explícitamente por límite, sin reintentar un
                // timeout ambiguo que podría corresponder a un mensaje que sí llegó.
                $http->connectTimeout(min(3, $timeout))->retry(2, 1000, function ($error) {
                    return $error instanceof RequestException && $error->response->status() === 429
                        && in_array(trim($error->response->header('Retry-After')), ['', '0', '1'], true);
                }, throw: false);
            }
            /** @var Response $response */
            $response = $http->$method($url, $method === 'GET' ? $query : $body);
        } catch (\Throwable $e) {
            Log::error('Evolution API sin respuesta', ['path' => $path, 'error' => $e->getMessage()]);

            return null;
        }

        if (! $response->successful()) {
            // 400/409 en create/fetch suele ser "la instancia ya existe": lo trata el llamador.
            Log::warning('Evolution API respondió con error', [
                'path' => $path,
                'status' => $response->status(),
                'body' => \Illuminate\Support\Str::limit($response->body(), 600),
            ]);

            return null;
        }

        $json = $response->json();

        return is_array($json) ? $json : [];
    }

    protected function withAuth(int $timeout): PendingRequest
    {
        return Http::withHeaders(['apikey' => (string) config('services.evolution.apikey')])
            ->timeout($timeout)
            ->acceptJson();
    }
}
