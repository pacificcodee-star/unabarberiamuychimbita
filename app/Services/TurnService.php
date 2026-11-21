<?php

namespace App\Services;

use App\Models\Turn;
use App\Models\User;
use App\Services\WhatsApp\BookingMessenger;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class TurnService
{
    /** $notify = false cuando el bot ya responde en el chat (evita mensajes duplicados). */
    public function createFor(User $barber, array $data, bool $notify = true): Turn
    {
        abort_unless(
            $barber->role === 'barbero' && $barber->attention_mode === 'llegada' && $barber->active,
            422,
            'El barbero seleccionado no atiende por orden de llegada.'
        );

        $turnDate = $data['turn_date'] ?? now()->toDateString();

        $turn = DB::transaction(function () use ($barber, $data, $turnDate) {
            User::whereKey($barber->id)->lockForUpdate()->first();

            $nextNumber = (int) Turn::query()
                ->where('barber_id', $barber->id)
                ->whereDate('turn_date', $turnDate)
                ->max('turn_number') + 1;

            return Turn::create([
                'barber_id' => $barber->id,
                'service_id' => $data['service_id'] ?? null,
                'client_name' => $data['client_name'],
                'client_phone' => $data['client_phone'] ?? null,
                'client_email' => $data['client_email'] ?? null,
                'whatsapp_consent' => (bool) ($data['whatsapp_consent'] ?? false),
                'payment_method' => $data['payment_method'] ?? null,
                'turn_date' => $turnDate,
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

        if (Carbon::parse($turnDate)->isToday()) {
            app(TurnQueueService::class)->advance($barber->id);
        } else {
            app(ClientNotifier::class)->flush();
        }

        return $turn->refresh();
    }
}
