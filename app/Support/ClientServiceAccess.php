<?php

namespace App\Support;

use App\Models\Appointment;
use App\Models\GuestCredential;
use App\Models\GuestVisitor;
use App\Models\Turn;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;

class ClientServiceAccess
{
    private const COOKIE = 'mis_servicios';

    private const CREDENTIAL_DAYS = 365;

    private const RENEW_BEFORE_DAYS = 180;

    /**
     * Credencial vigente de este navegador. Devuelve null si no existe o si fue revocada/vencida:
     * en ese caso el cliente debe usar "Recuperar mis servicios", nunca una vía menos segura.
     */
    public static function visitor(Request $request): ?GuestVisitor
    {
        if ($request->attributes->has('guest_visitor')) {
            return $request->attributes->get('guest_visitor');
        }

        $token = self::token($request);
        if ($token === null) {
            return null;
        }

        $credential = GuestCredential::with('visitor')->where('token_hash', hash('sha256', $token))->first();
        if ($credential) {
            if (! $credential->isActive()) {
                return null;
            }

            self::touch($request, $credential, $token, $credential->visitor);

            return self::bind($request, $credential->visitor, $token);
        }

        $visitor = self::adoptLegacyDevice($token);
        if ($visitor) {
            self::rememberCredential($token, $visitor);
            self::issueCookie($request, $token);

            return self::bind($request, $visitor, $token);
        }

        return null;
    }

    public static function remember(Request $request, Appointment|Turn $subject): void
    {
        $visitor = self::visitor($request) ?? self::createVisitor($request);
        $token = self::token($request);

        if ($subject->guest_visitor_id === null) {
            $subject->forceFill(['guest_visitor_id' => $visitor->id])->saveQuietly();
        } elseif ($subject->guest_visitor_id !== $visitor->id && $token !== null) {
            // El enlace firmado es una capacidad para ver esa reserva desde otro dispositivo.
            // Se registra como permiso puntual sin cambiarle el dueño a la reserva.
            self::insertLegacyGrant($token, $subject);
        }

        if ($visitor->phone === null && $subject->client_phone_normalized !== null) {
            $visitor->forceFill(['phone' => $subject->client_phone_normalized])->saveQuietly();
        }

        self::bind($request, $visitor, $token);
    }

    /**
     * Entrega una credencial nueva a este navegador después de verificar el teléfono.
     * No revoca las demás: el cliente puede usar varios dispositivos.
     */
    public static function issueCredential(Request $request, GuestVisitor $visitor): void
    {
        $token = self::newToken();
        self::rememberCredential($token, $visitor);
        self::issueCookie($request, $token);
        self::bind($request, $visitor, $token);
    }

    public static function hasServices(Request $request): bool
    {
        $visitor = self::visitor($request);
        if ($visitor && ($visitor->appointments()->exists() || $visitor->turns()->exists())) {
            return true;
        }

        return self::legacyGrantIds($request, 'appointment')->isNotEmpty()
            || self::legacyGrantIds($request, 'turn')->isNotEmpty();
    }

