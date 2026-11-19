<?php

namespace App\Services\WhatsApp;

use App\Models\Appointment;
use App\Models\Service;
use App\Models\SiteSetting;
use App\Models\StaffNotification;
use App\Models\Turn;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use App\Services\ClientNotifier;
use App\Services\TurnQueueService;
use Illuminate\Support\Str;

/**
 * Bot automático de WhatsApp de la barbería.
 *
 * Responde solo a los clientes que escriben al negocio: confirma y cancela citas
 * o turnos, informa el estado de la fila y responde precios, horarios y ubicación.
 * Todo con los mismos servicios que usa el panel, así que la agenda nunca se desincroniza.
 */
class WhatsappBotService
{
    public function __construct(
        private readonly WhatsAppInbox $inbox,
        private readonly ClientNotifier $notifier,
        private readonly TurnQueueService $queue,
    ) {
    }

    public function enabled(): bool
    {
        return (bool) config('services.evolution.bot_enabled');
    }

    /**
     * Procesa un mensaje entrante y envía la respuesta si corresponde.
     * Devuelve la respuesta enviada o null cuando el bot calla.
     */
    public function handle(WhatsAppConversation $conversation, WhatsAppMessage $message): ?string
    {
        if (! $this->enabled() || ! $conversation->bot_active) {
            return null;
        }

        if ($message->direction !== WhatsAppMessage::DIRECTION_IN) {
            return null;
        }

        $text = trim((string) ($message->body ?? ''));

        // Foto, audio o nota de voz: se responde con el menú para que nunca quede en visto.
        $reply = $text === '' ? $this->menu() : $this->replyFor($conversation, $text);

        if ($reply === null) {
            return null;
        }

        $this->inbox->send($conversation, $reply);

        return $reply;
    }

    /** Decide qué responde según lo que escribió el cliente. */
    protected function replyFor(WhatsAppConversation $conversation, string $text): ?string
    {
        $t = $this->normalize($text);

        // El orden importa: "cancelar mi cita" debe caer en cancelar, no en reservar.
        if ($this->has($t, ['cancelar', 'cancelo', 'anular', 'anulo', 'no podre', 'no puedo asistir', 'no asistire', 'no llegare'])) {
            return $this->cancelAction($conversation);
        }

        if ($this->has($t, ['confirmar', 'confirmo', 'asisto', 'confirmada', 'si asisto'])) {
            return $this->confirmAction($conversation);
        }

        if ($this->has($t, ['estado', 'como voy', 'cuanto falta', 'en que va', 'mi cita', 'mi turno', 'voy en fila', 'situacion', 'ya me toca'])) {
            return $this->statusAction($conversation);
        }

        if ($this->has($t, ['precio', 'precios', 'cuanto cuesta', 'cuanto', 'costo', 'costos', 'valor', 'valores', 'tarifa'])) {
            return $this->pricesReply();
        }

        if ($this->has($t, ['horario', 'horarios', 'abren', 'cierran', 'a que hora', 'que hora', 'abiertos'])) {
            return $this->hoursReply();
        }

        if ($this->has($t, ['ubicacion', 'donde estan', 'donde', 'direccion', 'mapa', 'llegar', 'como llego'])) {
            return $this->addressReply();
        }

        if ($this->has($t, ['telefono', 'contacto', 'llamar', 'celular', 'numero'])) {
            return $this->contactReply();
        }

        if ($this->has($t, ['reservar', 'reserva', 'cita', 'turno', 'agendar', 'agenda', 'disponible'])) {
            return $this->bookingReply($conversation);
        }

        if ($this->has($t, ['hola', 'buenas', 'buenos dias', 'que tal', 'menu', 'ayuda', 'help', 'info', 'informacion'])) {
            return $this->menu();
        }

        // Sin intención clara: menú con las opciones, nunca en silencio.
        return $this->menu();
    }

    // ------------------------------------------------------------------------------------------
    // Acciones sobre la agenda
    // ------------------------------------------------------------------------------------------

    protected function confirmAction(WhatsAppConversation $conversation): string
    {
        if ($appointment = $this->findAppointment($conversation->phone)) {
            if ($appointment->status === 'confirmada') {
                return 'Tu cita ya está confirmada ✅ '.$this->appointmentWhen($appointment).'. ¡Te esperamos!';
            }

            $before = ['status' => $appointment->status, 'starts_at' => $appointment->startsAt()];
            $appointment->update(['status' => 'confirmada']);

            // Avisa al cliente por los canales que tenga activados (igual que el panel).
            $this->notifier->appointmentChanged($appointment, $before);
            $this->notifier->flush();

            return '¡Listo! Tu cita quedó confirmada ✅ '.$this->appointmentWhen($appointment).".\nCualquier cambio, avísanos con tiempo. ¡Te esperamos!";
        }

        if ($turn = $this->findTurn($conversation->phone)) {
            return $this->turnStatusReply($turn);
        }

        return "No encontré una cita ni un turno pendiente a tu número.\n\nSi quieres reservar, escribe *reservar* y te dejo el enlace 📅";
    }

