<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Mensaje individual de una conversación de WhatsApp. */
class WhatsAppMessage extends Model
{
    /** Laravel pluralizaría "WhatsAppMessage" como whats_app_messages. */
    protected $table = 'whatsapp_messages';

    public const DIRECTION_IN = 'in';
    public const DIRECTION_OUT = 'out';

    /** Estados que manda WhatsApp vía evento messages.update. */
    public const STATUSES = [
        'sent' => 'Enviado',
        'delivered' => 'Entregado',
        'read' => 'Leído',
        'failed' => 'Fallido',
    ];

    protected $fillable = [
        'conversation_id',
        'direction',
        'type',
        'body',
        'evolution_id',
        'status',
        'sender_name',
        'raw',
    ];

    protected function casts(): array
    {
        return [
            'raw' => 'array',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(WhatsAppConversation::class, 'conversation_id');
    }

    public function isIncoming(): bool
    {
        return $this->direction === self::DIRECTION_IN;
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? ($this->status ? ucfirst($this->status) : '—');
    }
}
