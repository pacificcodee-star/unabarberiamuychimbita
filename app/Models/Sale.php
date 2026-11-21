<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Sale extends Model
{
    public const PAYMENT_METHODS = [
        'efectivo' => 'Efectivo',
        'nequi' => 'Nequi',
        'daviplata' => 'Daviplata',
        'transferencia' => 'Transferencia',
        'breb' => 'Bre-b',
        'tarjeta' => 'Tarjeta',
    ];

    protected $fillable = [
        'barber_id',
        'cash_session_id',
        'client_name',
        'payment_method',
        'total',
        'commission_percentage',
        'commission_amount',
        'notes',
        'sold_at',
    ];

    protected function casts(): array
    {
        return [
            'barber_id' => 'integer',
            'total' => 'decimal:2',
            'commission_percentage' => 'decimal:2',
            'commission_amount' => 'decimal:2',
            'sold_at' => 'datetime',
        ];
    }

    public function barber(): BelongsTo
    {
        return $this->belongsTo(User::class, 'barber_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(SaleItem::class);
    }

    public function cashSession(): BelongsTo
    {
        return $this->belongsTo(CashSession::class);
    }

    public function paymentLabel(): string
    {
        return self::PAYMENT_METHODS[$this->payment_method] ?? ucfirst($this->payment_method);
    }
}
