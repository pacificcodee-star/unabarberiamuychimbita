<?php

namespace App\Support;

use App\Models\Appointment;
use App\Models\Turn;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;

class ClientServiceAccess
{
    private const COOKIE = 'mis_servicios';

    private static function device(Request $request): ?string
    {
        return $request->attributes->get(self::COOKIE) ?? $request->cookie(self::COOKIE);
    }

    public static function remember(Request $request, Appointment|Turn $subject): void
    {
        $device = self::device($request);
        if (! $device) {
            // Stable across simultaneous first bookings in the same Laravel session.
            $device = hash_hmac('sha256', $request->session()->getId(), config('app.key'));
            $request->attributes->set(self::COOKIE, $device);
            Cookie::queue(cookie(self::COOKIE, $device, 60 * 24 * 365, '/', null, $request->isSecure(), true, false, 'lax'));
        }

        DB::table('client_service_accesses')->insertOrIgnore([
            'device_hash' => hash('sha256', $device),
            $subject instanceof Turn ? 'turn_id' : 'appointment_id' => $subject->id,
        ]);
    }

    public static function hasServices(Request $request): bool
    {
        return self::device($request) && self::grants($request)->exists();
    }

    public static function owned(Request $request, string $type): Builder
    {
        $turn = $type === 'turno';

        return ($turn ? Turn::query() : Appointment::query())->whereIn('id',
            self::grants($request)->select($turn ? 'turn_id' : 'appointment_id')
        );
    }

    private static function grants(Request $request)
    {
        return DB::table('client_service_accesses')->where('device_hash', hash('sha256', self::device($request) ?? ''));
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
}
