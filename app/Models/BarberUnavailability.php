<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BarberUnavailability extends Model
{
    public const WEEKDAYS = [
        1 => 'Lunes',
        2 => 'Martes',
        3 => 'Miércoles',
        4 => 'Jueves',
        5 => 'Viernes',
        6 => 'Sábado',
        7 => 'Domingo',
    ];

    protected $fillable = [
        'barber_id',
        'date',
        'weekday',
        'start_time',
        'end_time',
        'note',
    ];

    protected $casts = [
        'date' => 'date',
        'weekday' => 'integer',
    ];

    public function barber(): BelongsTo
    {
        return $this->belongsTo(User::class, 'barber_id');
    }

    public function isAllDay(): bool
    {
        return empty($this->start_time) || empty($this->end_time);
    }

    public function blocksSlot(string $date, string $time): bool
    {
        if (! $this->appliesOn($date)) {
            return false;
        }

        if ($this->isAllDay()) {
            return true;
        }

        $slot = Carbon::createFromFormat('H:i', substr($time, 0, 5));

        return $slot->gte($this->startClock()) && $slot->lt($this->endClock());
    }

    /** ¿El bloqueo se cruza con un servicio que va de $start a $end (HH:MM) ese día? */
    public function blocksRange(string $date, string $start, string $end): bool
    {
        if (! $this->appliesOn($date)) {
            return false;
        }

        if ($this->isAllDay()) {
            return true;
        }

        $from = Carbon::createFromFormat('H:i', substr($start, 0, 5));
        $to = Carbon::createFromFormat('H:i', substr($end, 0, 5));

        return $from->lt($this->endClock()) && $to->gt($this->startClock());
    }

    private function appliesOn(string $date): bool
    {
        $day = Carbon::parse($date);

        return $this->weekday === null
            ? $this->date?->toDateString() === $day->toDateString()
            : $this->weekday === $day->dayOfWeekIso;
    }

    private function startClock(): Carbon
    {
        return Carbon::createFromFormat('H:i:s', strlen($this->start_time) === 5 ? $this->start_time.':00' : $this->start_time);
    }

    private function endClock(): Carbon
    {
        return Carbon::createFromFormat('H:i:s', strlen($this->end_time) === 5 ? $this->end_time.':00' : $this->end_time);
    }
}
