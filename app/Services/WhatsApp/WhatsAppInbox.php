<?php

namespace App\Services\WhatsApp;

use App\Models\StaffNotification;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use App\Services\WhatsAppService;
use App\Support\WhatsAppLid;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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

        // Valida el JID completo para que un nombre de usuario con dígitos no se lea como teléfono.
        return preg_match('/\A\+?([0-9]{8,20})(?::[0-9]+)?\z/', $user, $parts) === 1 ? $parts[1] : null;
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
     * @return array{message: WhatsAppMessage, notifyTeam: bool}|null
     *         null cuando el mensaje no es de un cliente (saliente, grupo) o ya existía.
     */
    public function recordIncoming(array $data): ?array
    {
        $key = is_array($data['key'] ?? null) ? $data['key'] : [];

        if (! empty($key['fromMe'])) {
            return null; // lo nuestro: no revierte al bot (evita bucles)
        }

        $contact = $this->contact($key, $data);
        if (! $contact) {
            return null;
        }
        $evolutionId = isset($key['id']) ? Str::limit((string) $key['id'], 100, '') : null;

        try {
            return $this->saveIncoming($contact, $data, $evolutionId);
        } catch (UniqueConstraintViolationException) {
            // Otro webhook puede haber creado el chat o guardado el mismo mensaje entre la lectura y la escritura.
            return $this->saveIncoming($contact, $data, $evolutionId);
        }
    }

    private function saveIncoming(array $contact, array $data, ?string $evolutionId): ?array
    {
        [$phone, $jid, $lid, $username] = $contact;
        $text = $this->textFrom($data);
        $type = $this->typeFrom($data, $text);
        $preview = $text !== null ? $text : '['.$type.']';
        $pushName = $data['pushName'] ?? null;
        $senderName = is_string($pushName) && trim($pushName) !== '' ? Str::limit($pushName, 120, '') : null;

        return DB::transaction(function () use ($phone, $jid, $lid, $username, $data, $evolutionId, $text, $type, $preview, $senderName) {
            if ($evolutionId && WhatsAppMessage::query()->where('evolution_id', $evolutionId)->exists()) {
                return null;
            }

            $matches = WhatsAppConversation::query()->where('jid', $jid)
                ->when($lid, fn ($query) => $query->orWhere('lid', $lid)->orWhere('jid', $lid))
                ->when($phone, fn ($query) => $query->orWhere('phone', $phone))
                ->orderBy('id')->lockForUpdate()->get();

            $private = $lid ? $matches->first(fn ($chat) => $chat->lid === $lid || $chat->jid === $lid) : null;
            $byPhone = $phone ? $matches->first(fn ($chat) => $chat->phone === $phone) : null;
            $conversation = $byPhone ?? $private ?? $matches->firstWhere('jid', $jid) ?? new WhatsAppConversation;
            $moveFlow = $private && $phone !== null && $private->phone === null;

            if ($byPhone && $private && ! $byPhone->is($private)) {
                $private->messages()->update(['conversation_id' => $byPhone->id]);
                $conversation->unread_count += $private->unread_count;
                $conversation->username ??= $private->username;
                $conversation->client_name ??= $private->client_name;
                if ($conversation->bot_active || (! $private->bot_active && $private->advisor_wait_until === null)) {
                    $conversation->bot_active = $private->bot_active;
                    $conversation->advisor_wait_until = $private->advisor_wait_until;
                }
                $private->delete();
            }

            $isNewBurst = (int) $conversation->unread_count === 0;
            $conversation->fill([
                'jid' => $jid,
                'lid' => $lid ?? $conversation->lid,
                'phone' => $phone ?? $conversation->phone,
                'username' => $username ?? $conversation->username,
                'client_name' => $senderName ?? $conversation->client_name,
                'unread_count' => $conversation->unread_count + 1,
                'last_message_preview' => Str::limit($preview, 250, ''),
                'last_message_at' => now(),
            ])->save();

            $message = $conversation->messages()->create([
                'direction' => WhatsAppMessage::DIRECTION_IN,
                'type' => $type,
                'body' => $text !== null ? Str::limit($text, 5000, '…') : null,
                'evolution_id' => $evolutionId,
                'status' => null,
                'sender_name' => $senderName ?? $username,
                'raw' => $this->safeRaw($data),
            ]);

            if ($moveFlow) {
                DB::afterCommit(fn () => app(WhatsappBotService::class)->moveConversationFlow($lid, $phone));
            }

            return ['message' => $message, 'notifyTeam' => $isNewBurst];
        }, 3);
    }

    public function notifyIncoming(WhatsAppMessage $message): void
    {
        $conversation = $message->conversation;
        StaffNotification::record(
            'whatsapp_message',
            'WhatsApp: '.$conversation->contactName(),
            Str::limit($message->body ?? '['.$message->type.']', 200, ''),
            route('whatsapp.chat.show', $conversation, false),
        );
    }

    /** @return array{?string, string, ?string, ?string}|null Teléfono, destino, LID y nombre de usuario. */
    private function contact(array $key, array $data): ?array
    {
        $remote = is_string($key['remoteJid'] ?? null) ? $key['remoteJid'] : '';
        if (preg_match('/@(g\.us|broadcast|newsletter)$/', $remote) === 1) {
            return null;
        }
        $alternate = $key['remoteJidAlt'] ?? null;
        $remoteLid = WhatsAppLid::isLid($remote) ? $remote : null;
        $lid = $remoteLid ?? (is_string($alternate) && WhatsAppLid::isLid($alternate) ? $alternate : null);

        $candidates = [$remote, $alternate];
        if (empty($key['fromMe'])) {
            array_push($candidates, $key['senderPn'] ?? null, $data['senderPn'] ?? null, $key['participantAlt'] ?? null);
        }
        $phone = null;
        foreach ($candidates as $candidate) {
            if (! is_string($candidate) || $candidate === '') {
                continue;
            }
            $jid = str_contains($candidate, '@') ? $candidate : $candidate.'@s.whatsapp.net';
            if (($phone = self::phoneFromJid($jid)) !== null) {
                break;
            }
        }

        WhatsAppLid::remember($lid, $phone);
        $phone ??= WhatsAppLid::phoneFor($lid);
        if ($phone === null && $lid === null) {
            return null;
        }

        $username = $key['remoteJidUsername'] ?? null;
        $username = is_string($username) && preg_match('/\A@?([A-Za-z0-9._]{1,119})\z/', $username, $handle) === 1
            ? '@'.$handle[1]
            : null;
        $jid = $remoteLid ?? ($phone !== null ? $phone.'@s.whatsapp.net' : $lid);

        return [$phone, $jid, $lid, $username];
    }

    /** Las devoluciones de nuestros envíos no son una intervención humana desde el celular. */
    public function recordPhoneReply(array $data): void
    {
        $key = is_array($data['key'] ?? null) ? $data['key'] : [];
        $id = is_string($key['id'] ?? null) ? Str::limit($key['id'], 100, '') : '';
        $timestamp = $data['messageTimestamp'] ?? null;
        if (empty($key['fromMe']) || $id === '' || ! is_numeric($timestamp)
            || ! in_array(strtolower((string) ($data['source'] ?? '')), ['android', 'ios'], true)
            || in_array($data['status'] ?? null, [0, 'ERROR', 'FAILED'], true)
            || $this->evolution->wasSentByApi($id)) {
            return;
        }

        $sentAt = now()->setTimestamp((int) $timestamp);
        if ($sentAt->gt(now()->addSeconds(30)) || $sentAt->lt(now()->subMinutes(WhatsAppConversation::ADVISOR_WAIT_MINUTES))) {
            return;
        }

        $contact = $this->contact($key, $data);
        $conversation = $contact ? WhatsAppConversation::query()->where('jid', $contact[1])
            ->when($contact[2], fn ($query, $lid) => $query->orWhere('lid', $lid))
            ->when($contact[0], fn ($query, $phone) => $query->orWhere('phone', $phone))
            ->first() : null;
        if (! $conversation || WhatsAppMessage::where('evolution_id', $id)->exists()) {
            return;
        }

        $text = $this->textFrom($data);
        $type = $this->typeFrom($data, $text);
        if ($text === null && ! in_array($type, ['audio', 'image', 'video', 'document', 'contact', 'location'], true)) {
            return;
        }

        $this->recordOutgoing($conversation, $text ?? '['.$type.']', 'sent', $id, $type);
        $conversation->advisorReplied($sentAt);
    }

    /** Guarda un mensaje saliente (bot o equipo) ya enviado. */
    public function recordOutgoing(WhatsAppConversation $conversation, string $body, string $status = 'sent', ?string $providerId = null, string $type = 'text'): WhatsAppMessage
    {
        $message = $conversation->messages()->create([
            'direction' => WhatsAppMessage::DIRECTION_OUT,
            'type' => $type,
            'body' => Str::limit($body, 5000, '…'),
            'status' => $status,
            'evolution_id' => $providerId,
        ]);

        $conversation->fill([
            'last_message_preview' => Str::limit($body, 250, ''),
            'last_message_at' => now(),
        ])->save();

        if ($providerId !== null && is_string($update = Cache::pull($this->receiptKey($providerId)))) {
            $this->updateReceipt($providerId, $update);
        }

        return $message;
    }

    /** Actualiza el estado de un mensaje saliente (evento messages.update). */
    public function applyStatusUpdate(array $data): void
    {
        $key = is_array($data['key'] ?? null) ? $data['key'] : [];
        $rawId = $key['id'] ?? $data['keyId'] ?? null;
        $evolutionId = is_string($rawId) ? Str::limit($rawId, 100, '') : null;

        if (! $evolutionId || empty($key['fromMe'] ?? $data['fromMe'] ?? false)) {
            return;
        }

        // Estado de un mensaje nuestro con el @lid del cliente: se aprende de qué número es.
        if (WhatsAppLid::isLid($key['remoteJid'] ?? null)) {
            $phone = self::phoneFromJid((string) ($key['remoteJidAlt'] ?? ''))
                ?? WhatsAppMessage::query()->where('evolution_id', $evolutionId)->first()?->conversation?->phone;
            WhatsAppLid::remember($key['remoteJid'], $phone);
        }

        $status = $data['update']['status'] ?? $data['status'] ?? null;
        $map = [
            0 => 'failed', 1 => 'sent', 2 => 'sent', 3 => 'delivered', 4 => 'read', 5 => 'read',
            'ERROR' => 'failed', 'PENDING' => 'sent', 'SERVER_ACK' => 'sent',
            'DELIVERY_ACK' => 'delivered', 'READ' => 'read', 'PLAYED' => 'read',
            'delivered' => 'delivered', 'read' => 'read', 'failed' => 'failed',
        ];

        if (! is_int($status) && ! is_string($status)) {
            return;
        }
        $normalized = is_string($status) ? strtoupper($status) : $status;
        $mapped = $map[$status] ?? $map[$normalized] ?? null;

        if ($mapped === null) {
            return;
        }

        if (! $this->updateReceipt($evolutionId, $mapped)) {
            $cacheKey = $this->receiptKey($evolutionId);
            $previous = Cache::get($cacheKey);
            if ($this->canUpdateStatus($previous, $mapped)) {
                Cache::put($cacheKey, $mapped, now()->addMinutes(5));
                // El envío puede haberse guardado mientras llegaba este acuse.
                if ($this->updateReceipt($evolutionId, $mapped)) {
                    Cache::forget($cacheKey);
                }
            }
        }
    }

    private function updateReceipt(string $providerId, string $status): bool
    {
        $query = WhatsAppMessage::query()
            ->where('evolution_id', $providerId)
            ->where('direction', WhatsAppMessage::DIRECTION_OUT);
        $previous = array_filter(array_keys(WhatsAppMessage::STATUSES), fn ($state) => $this->canUpdateStatus($state, $status));

        $updated = (clone $query)->whereIn('status', $previous)->update(['status' => $status]);
        if ($updated && config('reverb.chat_enabled') && ($conversationId = $query->value('conversation_id'))) {
            app(WhatsAppLive::class)->changed($conversationId);
        }

        return $updated || $query->exists();
    }

    private function canUpdateStatus(?string $before, string $after): bool
    {
        $order = ['failed' => 0, 'sent' => 1, 'delivered' => 2, 'read' => 3];

        return ($order[$after] ?? 0) > ($order[$before] ?? -1)
            || ($after === 'failed' && $before === 'sent');
    }

    private function receiptKey(string $providerId): string
    {
        return 'whatsapp:receipt:'.hash('sha256', $providerId);
    }

    // ------------------------------------------------------------------------------------------
    // Envío
    // ------------------------------------------------------------------------------------------

    /** Envía una respuesta y la guarda en el chat. Devuelve true si el proveedor la aceptó. */
    public function send(WhatsAppConversation $conversation, string $body, bool $useChatAddress = true): bool
    {
        $lid = $useChatAddress && WhatsAppLid::isLid($conversation->jid) ? $conversation->jid : null;

        return $this->sendAndRecord($conversation, $body, $lid);
    }

    private function sendAndRecord(WhatsAppConversation $conversation, string $body, ?string $lid): bool
    {
        $phone = $conversation->phone !== null
            ? $this->whatsApp->normalizePhone($conversation->phone, international: true)
            : null;
        $this->pace($phone ?? $lid ?? '');

        $receipt = $this->whatsApp->usesTemplates()
            ? ($phone !== null && $this->whatsApp->sendText('+'.$phone, $body) ? [] : null)
            : $this->evolution->sendTextReceipt($phone, $body, $lid);
        $id = is_string($receipt['key']['id'] ?? null) ? Str::limit($receipt['key']['id'], 100, '') : null;
        $ok = $receipt !== null;
        $this->recordOutgoing($conversation, $body, $ok ? 'sent' : 'failed', $id);

        return $ok;
    }

    /**
     * Deja un espacio mínimo entre dos mensajes seguidos al mismo número.
     *
     * Cuando salen dos mensajes en el mismo segundo (la respuesta del bot y un aviso, por
     * ejemplo) WhatsApp le muestra al cliente el aviso "Esperando el mensaje. Esto puede tomar
     * tiempo." en uno de los dos y ese mensaje llega tarde. Un segundo de separación lo evita.
     */
    private function pace(string $phone): void
    {
        $gap = (int) config('services.evolution.send_gap_ms', 1200);
        if ($gap <= 0 || $phone === '') {
            return;
        }

        $key = 'whatsapp:pace:'.$phone;
        $last = (int) Cache::get($key, 0);
        $now = (int) (microtime(true) * 1000);

        if ($last > 0 && ($wait = $gap - ($now - $last)) > 0) {
            usleep(min($wait, $gap) * 1000);
        }

        Cache::put($key, (int) (microtime(true) * 1000), now()->addMinutes(5));
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

        return $this->send($conversation, $body, useChatAddress: false);
    }

    public function sendToLid(string $lid, string $body): bool
    {
        if (! WhatsAppLid::isLid($lid)) {
            return false;
        }

        $conversation = WhatsAppConversation::query()->firstOrCreate(['lid' => $lid], ['jid' => $lid]);

        return $this->sendAndRecord($conversation, $body, $lid);
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
