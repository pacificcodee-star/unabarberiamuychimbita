<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\CashSession;
use App\Models\Sale;
use App\Models\Turn;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Registra el ingreso una sola vez, solo para un servicio con llegada verificada y finalizado. */
class AttendedServiceRecorder
{
    public const ATTENDED = ['atendida', 'atendido'];

    /** @return string|null Mensaje para el usuario (o null si no hubo nada que avisar). */
    public function sync(Appointment|Turn $record, ?string $paymentMethod = null): ?string
    {
        $message = DB::transaction(function () use ($record, $paymentMethod) {
            $current = $record::query()
                ->with(['service', 'barber.serviceCommissions'])
                ->lockForUpdate()
                ->find($record->getKey());

            if (! $current) {
                return null;
            }

            $attended = in_array($current->status, self::ATTENDED, true);

            if (! $attended && $current->sale_id) {
                Sale::whereKey($current->sale_id)->delete();
                $current->forceFill(['sale_id' => null])->save();

                return 'Se quitó el ingreso que se había registrado por este servicio.';
            }

            if (! $attended) {
                return null;
            }

            $legacyAttended = $current->attendance_status === null && $attended;

            if (! $legacyAttended && $current->attendance_status !== 'presente') {
                return 'El servicio quedó como atendido, pero no tiene llegada verificada: no se registró el ingreso.';
            }

            if ($current->sale_id) {
                return null;
            }

            if (! $current->service || ! $current->barber) {
                return 'Quedó como atendido, pero no tiene servicio asignado: edítalo y elige el servicio para que cuente como ingreso.';
            }

            /** @var User $barber */
            $barber = $current->barber;
            $service = $current->service;
            $price = round((float) $service->price, 2);
            $percent = $barber->commissionPercentFor($service->id);
            $commission = round($price * $percent / 100, 2);
            $label = $current instanceof Appointment ? 'Cita' : 'Turno';

            $sale = Sale::create([
                'barber_id' => $barber->id,
                'cash_session_id' => CashSession::open()->latest('opened_at')->value('id'),
                'client_name' => $current->client_name,
                'payment_method' => array_key_exists((string) $paymentMethod, Sale::PAYMENT_METHODS) ? $paymentMethod : 'efectivo',
                'total' => $price,
                'commission_percentage' => $percent,
                'commission_amount' => $commission,
                'notes' => $label.' atendido #'.$current->id,
                'sold_at' => now(),
            ]);
            $sale->items()->create([
                'service_id' => $service->id,
                'service_name' => $service->name,
                'price' => $price,
                'quantity' => 1,
                'subtotal' => $price,
                'commission_percentage' => $percent,
                'commission_amount' => $commission,
            ]);

            $current->forceFill(['sale_id' => $sale->id])->save();

            return 'Servicio realizado registrado: '.format_money($sale->total).' de ingreso ('.$barber->name.' gana '.format_money($commission).').';
        });

        $record->refresh();

        return $message;
    }
}