    protected function cancelAction(WhatsAppConversation $conversation): string
    {
        if ($appointment = $this->findAppointment($conversation->phone)) {
            $before = ['status' => $appointment->status, 'starts_at' => $appointment->startsAt()];
            $appointment->update(['status' => 'cancelada']);

            $this->notifier->appointmentChanged($appointment, $before, byClient: true);
            $this->notifier->flush();

            StaffNotification::record(
                'client_cancelled',
                'Cliente anuló su cita (WhatsApp)',
                ($appointment->client_name ?: $appointment->client_phone).' · '.$this->appointmentWhen($appointment),
                route('appointments.index'),
            );

            return 'Listo, cancelé tu cita del '.$this->appointmentWhen($appointment)." 🙁\nSi cambias de idea, puedes reservar otra cuando quieras: escribe *reservar*.";
        }

        if ($turn = $this->findTurn($conversation->phone)) {
            $cancelled = Turn::query()
                ->whereKey($turn->id)
                ->whereIn('status', ['esperando', 'llamado'])
                ->update(['status' => 'cancelado', 'finished_at' => now()]);

            if ($cancelled) {
                $fresh = $turn->fresh();
                $this->notifier->cancelPending($fresh);

                StaffNotification::record(
                    'client_cancelled',
                    'Cliente anuló su turno (WhatsApp)',
                    ($fresh->client_name ?: $fresh->client_phone).' · turno #'.str_pad((string) $fresh->turn_number, 3, '0', STR_PAD_LEFT),
                    route('turns.index'),
                );

                $this->queue->advance((int) $fresh->barber_id);

                return 'Listo, anulé tu turno #'.str_pad((string) $fresh->turn_number, 3, '0', STR_PAD_LEFT).' y la fila se reorganizó ✅';
            }

            return 'Tu turno ya estaba finalizado o en atención, así que no pude anularlo.\nEscríbenos al equipo si necesitas ayuda 🙌';
        }

        return "No encontré una cita ni un turno activo a tu número.\n\nSi quieres anular algo, dime el día o escríbenos al equipo.";
    }

    protected function statusAction(WhatsAppConversation $conversation): string
    {
        if ($turn = $this->findTurn($conversation->phone)) {
            return $this->turnStatusReply($turn);
        }

        if ($appointment = $this->findAppointment($conversation->phone)) {
            return 'Tu cita está *'.mb_strtolower($appointment->statusLabel(), 'ES').'* para '.$this->appointmentWhen($appointment)
                ."\nSeguimiento y cancelación: ".$this->trackUrl($appointment);
        }

        return "No encontré citas ni turnos activos a tu número 📭\n\nPara reservar, escribe *reservar* y te comparto el enlace.";
    }

    // ------------------------------------------------------------------------------------------
    // Consultas frecuentes
    // ------------------------------------------------------------------------------------------

    protected function bookingReply(WhatsAppConversation $conversation): string
    {
        if ($appointment = $this->findAppointment($conversation->phone)) {
            return 'Ya tienes una cita '.$appointment->statusLabel().' el '.$this->appointmentWhen($appointment)
                ."\nPara confirmar escribe *confirmar*, para anular *cancelar*.\n\n¿Quieres agendar otra? ".$this->bookingUrl();
        }

        if ($turn = $this->findTurn($conversation->phone)) {
            return 'Ya estás en la fila hoy 🙌 '.$this->turnStatusReply($turn);
        }

        return "📅 Reserva en línea en un minuto:\n".$this->bookingUrl()."\n\nAhí eliges barbero, servicio, día y hora. Cuando reserves, te confirmo por aquí mismo.";
    }

    protected function pricesReply(): string
    {
        $services = Service::query()->active()->orderBy('price')->limit(8)->get();

        if ($services->isEmpty()) {
            return 'Estamos actualizando los precios. Escríbenos al equipo y te confirmamos al instante 🙌';
        }

        $lines = $services->map(fn (Service $service) => '• '.$service->name.' — '.$this->money($service->price));

        return "💈 Precios de *Una Barbería Muy Chimbita*:\n\n".$lines->implode("\n")
            ."\n\nReserva cuando quieras: ".$this->bookingUrl();
    }

    protected function hoursReply(): string
    {
        $settings = SiteSetting::currentData();
        $today = SiteSetting::todayHours($settings);

        $week = collect(SiteSetting::groupedHours($settings))
            ->map(fn ($group) => '• '.$group['label'].': '.$group['value'])
            ->implode("\n");

        return "🕐 *Horarios*\n\nHoy: ".$today."\n\n".$week."\n\nReserva: ".$this->bookingUrl();
    }

    protected function addressReply(): string
    {
        $settings = SiteSetting::currentData();
        $address = trim((string) ($settings['address'] ?? ''));
        $city = trim((string) ($settings['city'] ?? ''));
        $full = trim($address.($city !== '' ? ', '.$city : ''));

        if ($full === '') {
            return 'Te comparto la ubicación en la página: '.route('public.contact');
        }

        $map = 'https://www.google.com/maps/search/?api=1&query='.urlencode($full);

        return "📍 *Estamos en*\n".$full."\n\nCómo llegar: ".$map;
    }

