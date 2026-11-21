<?php

namespace App\Services\WhatsApp;

use App\Models\Appointment;
use App\Models\SiteSetting;
use App\Models\Turn;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Mensajes automáticos de la agenda por WhatsApp: pide confirmación de las citas
 * recién agendadas y avisa que el turno quedó registrado. Lo que el cliente
 * responda ("sí", "cancelar"...) lo procesa WhatsappBotService.
 */
class BookingMessenger
{
    public function __construct(private readonly WhatsAppInbox $inbox)
    {
    }

    public function requestAppointmentConfirmation(Appointment $appointment): bool
    {
        // Sin bot activo no hay quién lea el "sí" del cliente: no se pide confirmación.
        if ($appointment->status !== 'pendiente' || blank($appointment->client_phone) || ! config('services.evolution.bot_enabled')) {
            return false;
        }

        $body = $this->greeting($appointment->client_name).' Tu cita en *'.$this->brand().'* quedó registrada para *'
            .$this->when($appointment->appointment_date?->toDateString(), $appointment->appointment_time).'*'
            .($appointment->barber?->name ? ' con *'.$appointment->barber->name.'*' : '')
            .($appointment->service?->name ? ' ('.$appointment->service->name.')' : '').".\n\n"
            ."Para confirmarla, responde *SÍ*.\n"
            .'Si no vas a poder asistir, responde *CANCELAR*.';

        return $this->send($appointment->client_phone, $appointment->client_name, $body);
    }

    public function announceTurn(Turn $turn): bool
    {
        if (blank($turn->client_phone)) {
            return false;
        }

        $body = $this->greeting($turn->client_name).' Tu turno *'.$this->number($turn).'* en *'.$this->brand().'* quedó registrado correctamente'
            .($turn->barber?->name ? ' con *'.$turn->barber->name.'*' : '')
            .($turn->service?->name ? ' ('.$turn->service->name.')' : '').".\n"
            .'Te avisaremos por aquí cuando sea tu turno.';

        return $this->send($turn->client_phone, $turn->client_name, $body);
    }

    private function send(string $phone, ?string $name, string $body): bool
    {
        try {
            return $this->inbox->sendToPhone($phone, $body, $name);
        } catch (Throwable $e) {
            Log::warning('WhatsApp: no se pudo enviar el aviso de agenda', ['to' => \App\Support\Pii::phone($phone), 'error' => $e->getMessage()]);

            return false;
        }
    }

    private function when(?string $date, ?string $time): string
    {
        $moment = Carbon::parse(trim((string) $date.' '.substr((string) $time, 0, 5)));

        return 'el '.$moment->locale('es')->translatedFormat('l j \d\e F').' a las '.$this->time($moment);
    }

    private function time(Carbon $moment): string
    {
        return $moment->format('g:i').' '.((int) $moment->format('G') >= 12 ? 'p. m.' : 'a. m.');
    }

    private function number(Turn $turn): string
    {
        return '#'.str_pad((string) $turn->turn_number, 3, '0', STR_PAD_LEFT);
    }

    private function greeting(?string $name): string
    {
        $first = trim((string) $name);

        return '¡Hola'.($first !== '' ? ', '.explode(' ', $first)[0] : '').'!';
    }

    private function brand(): string
    {
        $settings = SiteSetting::currentData();

        return (string) ($settings['brand_name'] ?? config('app.name', 'la barbería'));
    }
}
