<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Insumo del negocio que se le entrega al barbero (cuchillas, talco, toallas…). */
class Supply extends Model
{
    protected $fillable = ['name', 'unit', 'cost', 'default_percentage', 'notes', 'active'];

    protected function casts(): array
    {
        return [
            'cost' => 'decimal:2',
            'default_percentage' => 'decimal:2',
            'active' => 'boolean',
        ];
    }

    public function scopeActive($query)
    {
        return $query->where('active', true);
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(SupplyDelivery::class);
    }
}
