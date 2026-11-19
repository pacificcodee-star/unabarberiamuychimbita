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
        'client_cancelled' => 'bi-x-circle',
        'turn_called' => 'bi-megaphone',
        'turn_finished' => 'bi-check2-circle',
    ];

    protected $fillable = ['barber_id', 'type', 'title', 'body', 'url'];

    public function barber(): BelongsTo
    {
        return $this->belongsTo(User::class, 'barber_id');
    }

    /** El administrador ve todo; cada barbero solo lo suyo. */
    public function scopeVisibleTo($query, User $user)
    {
        return $user->isAdmin() ? $query : $query->where('barber_id', $user->id);
    }

    public function icon(): string
    {
        return self::ICONS[$this->type] ?? 'bi-bell';
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
                    if ($barberId) $query->orWhereKey($barberId);
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
