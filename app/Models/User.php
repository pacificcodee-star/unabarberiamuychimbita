<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'phone',
        'specialty',
        'bio',
        'photo_path',
        'video_path',
        'commission_percentage',
        'attention_mode',
        'active',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'commission_percentage' => 'decimal:2',
            'active' => 'boolean',
            'notifications_seen_at' => 'datetime',
            'notifications_cleared_at' => 'datetime',
        ];
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function scopeBarbers($query)
    {
        return $query->where('role', 'barbero');
    }

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class, 'barber_id');
    }

    /** Dispositivos de este usuario del equipo suscritos a avisos push. */
    public function pushSubscriptions(): MorphMany
    {
        return $this->morphMany(PushSubscription::class, 'notifiable');
    }

    public function commissionPayments(): HasMany
    {
        return $this->hasMany(CommissionPayment::class, 'barber_id');
    }

    /** % de comisión de este barbero en cada servicio (si un servicio no está, se usa commission_percentage). */
    public function serviceCommissions(): BelongsToMany
    {
        return $this->belongsToMany(Service::class, 'barber_service_commissions', 'barber_id', 'service_id')
            ->withPivot('percentage')->withTimestamps();
    }

    /** Servicios que ofrece este barbero. Sin filas configuradas se asume que ofrece todo el catálogo. */
    public function offeredServices(): BelongsToMany
    {
        return $this->belongsToMany(Service::class, 'barber_services', 'barber_id', 'service_id')->withTimestamps();
    }

    public function offersService(int $serviceId): bool
    {
        $offered = $this->relationLoaded('offeredServices')
            ? $this->offeredServices
            : $this->offeredServices()->get();

        return $offered->isEmpty() || $offered->contains('id', $serviceId);
    }

    public function offeredServiceIds(): array
    {
        return $this->offeredServices->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /** % del costo de cada insumo que se le descuenta a este barbero (si no está, se usa el % del insumo). */
    public function supplyPercentages(): BelongsToMany
    {
        return $this->belongsToMany(Supply::class, 'barber_supply_percentages', 'barber_id', 'supply_id')
            ->withPivot('percentage')->withTimestamps();
    }

    public function supplyDeliveries(): HasMany
    {
        return $this->hasMany(SupplyDelivery::class, 'barber_id');
    }

    public function unavailabilities(): HasMany
    {
        return $this->hasMany(BarberUnavailability::class, 'barber_id');
    }

    public function isUnavailableOnDate(string $date): bool
    {
        return $this->unavailabilitiesOn($date)->contains(fn (BarberUnavailability $u) => $u->isAllDay());
    }

    public function isUnavailableAt(string $date, string $time): bool
    {
        return $this->unavailabilitiesOn($date)->contains(fn (BarberUnavailability $u) => $u->blocksSlot($date, $time));
    }

    /** @var array<string, \Illuminate\Support\Collection> Bloqueos por día ya consultados (evita una consulta por cada franja). */
    private array $unavailabilityCache = [];

    private function unavailabilitiesOn(string $date)
    {
        $day = \Illuminate\Support\Carbon::parse($date)->toDateString();

        return $this->unavailabilityCache[$day] ??= $this->unavailabilities()->whereDate('date', $day)->get();
    }

    public function commissionPercentFor(int $serviceId): float
    {
        $custom = $this->serviceCommissions->firstWhere('id', $serviceId);

        return (float) ($custom ? $custom->pivot->percentage : $this->commission_percentage);
    }

    public function supplyPercentFor(Supply $supply): float
    {
        $custom = $this->supplyPercentages->firstWhere('id', $supply->id);

        return (float) ($custom ? $custom->pivot->percentage : $supply->default_percentage);
    }

    public function totalSupplyCharges(): float
    {
        return (float) $this->supplyDeliveries()->sum('amount');
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class, 'barber_id');
    }

    public function totalCommissions(): float
    {
        return (float) $this->sales()->sum('commission_amount');
    }

    public function totalCommissionPayments(): float
    {
        return (float) $this->commissionPayments()->sum('amount');
    }

    public function pendingCommission(): float
    {
        // Lo ganado por servicios + lo ganado por insumos - pagos ya hechos.
        return max(0, round($this->totalCommissions() + $this->totalSupplyCharges() - $this->totalCommissionPayments(), 2));
    }

    public function attentionModeLabel(): string
    {
        return $this->attention_mode === 'citas' ? 'Por citas' : 'Por orden de llegada';
    }
}
