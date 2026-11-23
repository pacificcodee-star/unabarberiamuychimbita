<?php

namespace App\Services\WhatsApp;

use App\Models\StaffNotification;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use App\Services\WhatsAppService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Bandeja de WhatsApp del panel: convierte los eventos de Evolution API en
 * conversaciones/mensajes y envía respuestas (bot y equipo) guardándolas también.
 */
class WhatsAppInbox
{
    public function __construct(
        private readonly EvolutionClient $evolution,
        private readonly WhatsAppService $whatsApp,
    ) {
    }

    // ------------------------------------------------------------------------------------------
    // Lectura del payload de Evolution
    // ------------------------------------------------------------------------------------------

    /** '573001234567@s.whatsapp.net' → '573001234567'; null para grupos/estados/sin teléfono. */
    public static function phoneFromJid(?string $jid): ?string
    {
        if (! $jid || ! str_contains($jid, '@')) {
            return null;
        }

        [$user, $server] = explode('@', $jid, 2);

        if ($server !== 's.whatsapp.net') {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $user);

        return $digits ?: null;
    }

    /** Extrae el texto de un mensaje de WhatsApp (acepta los envoltorios de Baileys). */
    public function textFrom(array $data, int $depth = 0): ?string
    {
        if ($depth > 4) {
            return null;
        }

        $message = $data['message'] ?? null;
        if (! is_array($message)) {
            $message = $data;
        }

        $candidates = [
            'conversation' => null,
            'extendedTextMessage' => 'text',
            'buttonsResponseMessage' => 'selectedDisplayText',
            'listResponseMessage' => 'title',
            'templateButtonReplyMessage' => 'selectedDisplayText',
            'imageMessage' => 'caption',
            'videoMessage' => 'caption',
            'documentMessage' => 'caption',
        ];

        foreach ($candidates as $key => $field) {
            $value = $message[$key] ?? null;
            $value = ($field !== null && is_array($value)) ? ($value[$field] ?? null) : $value;

            if (is_string($value) && trim($value) !== '' && $value !== 'requestPlaceholder' && $value !== 'onDemandHistSync') {
                return trim($value);
            }
        }

        // Envoltorios: ephemeralMessage / viewOnceMessage / editedMessage...
        foreach (['ephemeralMessage', 'viewOnceMessage', 'viewOnceMessageV2', 'editedMessage', 'documentWithCaptionMessage'] as $wrapper) {
            if (is_array($message[$wrapper] ?? null)) {
                $nested = $this->textFrom($message[$wrapper], $depth + 1);
                if ($nested !== null) {
                    return $nested;
                }
            }
        }

        return null;
    }

    /** Tipo legible para guardar: text, image, audio, video, sticker, document... */
    public function typeFrom(array $data, ?string $text): string
    {
        if ($text !== null) {
            return 'text';
        }

        $raw = (string) ($data['messageType'] ?? '');

        $map = [
            'imageMessage' => 'image',
            'audioMessage' => 'audio',
            'videoMessage' => 'video',
            'stickerMessage' => 'sticker',
            'documentMessage' => 'document',
            'locationMessage' => 'location',
            'contactMessage' => 'contact',
        ];

        foreach ($map as $needle => $type) {
            if (str_contains($raw, $needle)) {
                return $type;
            }
        }

        return $raw !== '' ? Str::limit($raw, 20, '') : 'media';
    }

    // ------------------------------------------------------------------------------------------
    // Guardado
    // ------------------------------------------------------------------------------------------

