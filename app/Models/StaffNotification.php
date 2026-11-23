<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use App\Services\PushSender;
use Throwable;

/** Aviso para el equipo de la barbería. Se ve en la campana del panel. */
class StaffNotification extends Model
{
    public const ICONS = [
        'turn_new' => 'bi-person-plus',
        'appt_new' => 'bi-calendar-plus',
        'appt_rescheduled' => 'bi-calendar-event',
        'client_cancelled' => 'bi-x-circle',
        'client_confirmed' => 'bi-check2-circle',
        'turn_called' => 'bi-megaphone',
        'turn_finished' => 'bi-check2-circle',
        'survey_attention' => 'bi-chat-square-heart',
        'survey_review' => 'bi-shield-exclamation',
    ];

    protected $fillable = ['barber_id', 'type', 'title', 'body', 'url'];

    public function barber(): BelongsTo
    {
        return $this->belongsTo(User::class, 'barber_id');
    }

    /** Color del ícono según el tipo de aviso (clases .tone-* del panel). */
    public const TONES = [
        'turn_new' => 'blue',
        'appt_new' => 'green',
        'appt_rescheduled' => 'amber',
        'client_cancelled' => 'red',
        'client_confirmed' => 'green',
        'turn_called' => 'brand',
        'turn_finished' => 'green',
        'survey_attention' => 'amber',
        'survey_review' => 'red',
    ];

    /** Los avisos se borran solos después de este tiempo (programador diario). */
    public const RETENTION_DAYS = 90;

    /**
     * El administrador ve todo; cada barbero solo lo suyo. Si la persona vació su bandeja
     * ("Eliminar todas"), solo ve lo que llegó después.
     */
    public function scopeVisibleTo($query, User $user)
    {
        $query = $user->isAdmin() ? $query : $query->where('barber_id', $user->id);

        return $user->notifications_cleared_at
            ? $query->where('created_at', '>', $user->notifications_cleared_at)
            : $query;
    }

    public function icon(): string
    {
        return self::ICONS[$this->type] ?? 'bi-bell';
    }

    public function tone(): string
    {
        return self::TONES[$this->type] ?? 'neutral';
    }

    /** "Hoy", "Ayer" o la fecha, para agrupar la lista. */
    public function dayLabel(): string
    {
        $date = $this->created_at->copy()->locale('es');

        return match (true) {
            $date->isToday() => 'Hoy',
            $date->isYesterday() => 'Ayer',
            $date->isCurrentYear() => ucfirst($date->translatedFormat('l j \d\e F')),
            default => ucfirst($date->translatedFormat('j \d\e F \d\e Y')),
        };
    }

    /** Nunca interrumpe la reserva o la fila: si falla al guardar, solo queda en el log. */
    public static function record(string $type, string $title, ?string $body = null, ?string $url = null, ?int $barberId = null): void
    {
        try {
            $notification = static::create([
                'barber_id' => $barberId,
                'type' => $type,
                'title' => Str::limit($title, 145, ''),
                'body' => $body ? Str::limit($body, 495, '') : null,
                'url' => $url,
            ]);

            $recipients = User::query()
                ->where(function ($query) use ($barberId) {
                    $query->where('role', 'admin');
                    if ($barberId) $query->orWhere('id', $barberId);
                })
                ->with('pushSubscriptions')
                ->get();

            $sender = app(PushSender::class);
            foreach ($recipients as $recipient) {
                foreach ($recipient->pushSubscriptions as $subscription) {
                    $result = $sender->send($subscription, [
                        'title' => $notification->title,
                        'body' => $notification->body ?? '',
                        'url' => $notification->url ?: '/dashboard',
                        'tag' => 'staff-'.$notification->id,
                    ], 3600);

                    if ($result['ok']) {
                        $subscription->forceFill(['last_success_at' => now()])->save();
                    } elseif ($result['gone']) {
                        $subscription->delete();
                    }
                }
            }
        } catch (Throwable $e) {
            Log::warning('No se pudo guardar el aviso para el equipo', ['error' => $e->getMessage()]);
        }
    }
}
