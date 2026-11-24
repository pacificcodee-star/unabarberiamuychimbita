<?php

namespace App\Services;

use App\Models\Turn;
use App\Models\User;
use App\Services\WhatsApp\BookingMessenger;
use App\Support\TurnSchedule;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TurnService
{
    public function __construct(private TurnQueueService $queue)
    {
    }

    /** $notify = false cuando el bot ya responde en el chat (evita mensajes duplicados). */
    public function createFor(User $barber, array $data, bool $notify = true, bool $advanceQueue = true): Turn
    {
        abort_unless(
            $barber->role === 'barbero' && $barber->attention_mode === 'llegada' && $barber->active,
            422,
            'El barbero seleccionado no atiende por orden de llegada.'
        );

        $turnDate = Carbon::parse($data['turn_date'] ?? now()->toDateString())->startOfDay();

        // Solo se piden turnos para hoy dentro del horario del día y hasta 30 minutos antes del cierre.
        if ($turnDate->isToday()) {
            $state = TurnSchedule::forNow();
            if (! $state['open']) {
                throw ValidationException::withMessages(['barber_id' => $state['message']]);
            }
        }

        // Si el servidor estuvo apagado a medianoche, el primer turno del día también cierra la jornada anterior.
        $this->queue->closePreviousDays();

        $turn = DB::transaction(function () use ($barber, $data, $turnDate) {
            User::whereKey($barber->id)->lockForUpdate()->first();
            $date = $turnDate->toDateString();

            // Contador por fecha: la numeración reinicia sola cada día y no se repite aunque lleguen
            // varias solicitudes a la vez (la fila del contador queda bloqueada dentro de la transacción).
            DB::table('turn_sequences')->insertOrIgnore([
                'turn_date' => $date,
                'last_number' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $lastSequence = (int) DB::table('turn_sequences')->where('turn_date', $date)->lockForUpdate()->value('last_number');
            $lastTurn = (int) Turn::query()->where('turn_date', $date)->max('turn_number');
            $nextNumber = max($lastSequence, $lastTurn) + 1;

            DB::table('turn_sequences')->where('turn_date', $date)->update([
                'last_number' => $nextNumber,
                'updated_at' => now(),
            ]);

            return Turn::create([
                'barber_id' => $barber->id,
                'service_id' => $data['service_id'] ?? null,
                'client_name' => $data['client_name'],
                'client_phone' => $data['client_phone'] ?? null,
                'history_whatsapp_phone' => $data['history_whatsapp_phone'] ?? null,
                'client_email' => $data['client_email'] ?? null,
                'whatsapp_consent' => (bool) ($data['whatsapp_consent'] ?? false),
                'payment_method' => $data['payment_method'] ?? null,
                'turn_date' => $date,
                'turn_number' => $nextNumber,
                'status' => 'esperando',
                'notes' => $data['notes'] ?? null,
            ]);
        });

        // Confirmación al cliente y, si el barbero está libre, se le llama de una vez (la fila avanza sola).
        $turn->load(['service', 'barber']);

        if ($notify) {
            app(BookingMessenger::class)->announceTurn($turn);
            app(ClientNotifier::class)->turnConfirmed($turn);
        }

        if (! $advanceQueue) {
            return $turn;
        }

        if ($turnDate->isToday()) {
            $this->queue->advance($barber->id);
        } else {
            app(ClientNotifier::class)->flush();
        }

        return $turn->refresh();
    }
}
