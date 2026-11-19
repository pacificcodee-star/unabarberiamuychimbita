<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Expense extends Model
{
    public const CATEGORIES = [
        'arriendo' => 'Arriendo',
        'servicios_publicos' => 'Servicios públicos',
        'productos' => 'Productos',
        'cuchillas' => 'Cuchillas',
        'desinfectantes' => 'Desinfectantes',
        'publicidad' => 'Publicidad',
        'refrigerios' => 'Refrigerios',
        'otros' => 'Otros',
    ];

    protected $fillable = [
        'user_id',
        'cash_session_id',
        'category',
        'description',
        'amount',
        'expense_date',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'expense_date' => 'date',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function cashSession(): BelongsTo
    {
        return $this->belongsTo(CashSession::class);
    }

    public function categoryLabel(): string
    {
        return self::CATEGORIES[$this->category] ?? ucfirst($this->category);
    }
}
