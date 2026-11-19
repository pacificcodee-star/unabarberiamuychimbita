<?php

namespace App\Http\Controllers;

use App\Models\CommissionPayment;
use App\Models\SupplyDelivery;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;

class CommissionController extends Controller
{
    public function index()
    {
        $barbers = User::barbers()
            ->withSum('sales as total_commission', 'commission_amount')
            ->withSum('commissionPayments as total_paid', 'amount')
            ->withSum('supplyDeliveries as total_supplies', 'amount')
            ->orderBy('name')
            ->get();

        $payments = CommissionPayment::query()
            ->with(['barber', 'registeredBy'])
            ->orderByDesc('paid_at')
            ->orderByDesc('id')
            ->paginate(15);

        $totalCommissions = (float) $barbers->sum(fn (User $barber) => (float) ($barber->total_commission ?? 0));
        $totalPaid = (float) $barbers->sum(fn (User $barber) => (float) ($barber->total_paid ?? 0));
        $totalSupplies = (float) $barbers->sum(fn (User $barber) => (float) ($barber->total_supplies ?? 0));
        $totalPending = (float) $barbers->sum(fn (User $barber) => max(0, (float) ($barber->total_commission ?? 0) + (float) ($barber->total_supplies ?? 0) - (float) ($barber->total_paid ?? 0)));

        // Detalle por barbero: lo ganado en cada servicio (ventas antiguas: % de la venta) y los insumos entregados.
        $earned = DB::table('sale_items')
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->select('sales.barber_id', 'sale_items.service_name',
                DB::raw('SUM(sale_items.quantity) as qty'),
                DB::raw('SUM(sale_items.subtotal) as sold'),
                DB::raw('SUM(CASE WHEN ABS((SELECT SUM(si2.commission_amount) FROM sale_items si2 WHERE si2.sale_id = sales.id) - sales.commission_amount) < 0.01 THEN sale_items.commission_amount WHEN sales.total > 0 THEN sale_items.subtotal / sales.total * sales.commission_amount ELSE 0 END) as earned'))
            ->groupBy('sales.barber_id', 'sale_items.service_name')
            ->orderByDesc('earned')
            ->get()
            ->groupBy('barber_id');

        $supplies = SupplyDelivery::query()
            ->select('barber_id', 'supply_name', DB::raw('SUM(quantity) as qty'), DB::raw('SUM(amount) as amount'))
            ->groupBy('barber_id', 'supply_name')
            ->orderByDesc('amount')
            ->get()
            ->groupBy('barber_id');

        return view('commissions.index', compact(
            'earned',
            'supplies',
            'barbers',
            'payments',
            'totalCommissions',
            'totalPaid',
            'totalSupplies',
            'totalPending',
        ));
    }

