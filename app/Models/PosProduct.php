<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PosProduct extends Model
{
    protected $fillable = [
        'name',
        'reference',
        'category',
        'sale_price',
        'cost_price',
        'stock',
        'min_stock',
        'expiry_date',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'sale_price' => 'decimal:2',
            'cost_price' => 'decimal:2',
            'stock' => 'integer',
            'min_stock' => 'integer',
            'expiry_date' => 'date',
            'active' => 'boolean',
        ];
    }

    public function scopeActive($query)
    {
        return $query->where('active', true);
    }

    public function isLowStock(): bool
    {
        return $this->min_stock !== null && $this->stock <= $this->min_stock;
    }
}
