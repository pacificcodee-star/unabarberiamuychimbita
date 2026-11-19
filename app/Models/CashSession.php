<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CashSession extends Model
{
    protected $fillable = [
        'opened_by',
        'closed_by',
        'initial_amount',
        'expected_amount',
        'counted_amount',
        'difference',
        'status',
        'notes',
        'opened_at',
        'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'initial_amount' => 'decimal:2',
            'expected_amount' => 'decimal:2',
            'counted_amount' => 'decimal:2',
            'difference' => 'decimal:2',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function openedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    public function scopeOpen($query)
    {
        return $query->where('status', 'abierta');
    }

    public function cashSales(): float
    {
        return (float) $this->sales()->where('payment_method', 'efectivo')->sum('total');
    }

    public function cashExpenses(): float
    {
        return (float) $this->expenses()->sum('amount');
    }

    public function calculateExpected(): float
    {
        return round((float) $this->initial_amount + $this->cashSales() - $this->cashExpenses(), 2);
    }
}