    /**
     * Guarda un mensaje entrante (evento messages.upsert).
     *
     * @return array{conversation: WhatsAppConversation, message: WhatsAppMessage}|null
     *         null cuando el mensaje no es de un cliente (saliente, grupo) o ya existía.
     */
    public function recordIncoming(array $data): ?array
    {
        $key = is_array($data['key'] ?? null) ? $data['key'] : [];

        if (! empty($key['fromMe'])) {
            return null; // lo nuestro: no revierte al bot (evita bucles)
        }

        // Grupos, estados y canales no son clientes.
        $remote = (string) ($key['remoteJid'] ?? '');
        if (preg_match('/@(g\.us|broadcast|newsletter)$/', $remote) === 1) {
            return null;
        }

        // Con las direcciones nuevas de WhatsApp (…@lid) el número puede venir en otro campo: se usa el
        // primero que traiga un teléfono. Antes, si remoteJidAlt traía el @lid, el mensaje se perdía.
        $phone = $jid = null;
        foreach ([$key['remoteJidAlt'] ?? null, $remote, $key['senderPn'] ?? null, $data['senderPn'] ?? null, $key['participantAlt'] ?? null] as $candidate) {
            if (! is_string($candidate) || $candidate === '') {
                continue;
            }
            $jid = str_contains($candidate, '@') ? $candidate : $candidate.'@s.whatsapp.net';
            if (($phone = self::phoneFromJid($jid)) !== null) {
                break;
            }
        }

        if ($phone !== null) {
            // Llegaron juntos el @lid y el número: se recuerda para cuando llegue solo el @lid.
            \App\Support\WhatsAppLid::remember($remote, $phone);
        } elseif (($known = \App\Support\WhatsAppLid::phoneFor($remote)) !== null) {
            // Solo vino el @lid, pero ya sabemos de qué número es (lo vimos al enviarle un mensaje).
            $phone = $known;
            $jid = $known.'@s.whatsapp.net';
        }

        if ($phone === null) {
            // Antes se descartaba sin dejar rastro: ahora queda en el registro para poder revisarlo.
            Log::warning('WhatsApp: mensaje entrante sin número de teléfono (solo @lid); no se pudo atender', [
                'remoteJid' => $remote,
                'campos' => array_keys($key),
            ]);

            return null;
        }

        $phone = $this->whatsApp->normalizePhone($phone);
        if ($phone === '') {
            return null;
        }

        $evolutionId = isset($key['id']) ? Str::limit((string) $key['id'], 100, '') : null;

        // Reintento del webhook: ya lo procesamos antes.
        if ($evolutionId && WhatsAppMessage::query()->where('evolution_id', $evolutionId)->exists()) {
            return null;
        }

        $text = $this->textFrom($data);
        $type = $this->typeFrom($data, $text);
        $preview = $text !== null ? $text : '['.$type.']';

        $conversation = WhatsAppConversation::query()->firstOrNew(['phone' => $phone]);
        $isNewBurst = ! $conversation->exists || (int) $conversation->unread_count === 0;

        if (! $conversation->exists) {
            $conversation->jid = $jid;
        }

        $conversation->fill([
            'jid' => $jid ?: $conversation->jid,
            'client_name' => filled($data['pushName'] ?? null) ? Str::limit((string) $data['pushName'], 120, '') : $conversation->client_name,
            'unread_count' => $conversation->unread_count + 1,
            'last_message_preview' => Str::limit($preview, 250, ''),
            'last_message_at' => now(),
        ])->save();

        $message = WhatsAppMessage::query()->create([
            'conversation_id' => $conversation->id,
            'direction' => WhatsAppMessage::DIRECTION_IN,
            'type' => $type,
            'body' => $text !== null ? Str::limit($text, 5000, '…') : null,
            'evolution_id' => $evolutionId,
            'status' => null,
            'sender_name' => filled($data['pushName'] ?? null) ? Str::limit((string) $data['pushName'], 120, '') : null,
            'raw' => $this->safeRaw($data),
        ]);

        if ($isNewBurst) {
            StaffNotification::record(
                'whatsapp_message',
                'WhatsApp: '.($conversation->client_name ?: $conversation->phone),
                Str::limit($preview, 200, ''),
                route('whatsapp.chat.show', $conversation),
            );
        }

        return ['conversation' => $conversation->fresh(), 'message' => $message];
    }

