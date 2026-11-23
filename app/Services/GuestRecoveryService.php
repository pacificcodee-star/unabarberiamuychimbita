<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\GuestVerificationCode;
use App\Models\GuestVisitor;
use App\Models\Turn;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Recuperación de servicios desde otro dispositivo verificando el celular.
 *
 * El código nunca se guarda en claro (hash_hmac con APP_KEY), vence a los 10 minutos,
 * admite 5 intentos y cada solicitud invalida los códigos anteriores. Si no hay un
 * proveedor de WhatsApp configurado no se envía nada y se responde con un error
 * operativo: nunca se simula un envío exitoso.
 */
class GuestRecoveryService
{
    public const CODE_MINUTES = 10;

    public const MAX_ATTEMPTS = 5;

    public const RESEND_SECONDS = 60;

    public const MAX_PER_HOUR = 5;

    public function __construct(private readonly WhatsAppService $whatsApp)
    {
    }

    public function isAvailable(): bool
    {
        return $this->whatsApp->isConfigured();
    }

    public function request(string $rawPhone, ?string $ip): void
    {
        $phone = $this->phone($rawPhone, 'phone');

        if (! $this->isAvailable()) {
            throw ValidationException::withMessages([
                'phone' => 'La recuperación por código no está disponible ahora. Escríbenos por WhatsApp y te ayudamos.',
            ]);
        }

        $recent = GuestVerificationCode::where('phone', $phone)
            ->where('created_at', '>=', now()->subHour())->count();
        if ($recent >= self::MAX_PER_HOUR) {
            throw ValidationException::withMessages([
                'phone' => 'Pediste varios códigos seguidos. Espera una hora o escríbenos por WhatsApp.',
            ]);
        }

        $latest = GuestVerificationCode::where('phone', $phone)->latest('id')->first();
        if ($latest && $latest->created_at?->gt(now()->subSeconds(self::RESEND_SECONDS))) {
            throw ValidationException::withMessages([
                'phone' => 'Espera un minuto antes de pedir otro código.',
            ]);
        }

        // La respuesta es la misma exista o no el número, para no revelar teléfonos registrados.
        if (! $this->hasBookings($phone)) {
            return;
        }

        GuestVerificationCode::where('phone', $phone)->whereNull('consumed_at')
            ->update(['consumed_at' => now()]);

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $record = GuestVerificationCode::create([
            'phone' => $phone,
            'code_hash' => GuestVerificationCode::hashCode($code),
            'attempts' => 0,
            'expires_at' => now()->addMinutes(self::CODE_MINUTES),
            'sent_at' => now(),
            'request_ip' => $ip,
        ]);

        $sent = $this->whatsApp->sendText(
            $phone,
            "Tu código para recuperar tus servicios es {$code}. Vence en ".self::CODE_MINUTES
                ." minutos. Si no lo pediste, ignora este mensaje."
        );

        if (! $sent) {
            $record->delete();
            throw ValidationException::withMessages([
                'phone' => 'No pudimos enviar el código por WhatsApp. Inténtalo más tarde o escríbenos.',
            ]);
        }
    }

    public function verify(string $rawPhone, string $rawCode): GuestVisitor
    {
        $phone = $this->phone($rawPhone, 'phone');
        $code = preg_replace('/\D+/', '', $rawCode);

        if (strlen((string) $code) !== 6) {
            throw $this->invalid();
        }

        $visitor = DB::transaction(function () use ($phone, $code) {
            $record = GuestVerificationCode::where('phone', $phone)
                ->latest('id')->lockForUpdate()->first();

            if (! $record || $record->consumed_at || $record->expires_at->isPast()
                || $record->attempts >= self::MAX_ATTEMPTS) {
                return null;
            }

            if (! hash_equals($record->code_hash, GuestVerificationCode::hashCode($code))) {
                $record->increment('attempts');

                return null;
            }

            $record->forceFill(['consumed_at' => now()])->save();

            return $this->visitorFor($phone);
        });

        if (! $visitor) {
            throw $this->invalid();
        }

        return $visitor;
    }

    private function visitorFor(string $phone): GuestVisitor
    {
        $visitor = GuestVisitor::where('verified_phone', $phone)->first();
        $candidates = GuestVisitor::where('phone', $phone)->whereNull('phone_verified_at')->pluck('id');

        if (! $visitor) {
            $visitor = $candidates->isNotEmpty()
                ? GuestVisitor::findOrFail($candidates->first())
                : new GuestVisitor();
        }

        $visitor->forceFill([
            'phone' => $visitor->phone ?: $phone,
            'verified_phone' => $phone,
            'phone_verified_at' => $visitor->phone_verified_at ?: now(),
            'recovery_consent_at' => now(),
        ]);

        try {
            $visitor->save();
        } catch (UniqueConstraintViolationException) {
            // Otra verificación simultánea ganó el teléfono: ambas sesiones son del mismo titular.
            $visitor = GuestVisitor::where('verified_phone', $phone)->firstOrFail();
        }

        $ids = $candidates->push($visitor->id)->unique()->all();

        foreach ([Appointment::class, Turn::class] as $model) {
            $model::where('client_phone_normalized', $phone)
                ->where(fn ($query) => $query->whereNull('guest_visitor_id')->orWhereIn('guest_visitor_id', $ids))
                ->update(['guest_visitor_id' => $visitor->id]);
        }

        return $visitor;
    }

    private function hasBookings(string $phone): bool
    {
        return Appointment::where('client_phone_normalized', $phone)->exists()
            || Turn::where('client_phone_normalized', $phone)->exists();
    }

    private function phone(string $raw, string $field): string
    {
        $phone = GuestVisitor::normalizePhone($raw);

        if ($phone === null || strlen($phone) !== 10) {
            throw ValidationException::withMessages([
                $field => 'Escribe un número de celular de 10 dígitos.',
            ]);
        }

        return $phone;
    }

    private function invalid(): ValidationException
    {
        return ValidationException::withMessages([
            'code' => 'El código no es válido o venció. Solicita uno nuevo.',
        ]);
    }
}