    public static function owned(Request $request, string $type): Builder
    {
        $turn = $type === 'turno';
        $visitor = self::visitor($request);
        $grantedIds = self::legacyGrantIds($request, $turn ? 'turn' : 'appointment');

        $query = $turn ? Turn::query() : Appointment::query();
        if (! $visitor && $grantedIds->isEmpty()) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function ($query) use ($visitor, $grantedIds): void {
            if ($visitor) {
                $query->where('guest_visitor_id', $visitor->id);
            }
            if ($grantedIds->isNotEmpty()) {
                $visitor ? $query->orWhereIn('id', $grantedIds) : $query->whereIn('id', $grantedIds);
            }
        });
    }

    public static function recoveryUrl(Appointment|Turn $subject): string
    {
        $turn = $subject instanceof Turn;
        $expires = ($turn ? $subject->turn_date : $subject->appointment_date)->copy()
            ->addDays((int) config('notificaciones.track_link_days', 30))->endOfDay();

        return URL::temporarySignedRoute('my-services.access', $expires, [
            'type' => $turn ? 'turno' : 'cita', 'token' => $subject->public_token,
        ]);
    }

    private static function token(Request $request): ?string
    {
        $token = $request->attributes->get(self::COOKIE.'_token') ?? $request->cookie(self::COOKIE);

        return is_string($token) && $token !== '' ? $token : null;
    }

    private static function bind(Request $request, GuestVisitor $visitor, ?string $token): GuestVisitor
    {
        $request->attributes->set('guest_visitor', $visitor);
        if ($token !== null) {
            $request->attributes->set(self::COOKIE.'_token', $token);
        }

        return $visitor;
    }

    private static function createVisitor(Request $request): GuestVisitor
    {
        $visitor = GuestVisitor::create(['last_seen_at' => now()]);
        $token = self::newToken();
        self::rememberCredential($token, $visitor);
        self::issueCookie($request, $token);

        return self::bind($request, $visitor, $token);
    }

    private static function newToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    private static function rememberCredential(string $token, GuestVisitor $visitor): GuestCredential
    {
        return GuestCredential::firstOrCreate(
            ['token_hash' => hash('sha256', $token)],
            [
                'guest_visitor_id' => $visitor->id,
                'expires_at' => now()->addDays(self::CREDENTIAL_DAYS),
                'last_used_at' => now(),
            ]
        );
    }

    private static function touch(Request $request, GuestCredential $credential, string $token, GuestVisitor $visitor): void
    {
        $now = now();
        $changes = [];

        if ($credential->last_used_at === null || $credential->last_used_at->lt($now->copy()->subHour())) {
            $changes['last_used_at'] = $now;
        }

        // Renovación deslizante: si al año le quedan menos de 180 días, se extiende sin rotar
        // el token (rotarlo en cada petición rompería pestañas abiertas en paralelo).
        if ($credential->expires_at->lt($now->copy()->addDays(self::RENEW_BEFORE_DAYS))) {
            $changes['expires_at'] = $now->copy()->addDays(self::CREDENTIAL_DAYS);
            self::issueCookie($request, $token);
        }

        if ($changes !== []) {
            $credential->forceFill($changes)->saveQuietly();
        }

        if ($visitor->last_seen_at === null || $visitor->last_seen_at->lt($now->copy()->subHour())) {
            $visitor->forceFill(['last_seen_at' => $now])->saveQuietly();
        }
    }

    private static function issueCookie(Request $request, string $token): void
    {
        Cookie::queue(cookie(
            self::COOKIE,
            $token,
            60 * 24 * self::CREDENTIAL_DAYS,
            '/',
            null,
            $request->isSecure(),
            true,
            false,
            'lax'
        ));
    }

    /**
     * Dispositivos que ya tenían la cookie anterior (hash del identificador de sesión).
     * Al primer uso se crea el visitante y se le transfieren las reservas sin dueño.
     */
    private static function adoptLegacyDevice(string $token): ?GuestVisitor
    {
        $hash = hash('sha256', $token);
        $appointmentIds = DB::table('client_service_accesses')->where('device_hash', $hash)
            ->whereNotNull('appointment_id')->pluck('appointment_id');
        $turnIds = DB::table('client_service_accesses')->where('device_hash', $hash)
            ->whereNotNull('turn_id')->pluck('turn_id');

        if ($appointmentIds->isEmpty() && $turnIds->isEmpty()) {
            return null;
        }

        return DB::transaction(function () use ($appointmentIds, $turnIds): GuestVisitor {
            $visitor = GuestVisitor::create(['last_seen_at' => now()]);

            if ($appointmentIds->isNotEmpty()) {
                Appointment::whereIn('id', $appointmentIds)->whereNull('guest_visitor_id')
                    ->update(['guest_visitor_id' => $visitor->id]);
            }
            if ($turnIds->isNotEmpty()) {
                Turn::whereIn('id', $turnIds)->whereNull('guest_visitor_id')
                    ->update(['guest_visitor_id' => $visitor->id]);
            }

            $phone = Appointment::where('guest_visitor_id', $visitor->id)->whereNotNull('client_phone_normalized')
                ->value('client_phone_normalized')
                ?: Turn::where('guest_visitor_id', $visitor->id)->whereNotNull('client_phone_normalized')
                    ->value('client_phone_normalized');
            if ($phone) {
                $visitor->forceFill(['phone' => $phone])->saveQuietly();
            }

            return $visitor;
        });
    }

    private static function insertLegacyGrant(string $token, Appointment|Turn $subject): void
    {
        DB::table('client_service_accesses')->insertOrIgnore([
            'device_hash' => hash('sha256', $token),
            $subject instanceof Turn ? 'turn_id' : 'appointment_id' => $subject->id,
        ]);
    }

    private static function legacyGrantIds(Request $request, string $column): \Illuminate\Support\Collection
    {
        $token = self::token($request);
        if ($token === null) {
            return collect();
        }

        return DB::table('client_service_accesses')
            ->where('device_hash', hash('sha256', $token))
            ->whereNotNull($column.'_id')
            ->pluck($column.'_id');
    }
}
