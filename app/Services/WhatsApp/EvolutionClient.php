<?php

namespace App\Services\WhatsApp;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
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

    /** Desvincula el número (hay que escanear el QR de nuevo). */
    public function logout(): bool
    {
        return $this->request('DELETE', '/instance/logout/'.$this->instance()) !== null;
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
                'headers' => [],
            ],
        ]) !== null;
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
        if (! $this->isConfigured() || $number === '' || trim($text) === '') {
            return false;
        }

        $response = $this->request('POST', '/message/sendText/'.$this->instance(), [], [
            'number' => $number,
            'text' => $text,
        ]);

        if ($response === null) {
            return false;
        }

        Log::info('WhatsApp (Evolution) enviado', ['to' => $number]);

        return true;
    }

    // ------------------------------------------------------------------------------------------
    // Bajo nivel
    // ------------------------------------------------------------------------------------------

    /**
     * Hace una petición a la API. Devuelve el JSON como array o null si algo falló.
     * Cualquier error queda en el log: nunca interrumpe la reserva ni la fila de turnos.
     */
    public function request(string $method, string $path, array $query = [], array $body = []): ?array
    {
        if (! $this->isConfigured()) {
            Log::info('Evolution API no configurada', ['path' => $path]);

            return null;
        }

        $url = $this->baseUrl().$path;
        $timeout = (int) config('services.evolution.timeout', 20);

        try {
            /** @var Response $response */
            $response = $this->withAuth($timeout)->$method($url, $method === 'GET' ? $query : $body);
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
