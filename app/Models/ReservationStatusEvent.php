<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ReservationStatusEvent extends Model
{
    protected $fillable = [
        'subject_type',
        'subject_id',
        'from_status',
        'to_status',
        'actor_type',
        'actor_id',
        'reason',
    ];

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}
