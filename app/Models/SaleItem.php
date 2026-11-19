<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SaleItem extends Model
{
    protected $fillable = [
        'sale_id',
        'service_id',
        'pos_product_id',
        'service_name',
        'price',
        'quantity',
        'returned_quantity',
        'subtotal',
        'commission_percentage',
        'commission_amount',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'subtotal' => 'decimal:2',
            'commission_percentage' => 'decimal:2',
            'commission_amount' => 'decimal:2',
            'quantity' => 'integer',
            'returned_quantity' => 'integer',
        ];
    }

    public function posProduct(): BelongsTo
    {
        return $this->belongsTo(PosProduct::class);
    }

    public function returnableQuantity(): int
    {
        return max(0, (int) $this->quantity - (int) $this->returned_quantity);
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }
}
