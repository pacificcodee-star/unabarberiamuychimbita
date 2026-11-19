<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Conversación de WhatsApp con un cliente (una por número). */
class WhatsAppConversation extends Model
{
    /** Laravel pluralizaría "WhatsAppConversation" como whats_app_conversations. */
    protected $table = 'whatsapp_conversations';

    protected $fillable = [
        'jid',
        'phone',
        'client_name',
        'unread_count',
        'last_message_preview',
        'last_message_at',
        'bot_active',
    ];

    protected function casts(): array
    {
        return [
            'unread_count' => 'integer',
            'bot_active' => 'boolean',
            'last_message_at' => 'datetime',
        ];
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
