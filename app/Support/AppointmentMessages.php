<?php

namespace App\Support;

use App\Models\Appointment;
use App\Models\SiteSetting;
use Carbon\Carbon;

/**
 * Mensajes de cita para el cliente (WhatsApp y push), iguales desde la página, el panel y el bot:
 *
 *  1. Al sacar la cita (queda pendiente): saludo con todos los detalles y la petición de confirmar.
 *  2. Al confirmarla: "¡Hola, Ana! Tu cita en Una Barbería Muy Chimbita quedó confirmada" con el
 *     servicio, el tipo de servicio, la fecha, la hora, el barbero, el valor y la dirección.
 *
 * Usa *negrita* de WhatsApp; el push la quita antes de enviarse (ClientNotifier::plainText).
 */
final class AppointmentMessages
{
    /** Pide confirmar la cita recién agendada. */
    public static function confirmationRequest(Appointment $appointment): string
    {
        return '👋 *'.self::hello($appointment).'*'."\n\n"
            .'Recibimos tu solicitud de cita en *'.self::brand().'* 💈'."\n\n"
            .'📋 *Detalles de tu reserva:*'."\n\n"
            .self::details($appointment)."\n\n"
            .'🙏 *Por favor confirma tu cita* respondiendo *SÍ* a este mensaje.'."\n"
            .'📲 También puedes confirmarla en *Mis servicios* de nuestra página.'."\n"
            .'❌ Si no puedes asistir, responde *CANCELAR*.'."\n\n"
            .'⏳ Tu cita queda *pendiente* hasta que la confirmes.';
    }

    /** Cita confirmada (por el cliente o automáticamente). */
    public static function confirmed(Appointment $appointment, bool $automatic = false, bool $thanks = false): string
    {
        $minutesLeft = (int) ceil(now()->diffInMinutes($appointment->startsAt(), false));

        $closing = $automatic
            ? '⏰ Tu cita es '.($minutesLeft <= 1 ? 'en este momento' : 'en '.$minutesLeft.' minutos')
                .' y ya quedó confirmada automáticamente: no necesitas responder nada más.'."\n"
                .'🚶 Te recomendamos salir ya para llegar a tiempo.'
            : ($minutesLeft > 30 ? '🔔 Te enviaremos un recordatorio *30 minutos antes*.'."\n" : '')
                .'🚶 Te recomendamos llegar *5 minutos antes*.';

        return '✅ *'.self::hello($appointment).'*'."\n\n"
            .($thanks ? '🙌 ¡Muchas gracias por confirmar!'."\n" : '')
            .'Tu cita en *'.self::brand().'* quedó *confirmada*. 🎉'."\n\n"
            .'📋 *Detalles de tu cita:*'."\n\n"
            .self::details($appointment)."\n\n"
            .$closing."\n\n"
            .'💈 ¡Te esperamos!';
    }

    /** Detalles en renglones separados, cada uno con su emoji. */
    public static function details(Appointment $appointment): string
    {
        $appointment->loadMissing(['service', 'barber']);
        $start = $appointment->startsAt();
        $address = trim((string) (SiteSetting::currentData()['address'] ?? ''));
        $service = $appointment->service;
        $price = $appointment->service_price ?? $service?->price;

        $lines = array_filter([
            trim((string) $appointment->client_name) !== '' ? '👤 *Cliente:* '.trim((string) $appointment->client_name) : null,
            $service ? '✂️ *Servicio:* '.$service->name : null,
            $service ? '🏷️ *Tipo de servicio:* '.$service->categoryLabel() : null,
            '📅 *Fecha:* '.ucfirst($start->copy()->locale('es')->translatedFormat('l j \d\e F')),
            '🕒 *Hora:* '.self::time($start),
            '💈 *Barbero:* '.($appointment->barber?->name ?? 'Por asignar'),
            $price !== null ? '💰 *Valor:* '.format_money($price) : null,
            $address !== '' ? '📍 *Dirección:* '.$address : null,
        ]);

        return implode("\n", $lines);
    }

    private static function hello(Appointment $appointment): string
    {
        $first = explode(' ', trim((string) $appointment->client_name))[0] ?? '';

        return $first === '' ? '¡Hola!' : '¡Hola, '.$first.'!';
    }

    private static function time(Carbon $moment): string
    {
        return $moment->format('g:i').' '.((int) $moment->format('G') >= 12 ? 'p. m.' : 'a. m.');
    }

    private static function brand(): string
    {
        return (string) (SiteSetting::currentData()['brand_name'] ?? config('app.name', 'la barbería'));
    }
}
