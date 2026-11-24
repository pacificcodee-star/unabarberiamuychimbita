<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Conversación de WhatsApp con un cliente (una por número). */
class WhatsAppConversation extends Model
{
    /** Laravel pluralizaría "WhatsAppConversation" como whats_app_conversations. */
    protected $table = 'whatsapp_conversations';

    public const ADVISOR_WAIT_MINUTES = 30;

    protected $fillable = [
        'jid',
        'phone',
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