    /** Guarda un mensaje saliente (bot o equipo) ya enviado. */
    public function recordOutgoing(WhatsAppConversation $conversation, string $body, string $status = 'sent'): WhatsAppMessage
    {
        $message = $conversation->messages()->create([
            'direction' => WhatsAppMessage::DIRECTION_OUT,
            'type' => 'text',
            'body' => Str::limit($body, 5000, '…'),
            'status' => $status,
        ]);

        $conversation->fill([
            'last_message_preview' => Str::limit($body, 250, ''),
            'last_message_at' => now(),
        ])->save();

        return $message;
    }

    /** Actualiza el estado de un mensaje saliente (evento messages.update). */
    public function applyStatusUpdate(array $data): void
    {
        $key = is_array($data['key'] ?? null) ? $data['key'] : [];
        $evolutionId = isset($key['id']) ? Str::limit((string) $key['id'], 100, '') : null;

        if (! $evolutionId || empty($key['fromMe'])) {
            return;
        }

        // Estado de un mensaje nuestro con el @lid del cliente: se aprende de qué número es.
        if (\App\Support\WhatsAppLid::isLid($key['remoteJid'] ?? null)) {
            $phone = self::phoneFromJid((string) ($key['remoteJidAlt'] ?? ''))
                ?? WhatsAppMessage::query()->where('evolution_id', $evolutionId)->first()?->conversation?->phone;
            \App\Support\WhatsAppLid::remember($key['remoteJid'], $phone);
        }

        $status = $data['update']['status'] ?? null;
        $map = [
            0 => 'failed', 1 => 'sent', 2 => 'sent', 3 => 'delivered', 4 => 'read', 5 => 'read',
            'ERROR' => 'failed', 'PENDING' => 'sent', 'SERVER_ACK' => 'sent',
            'DELIVERY_ACK' => 'delivered', 'READ' => 'read', 'PLAYED' => 'read',
            'delivered' => 'delivered', 'read' => 'read', 'failed' => 'failed',
        ];

        $normalized = is_string($status) ? strtoupper($status) : $status;
        $mapped = $map[$status] ?? $map[$normalized] ?? null;

        if ($mapped === null) {
            return;
        }

        WhatsAppMessage::query()
            ->where('evolution_id', $evolutionId)
            ->where('direction', WhatsAppMessage::DIRECTION_OUT)
            ->update(['status' => $mapped]);
    }

    // ------------------------------------------------------------------------------------------
    // Envío
    // ------------------------------------------------------------------------------------------

    /** Envía una respuesta y la guarda en la conversación. Devuelve true si Evolution la aceptó. */
    public function send(WhatsAppConversation $conversation, string $body): bool
    {
        $ok = $this->whatsApp->sendText($conversation->phone, $body);

        $this->recordOutgoing($conversation, $body, $ok ? 'sent' : 'failed');

        return $ok;
    }

    /** Escribe a un número aunque todavía no exista conversación (avisos de agenda, sin que el cliente escriba primero). */
    public function sendToPhone(string $phone, string $body, ?string $name = null): bool
    {
        $phone = $this->whatsApp->normalizePhone($phone);

        if ($phone === '') {
            return false;
        }

        $conversation = WhatsAppConversation::query()->firstOrCreate(
            ['phone' => $phone],
            ['jid' => $phone.'@s.whatsapp.net', 'bot_active' => true],
        );

        if (blank($conversation->client_name) && filled($name)) {
            $conversation->fill(['client_name' => Str::limit((string) $name, 120, '')])->save();
        }

        $ok = $this->whatsApp->sendText($phone, $body);

        $this->recordOutgoing($conversation, $body, $ok ? 'sent' : 'failed');

        return $ok;
    }

    public function evolution(): EvolutionClient
    {
        return $this->evolution;
    }

    /** El payload puede traer medios enormes: solo conservamos lo útil para depurar. */
    private function safeRaw(array $data): array
    {
        unset($data['message']['base64']);

        return $data;
    }
}
