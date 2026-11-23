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
 *     servicio, la fecha, la hora, el barbero, el valor y la dirección.
 *
 * Usa *negrita* de WhatsApp; el push la quita antes de enviarse (ClientNotifier::plainText).
 */
final class AppointmentMessages
{
    /** Pide confirmar la cita recién agendada (o que volvió a quedar pendiente). */
    public static function confirmationRequest(Appointment $appointment, ?string $intro = null): string
    {
        return '👋 *'.self::hello($appointment).'*'."\n\n"
            .($intro ?? 'Recibimos tu solicitud de cita en *'.self::brand().'* 💈')."\n\n"
            .'📋 *Detalles de tu reserva:*'."\n\n"
            .self::details($appointment)."\n\n"
            .'🙏 *Por favor confirma tu cita* respondiendo *SÍ* a este mensaje.'."\n"
            .'📲 También puedes confirmarla en *Mis servicios* de nuestra página.'."\n"
            .'❌ Si no puedes asistir, responde *CANCELAR*.'."\n\n"
            .'⏳ Tu cita queda *pendiente* hasta que la confirmes.';
    }

    /** Cita confirmada (por el cliente o automáticamente). */
    public static function confirmed(Appointment $appointment, bool $automatic = false, bool $thanks = false, ?string $intro = null): string
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
            .($intro ?? 'Tu cita en *'.self::brand().'* quedó *confirmada*. 🎉')."\n\n"
            .'📋 *Detalles de tu cita:*'."\n\n"
            .self::details($appointment)."\n\n"
            .$closing."\n\n"
            .'💈 ¡Te esperamos!';
    }

    /** Cambio de fecha u hora, con los datos nuevos. */
    public static function rescheduled(Appointment $appointment): string
    {
        $pending = $appointment->status === 'pendiente';

        return '🔄 *'.self::hello($appointment).'*'."\n\n"
            .'Tu cita en *'.self::brand().'* fue *reprogramada*.'."\n\n"
            .'📋 *Nuevos detalles:*'."\n\n"
            .self::details($appointment)."\n\n"
            .($pending
                ? '🙏 *Por favor confirma tu cita* respondiendo *SÍ* a este mensaje.'."\n".'❌ Si no puedes asistir, responde *CANCELAR*.'
                : '✅ Tu cita sigue *confirmada* en el nuevo horario.'."\n".'🔔 Te enviaremos un recordatorio *30 minutos antes*.')
            ."\n\n".'💈 ¡Te esperamos!';
    }

    /** La barbería volvió a activar una cita que estaba cancelada. */
    public static function reactivated(Appointment $appointment): string
    {
        return $appointment->status === 'confirmada'
            ? self::confirmed($appointment, intro: 'Tu cita en *'.self::brand().'* fue *reactivada* y quedó *confirmada*. 🎉')
            : self::confirmationRequest($appointment, 'Tu cita en *'.self::brand().'* fue *reactivada*. 💈');
    }

    /**
     * Resumen de una línea para la notificación del navegador o de la app (sin asteriscos ni saludos):
     * "Corte degradado · mié 18 nov, 3:30 p. m. con Carlos".
     */
    public static function short(Appointment $appointment): string
    {
        $appointment->loadMissing(['service', 'barber']);
        $start = $appointment->startsAt()->copy()->locale('es');
        $barber = $appointment->barber?->name ? ' con '.explode(' ', trim($appointment->barber->name))[0] : '';
        $when = str_replace('.', '', $start->isoFormat('ddd D MMM')).', '.self::time($start); // "mié 18 nov"

        return trim(($appointment->service?->name ? $appointment->service->name.' · ' : '').$when.$barber);
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
