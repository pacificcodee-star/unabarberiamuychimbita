<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Review extends Model
{
    protected $fillable = [
        'client_name',
        'rating',
        'comment',
        'approved',
    ];

    protected function casts(): array
    {
        return [
            'rating' => 'integer',
            'approved' => 'boolean',
        ];
    }

    public function scopeApproved($query)
    {
        return $query->where('approved', true);
    }
}
