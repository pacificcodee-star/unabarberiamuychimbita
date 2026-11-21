<?php

namespace App\Services;

use App\Services\WhatsApp\EvolutionClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Salida de WhatsApp hacia el resto del sistema.
 *
 * Hay dos drivers y solo uno activo a la vez (WHATSAPP_DRIVER):
 *  - evolution: Evolution API propia (Northflank). Número normal con QR, sin
 *    plantillas aprobadas, sin cuota de Meta. Es el que usa esta barbería.
 *  - cloud: WhatsApp Business Cloud API oficial de Meta (requiere plantillas).
 *
 * ClientNotifier, el POS y los comandos llaman a esta clase sin saber el driver.
 */
class WhatsAppService
{
    public function __construct(private readonly EvolutionClient $evolution)
    {
    }

    protected function driver(): string
    {
        return config('services.whatsapp.driver') ?: 'evolution';
    }

    /** Solo la Cloud API de Meta trabaja con plantillas; Evolution envía texto libre. */
    public function usesTemplates(): bool
    {
        return $this->driver() === 'cloud';
    }

    /**
     * Envía un mensaje de texto si hay un canal configurado.
     * Variables: EVOLUTION_URL + EVOLUTION_APIKEY (driver evolution),
     * WHATSAPP_CLOUD_TOKEN + WHATSAPP_PHONE_NUMBER_ID (driver cloud),
     * WHATSAPP_DEFAULT_COUNTRY (opcional, ej 57).
     */
    public function sendText(string $to, string $message): bool
    {
        $to = $this->normalizePhone($to);
        if ($to === '') {
            Log::warning('WhatsApp no enviado: número vacío.');
            return false;
        }

        if ($this->driver() === 'evolution') {
            if (! $this->evolution->isConfigured()) {
                Log::info('WhatsApp (simulado, Evolution API sin configurar)', ['to' => \App\Support\Pii::phone($to), 'caracteres' => mb_strlen((string) $message)]);
                return false;
            }

            return $this->evolution->sendText($to, $message);
        }

        $token = config('services.whatsapp.token') ?: env('WHATSAPP_CLOUD_TOKEN');
        $phoneNumberId = config('services.whatsapp.phone_number_id') ?: env('WHATSAPP_PHONE_NUMBER_ID');

        if (! $token || ! $phoneNumberId) {
            Log::info('WhatsApp (simulado, sin API configurada)', ['to' => \App\Support\Pii::phone($to), 'caracteres' => mb_strlen((string) $message)]);
            return false;
        }

        $url = "https://graph.facebook.com/v20.0/{$phoneNumberId}/messages";

        $response = Http::withToken($token)->post($url, [
            'messaging_product' => 'whatsapp',
            'to' => $to,
            'type' => 'text',
            'text' => ['body' => $message],
        ]);

        if (! $response->successful()) {
            Log::error('WhatsApp API error', ['to' => \App\Support\Pii::phone($to), 'status' => $response->status(), 'body' => \Illuminate\Support\Str::limit($response->body(), 300)]);
            return false;
        }

        Log::info('WhatsApp enviado', ['to' => \App\Support\Pii::phone($to)]);
        return true;
    }

    public function isConfigured(): bool
    {
        if ($this->driver() === 'evolution') {
            return $this->evolution->isConfigured();
        }

        return filled(config('services.whatsapp.token') ?: env('WHATSAPP_CLOUD_TOKEN'))
            && filled(config('services.whatsapp.phone_number_id') ?: env('WHATSAPP_PHONE_NUMBER_ID'));
    }

    /**
     * Envía una plantilla aprobada de WhatsApp Business (obligatoria en la Cloud API
     * para avisos que inicia el negocio). Con Evolution no existen plantillas: se
     * envía el último parámetro (el cuerpo ya redactado) como texto normal.
     * $parameters son los textos que reemplazan {{1}}, {{2}}... en el cuerpo de la plantilla.
     */
    public function sendTemplate(string $to, string $template, array $parameters = [], ?string $language = null): bool
    {
        $to = $this->normalizePhone($to);

        if ($to === '') {
            return false;
        }

        if (! $this->usesTemplates()) {
            $body = $parameters !== [] ? (string) end($parameters) : '';

            return $body !== '' ? $this->sendText($to, $body) : false;
        }

        if (! $this->isConfigured()) {
            Log::info('WhatsApp plantilla (simulada, sin API configurada)', ['to' => \App\Support\Pii::phone($to), 'template' => $template]);
            return false;
        }

        $token = config('services.whatsapp.token') ?: env('WHATSAPP_CLOUD_TOKEN');
        $phoneNumberId = config('services.whatsapp.phone_number_id') ?: env('WHATSAPP_PHONE_NUMBER_ID');

        $payload = [
            'messaging_product' => 'whatsapp',
            'to' => $to,
            'type' => 'template',
            'template' => [
                'name' => $template,
                'language' => ['code' => $language ?: config('services.whatsapp.template_language', 'es_CO')],
            ],
        ];
        if ($parameters !== []) {
            $payload['template']['components'] = [[
                'type' => 'body',
                'parameters' => array_map(fn ($text) => ['type' => 'text', 'text' => (string) $text], array_values($parameters)),
            ]];
        }

        $response = Http::withToken($token)->timeout(10)->post("https://graph.facebook.com/v20.0/{$phoneNumberId}/messages", $payload);

        if (! $response->successful()) {
            Log::error('WhatsApp API error (plantilla)', ['to' => \App\Support\Pii::phone($to), 'template' => $template, 'status' => $response->status(), 'body' => \Illuminate\Support\Str::limit($response->body(), 300)]);
            return false;
        }

        Log::info('WhatsApp plantilla enviada', ['to' => \App\Support\Pii::phone($to), 'template' => $template]);
        return true;
    }

    /** 573001234567: dígitos puros con indicativo, formato que entienden ambos drivers. */
    public function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone);
        if (! $digits) {
            return '';
        }

        $country = (string) (config('services.whatsapp.default_country') ?: env('WHATSAPP_DEFAULT_COUNTRY', '57'));
        $country = preg_replace('/\D+/', '', $country) ?: '57';

        // Si es un número local de 10 dígitos, anteponer país.
        if (strlen($digits) === 10) {
            $digits = $country.$digits;
        }

        return $digits;
    }
}