    protected function contactReply(): string
    {
        $settings = SiteSetting::currentData();
        $phone = trim((string) ($settings['phone_link'] ?? ($settings['phone'] ?? '')));
        $email = trim((string) ($settings['email'] ?? ''));

        $lines = ['📞 Teléfono: '.($phone !== '' ? $phone : 'no disponible')];
        if ($email !== '') {
            $lines[] = '✉️ Correo: '.$email;
        }

        $lines[] = '🌐 Página: '.route('landing');

        return implode("\n", $lines);
    }

    protected function menu(): string
    {
        return "¡Hola! 👋 Soy el asistente de *Una Barbería Muy Chimbita*.\n\n"
            ."Puedo ayudarte con:\n"
            ."1️⃣ *reservar* → cita o turno en línea\n"
            ."2️⃣ *confirmar* → confirma tu cita\n"
            ."3️⃣ *cancelar* → anula tu cita o turno\n"
            ."4️⃣ *estado* → cómo voy en la fila\n"
            ."5️⃣ *precios* → lista de servicios\n"
            ."6️⃣ *horarios* → cuándo abrimos\n"
            ."7️⃣ *ubicación* → dónde estamos\n\n"
            ."También te escucha el equipo de la barbería por aquí 💈\n"
            ."Reserva: ".$this->bookingUrl();
    }

    // ------------------------------------------------------------------------------------------
    // Utilidades
    // ------------------------------------------------------------------------------------------

    /** Cita activa (pendiente/confirmada) del número, la más próxima. */
    protected function findAppointment(string $phone): ?Appointment
    {
        $local = substr($phone, -10);

        if ($local === '') {
            return null;
        }

        return Appointment::query()
            ->where('client_phone', 'like', '%'.$local)
            ->whereIn('status', ['pendiente', 'confirmada'])
            ->whereDate('appointment_date', '>=', now()->toDateString())
            ->orderBy('appointment_date')
            ->orderBy('appointment_time')
            ->first();
    }

    /** Turno aún no finalizado del número. */
    protected function findTurn(string $phone): ?Turn
    {
        $local = substr($phone, -10);

        if ($local === '') {
            return null;
        }

        return Turn::query()
            ->where('client_phone', 'like', '%'.$local)
            ->whereIn('status', ['esperando', 'llamado', 'atendiendo'])
            ->orderBy('turn_number')
            ->first();
    }

    protected function turnStatusReply(Turn $turn): string
    {
        $number = '#'.str_pad((string) $turn->turn_number, 3, '0', STR_PAD_LEFT);

        if ($turn->status === 'atendiendo') {
            return 'Tu turno '.$number.' está *en atención* 💈 Ya casi terminas.';
        }

        if ($turn->status === 'llamado') {
            return '¡Te estamos llamando por el turno '.$number.'! 📣 Ven al local, te guardamos unos minutos.';
        }

        $estimate = $this->queue->estimates((int) $turn->barber_id)[$turn->id] ?? null;

        $line = $turn->status === 'esperando'
            ? ($estimate !== null
                ? 'Vas en la posición '.($estimate['ahead'] + 1).' y te llamamos hacia las '.$estimate['call_at']->format('H:i').' ⏳'
                : 'Estás esperando en la fila ⏳')
            : 'Tu turno '.$number.' está '.$turn->statusLabel();

        return 'Tu turno '.$number.': '.$line."\nSeguimiento: ".$this->trackUrl($turn);
    }

    protected function appointmentWhen(Appointment $appointment): string
    {
        $date = $appointment->appointment_date?->format('j/n/Y') ?? $appointment->appointment_date;
        $time = substr((string) $appointment->appointment_time, 0, 5);

        return 'el '.$date.($time !== '' ? ' a las '.$time : '');
    }

    protected function trackUrl(Appointment|Turn $subject): string
    {
        return route('track.show', [
            'type' => $subject instanceof Turn ? 'turno' : 'cita',
            'token' => $subject->public_token,
        ]);
    }

    protected function bookingUrl(): string
    {
        return route('public.booking');
    }

    protected function money(float|string|int|null $amount): string
    {
        return '$'.number_format((float) $amount, 0, ',', '.');
    }

    /** minúsculas, sin acentos y con espacios colapsados. */
    protected function normalize(string $text): string
    {
        return trim((string) Str::of(Str::ascii(mb_strtolower($text, 'UTF-8'), 'UTF-8'))->squish());
    }

    /** ¿Aparece alguna frase como palabra completa? */
    protected function has(string $text, array $phrases): bool
    {
        foreach ($phrases as $phrase) {
            $pattern = '/(?<![\p{L}\p{N}])'.preg_quote($phrase, '/').'(?![\p{L}\p{N}])/ui';

            if (preg_match($pattern, $text) === 1) {
                return true;
            }
        }

        return false;
    }
}