    /** Detalle de nómina de un barbero, filtrable por fechas. */
    public function show(Request $request, User $barber)
    {
        abort_unless($barber->role === 'barbero', 404);

        // "Todo": desde el primer servicio, insumo o pago del barbero hasta hoy (así cuadra con la tabla de Nómina).
        $firstDate = collect([
            DB::table('sales')->where('barber_id', $barber->id)->min('sold_at'),
            SupplyDelivery::where('barber_id', $barber->id)->min('delivered_at'),
            CommissionPayment::where('barber_id', $barber->id)->min('paid_at'),
        ])->filter()->map(fn ($d) => \Carbon\Carbon::parse($d))->min() ?? now();

        $presets = [
            'todo' => [$firstDate->copy()->startOfDay(), now()->endOfDay()],
            'hoy' => [now()->startOfDay(), now()->endOfDay()],
            'semana' => [now()->startOfWeek(), now()->endOfWeek()],
            'quincena' => now()->day <= 15 ? [now()->startOfMonth(), now()->startOfMonth()->addDays(14)->endOfDay()] : [now()->startOfMonth()->addDays(15), now()->endOfMonth()],
            'mes' => [now()->startOfMonth(), now()->endOfMonth()],
            'mes-pasado' => [now()->subMonthNoOverflow()->startOfMonth(), now()->subMonthNoOverflow()->endOfMonth()],
        ];
        $preset = $request->input('rango');
        if ($preset && isset($presets[$preset])) {
            [$from, $to] = $presets[$preset];
        } else {
            $request->validate(['desde' => ['nullable', 'date'], 'hasta' => ['nullable', 'date', 'after_or_equal:desde']]);
            if ($request->hasAny(['desde', 'hasta'])) {
                $from = $request->filled('desde') ? \Carbon\Carbon::parse($request->input('desde'))->startOfDay() : $presets['todo'][0];
                $to = $request->filled('hasta') ? \Carbon\Carbon::parse($request->input('hasta'))->endOfDay() : now()->endOfDay();
                $preset = null;
            } else {
                [$from, $to] = $presets['todo'];
                $preset = 'todo';
            }
        }

        $items = DB::table('sale_items')
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->where('sales.barber_id', $barber->id)
            ->whereBetween('sales.sold_at', [$from, $to])
            ->select('sales.id as sale_id', 'sales.sold_at', 'sales.client_name', 'sale_items.service_name', 'sale_items.quantity', 'sale_items.subtotal',
                // Lo ganado en cada servicio es su parte de la comisión de la venta: así el total cuadra exacto con Nómina.
                DB::raw('CASE WHEN sale_items.subtotal > 0 THEN (CASE WHEN ABS((SELECT SUM(si2.commission_amount) FROM sale_items si2 WHERE si2.sale_id = sales.id) - sales.commission_amount) < 0.01 THEN sale_items.commission_amount WHEN sales.total > 0 THEN sale_items.subtotal / sales.total * sales.commission_amount ELSE 0 END) / sale_items.subtotal * 100 ELSE 0 END as percentage'),
                DB::raw('CASE WHEN ABS((SELECT SUM(si2.commission_amount) FROM sale_items si2 WHERE si2.sale_id = sales.id) - sales.commission_amount) < 0.01 THEN sale_items.commission_amount WHEN sales.total > 0 THEN sale_items.subtotal / sales.total * sales.commission_amount ELSE 0 END as earned'))
            ->orderByDesc('sales.sold_at')
            ->get();

        $byService = $items->groupBy('service_name')->map(fn ($rows, $name) => (object) [
            'name' => $name, 'qty' => $rows->sum('quantity'), 'charged' => $rows->sum('subtotal'), 'earned' => $rows->sum('earned'),
        ])->sortByDesc('earned')->values();

        $supplies = SupplyDelivery::query()
            ->where('barber_id', $barber->id)
            ->whereBetween('delivered_at', [$from->toDateString(), $to->toDateString()])
            ->orderByDesc('delivered_at')
            ->get();

        $payments = CommissionPayment::query()
            ->where('barber_id', $barber->id)
            ->whereBetween('paid_at', [$from->toDateString(), $to->toDateString()])
            ->orderByDesc('paid_at')
            ->get();

        $earnedServices = round((float) $items->sum('earned'), 2);
        $earnedSupplies = round((float) $supplies->sum('amount'), 2);
        $paidInRange = round((float) $payments->sum('amount'), 2);

        return view('commissions.show', [
            'barber' => $barber,
            'from' => $from,
            'to' => $to,
            'preset' => $preset,
            'items' => $items,
            'byService' => $byService,
            'supplies' => $supplies,
            'payments' => $payments,
            'earnedServices' => $earnedServices,
            'earnedSupplies' => $earnedSupplies,
            'totalEarned' => round($earnedServices + $earnedSupplies, 2),
            'paidInRange' => $paidInRange,
            'pendingAll' => $barber->pendingCommission(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'barber_id' => ['required', 'exists:users,id'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'paid_at' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:300'],
        ]);

        $barber = User::findOrFail($data['barber_id']);
        abort_unless($barber->role === 'barbero', 422, 'El usuario seleccionado no es un barbero.');

        // Sin servicios realizados ni ingresos no hay pago; y nunca se paga más de lo que tiene pendiente.
        $pending = $barber->pendingCommission();
        if ($pending <= 0) {
            return back()->withInput()->withErrors(['amount' => $barber->name.' no tiene servicios realizados ni ingresos pendientes: no hay nada que pagarle.']);
        }
        if ((float) $data['amount'] > $pending + 0.009) {
            return back()->withInput()->withErrors(['amount' => 'El pago no puede ser mayor a lo que se le debe a '.$barber->name.' ('.format_money($pending).').']);
        }

        CommissionPayment::create([
            ...$data,
            'registered_by' => $request->user()->id,
        ]);

        return redirect()
            ->route('commissions.index')
            ->with('success', 'Pago de nómina registrado correctamente.');
    }

    public function destroy(CommissionPayment $commission)
    {
        $commission->delete();

        return redirect()
            ->route('commissions.index')
            ->with('success', 'Pago de nómina eliminado correctamente.');
    }
}
