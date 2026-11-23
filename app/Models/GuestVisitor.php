<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GuestVisitor extends Model
{
    protected $fillable = [
        'phone',
        'verified_phone',
        'phone_verified_at',
        'recovery_consent_at',
        'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'phone_verified_at' => 'datetime',
            'recovery_consent_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    public function credentials(): HasMany
    {
        return $this->hasMany(GuestCredential::class);
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    public function turns(): HasMany
    {
        return $this->hasMany(Turn::class);
    }

    public function hasVerifiedPhone(): bool
    {
        return $this->phone_verified_at !== null;
    }

    /**
     * Los teléfonos se comparan por sus últimos 10 dígitos: el cliente puede escribirlos con
     * +57, espacios, guiones o paréntesis y aun así debe recuperar sus reservas.
     */
    public static function normalizePhone(?string $value): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $value);

        return $digits !== '' ? substr($digits, -10) : null;
    }
}
