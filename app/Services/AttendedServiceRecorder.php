<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\CashSession;
use App\Models\Sale;
use App\Models\Turn;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Al marcar una cita como "Atendida" o un turno como "Atendido" se da por hecho que el servicio se realizó
 * y generó ingreso: se registra automáticamente (con la comisión del barbero) y entra a la caja y a la nómina.
 * Si luego se cambia a otro estado, ese registro se elimina para no contarlo dos veces.
 */
class AttendedServiceRecorder
{
    public const ATTENDED = ['atendida', 'atendido'];

    /** @return string|null Mensaje para el usuario (o null si no hubo nada que avisar). */
    public function sync(Appointment|Turn $record, ?string $paymentMethod = null): ?string
    {
        $attended = in_array($record->status, self::ATTENDED, true);

        if (! $attended && $record->sale_id) {
            Sale::whereKey($record->sale_id)->first()?->delete();
            $record->forceFill(['sale_id' => null])->save();

            return 'Se quitó el ingreso que se había registrado por este servicio.';
        }

        if (! $attended || $record->sale_id) {
            return null;
        }

        $record->loadMissing(['service', 'barber']);
        if (! $record->service || ! $record->barber) {
            return 'Quedó como atendido, pero no tiene servicio asignado: edítalo y elige el servicio para que cuente como ingreso.';
        }

        $sale = DB::transaction(function () use ($record, $paymentMethod) {
            /** @var User $barber */
            $barber = $record->barber->load('serviceCommissions');
            $service = $record->service;
            $price = round((float) $service->price, 2);
            $percent = $barber->commissionPercentFor($service->id);
            $commission = round($price * $percent / 100, 2);
            $label = $record instanceof Appointment ? 'Cita' : 'Turno';

            $sale = Sale::create([
                'barber_id' => $barber->id,
                'cash_session_id' => CashSession::open()->latest('opened_at')->value('id'),
                'client_name' => $record->client_name,
                'payment_method' => array_key_exists((string) $paymentMethod, Sale::PAYMENT_METHODS) ? $paymentMethod : 'efectivo',
                'total' => $price,
                'commission_percentage' => $percent,
                'commission_amount' => $commission,
                'notes' => $label.' atendido #'.$record->id,
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

            return $sale;
        });

        $record->forceFill(['sale_id' => $sale->id])->save();

        return 'Servicio realizado registrado: '.format_money($sale->total).' de ingreso ('.$record->barber->name.' gana '.format_money($sale->commission_amount).').';
    }
}
