<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Entrega de un insumo a un barbero. "amount" es lo que se le descuenta de sus comisiones. */
class SupplyDelivery extends Model
{
    protected $fillable = [
        'barber_id', 'supply_id', 'supply_name', 'quantity', 'unit_cost',
        'percentage', 'amount', 'delivered_at', 'notes', 'registered_by',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_cost' => 'decimal:2',
            'percentage' => 'decimal:2',
            'amount' => 'decimal:2',
            'delivered_at' => 'date',
        ];
    }

    public function barber(): BelongsTo
    {
        return $this->belongsTo(User::class, 'barber_id');
    }

    public function supply(): BelongsTo
    {
        return $this->belongsTo(Supply::class);
    }

    public function registeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registered_by');
    }
}
