<?php

namespace App\Models;

use App\Support\WhatsAppLid;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Conversación de WhatsApp con un cliente (una por identidad estable). */
class WhatsAppConversation extends Model
{
    /** Laravel pluralizaría "WhatsAppConversation" como whats_app_conversations. */
    protected $table = 'whatsapp_conversations';

    public const ADVISOR_WAIT_MINUTES = 30;

    protected $fillable = [
        'jid',
        'phone',
        'username',
        'client_name',
        'unread_count',
        'last_message_preview',
        'last_message_at',
        'bot_active',
        'advisor_wait_until',
    ];

    protected function casts(): array
    {
        return [
            'unread_count' => 'integer',
            'bot_active' => 'boolean',
            'last_message_at' => 'datetime',
            'advisor_wait_until' => 'datetime',
        ];
    }

    /** Guarda el username canónico, sin @; nunca convierte un nombre visible en identidad. */
    public function setUsernameAttribute(?string $value): void
    {
        $this->attributes['username'] = self::normalizeUsername($value);
    }

    /** @example @Nombre123 -> nombre123 */
    public static function normalizeUsername(?string $value): ?string
    {
        $username = strtolower(ltrim(trim((string) $value), '@'));

        if ($username === '' || strlen($username) > 35
            || preg_match('/\A[a-z0-9][a-z0-9._]*\z/D', $username) !== 1
            || preg_match('/[a-z]/i', $username) !== 1
            || str_contains($username, '..')
            || str_ends_with($username, '.')) {
            return null;
        }

        return $username;
    }

    public function hasRealPhone(): bool
    {
        return is_string($this->phone) && preg_match('/\A[1-9][0-9]{7,14}\z/D', $this->phone) === 1;
    }

    public function realPhone(): string
    {
        return $this->hasRealPhone() ? (string) $this->phone : '';
    }

    public function usernameLabel(): ?string
    {
        return filled($this->username) ? '@'.$this->username : null;
    }

    /** Identidad que ve el equipo; el teléfono no se inventa cuando WhatsApp lo oculta. */
    public function contactIdentity(): string
    {
        return $this->usernameLabel()
            ?? ($this->hasRealPhone() ? (string) $this->phone : (string) $this->jid);
    }

    public function contactLabel(): string
    {
        return filled($this->client_name) ? (string) $this->client_name : $this->contactIdentity();
    }

    /** El JID sigue siendo la clave estable cuando no hay teléfono; @usuario es metadata actualizable. */
    public function botIdentity(): string
    {
        return $this->hasRealPhone() ? (string) $this->phone : (string) $this->jid;
    }

    /** Destino aceptado por Evolution: primero el JID exacto del chat, después teléfono o username. */
    public function replyAddress(): ?string
    {
        if (WhatsAppLid::isLid($this->jid)) {
            return (string) $this->jid;
        }

        if ($this->hasRealPhone()) {
            return (string) $this->phone;
        }

        if (filled($this->username)) {
            return $this->username.'@s.whatsapp.net';
        }

        return filled($this->jid) ? (string) $this->jid : null;
    }

    public function waitForAdvisor(): void
    {
        $this->update([
            'bot_active' => false,
            'advisor_wait_until' => now()->addMinutes(self::ADVISOR_WAIT_MINUTES),
        ]);
    }

    public function advisorReplied(CarbonInterface $sentAt): void
    {
        $this->newQuery()->whereKey($this->id)->where('bot_active', false)
            ->whereBetween('advisor_wait_until', [$sentAt, $sentAt->copy()->addMinutes(self::ADVISOR_WAIT_MINUTES)])
            ->update(['advisor_wait_until' => null]);
    }

    public function resumeIfAdvisorAbsent(): bool
    {
        if (! $this->advisor_wait_until || $this->advisor_wait_until->gt(now())) {
            return false;
        }

        $resumed = $this->newQuery()->whereKey($this->id)->where('bot_active', false)
            ->where('advisor_wait_until', '<=', now())
            ->update(['bot_active' => true, 'advisor_wait_until' => null]);
        if ($resumed) {
            $this->refresh();
        }

        return (bool) $resumed;
    }

    public function messages(): HasMany
    {
        return $this->hasMany(WhatsAppMessage::class, 'conversation_id');
    }

    /** Último mensaje ya guardado (para la lista del panel). */
    public function lastMessage()
    {
        return $this->hasOne(WhatsAppMessage::class, 'conversation_id')->latestOfMany();
    }

    public function markAsRead(): void
    {
        if ($this->unread_count > 0) {
            $this->forceFill(['unread_count' => 0])->save();
        }
    }

    /** Total de chats sin leer (badge del menú lateral). */
    public static function unreadTotal(): int
    {
        return (int) static::query()->sum('unread_count');
    }
}
