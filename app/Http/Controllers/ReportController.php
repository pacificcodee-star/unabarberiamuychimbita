<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\CommissionPayment;
use App\Models\Expense;
use App\Models\Sale;
use App\Models\SaleItem;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{
    public function index(Request $request)
    {
        // Por defecto se carga TODO: desde el primer movimiento registrado hasta hoy (igual que Ventas y Nómina).
        $firstDate = collect([
            Sale::min('sold_at'), Expense::min('expense_date'),
            \App\Models\SupplyDelivery::min('delivered_at'), CommissionPayment::min('paid_at'),
        ])->filter()->map(fn ($d) => Carbon::parse($d))->min();
        $allStart = ($firstDate ?? now()->startOfMonth())->toDateString();
        $start = $request->input('start', $allStart);
        $end = $request->input('end', now()->toDateString());

        $startDate = Carbon::parse($start)->startOfDay();
        $endDate = Carbon::parse($end)->endOfDay();

        $salesQuery = Sale::query()->whereBetween('sold_at', [$startDate, $endDate]);

        $totalSales = (float) (clone $salesQuery)->sum('total');
        $salesCount = (clone $salesQuery)->count();
        $totalCommissions = (float) (clone $salesQuery)->sum('commission_amount');

        $expensesQuery = Expense::query()->whereBetween('expense_date', [$startDate->toDateString(), $endDate->toDateString()]);
        $totalExpenses = (float) (clone $expensesQuery)->sum('amount');

        $totalPayouts = (float) CommissionPayment::query()
            ->whereBetween('paid_at', [$startDate->toDateString(), $endDate->toDateString()])
            ->sum('amount');

        // Lo que ganan los barberos por insumos en el período también es costo de nómina.
        $totalSupplies = (float) \App\Models\SupplyDelivery::query()
            ->whereBetween('delivered_at', [$startDate->toDateString(), $endDate->toDateString()])
            ->sum('amount');
        $profit = round($totalSales - $totalExpenses - $totalCommissions - $totalSupplies, 2);
        $averageTicket = $salesCount > 0 ? round($totalSales / $salesCount, 2) : 0;

        // Igual que en Nómina: lo pendiente de cada barbero (nunca negativo), sumado.
        $globalPending = round((float) \App\Models\User::barbers()->get()->sum(fn ($barber) => $barber->pendingCommission()), 2);

        $byService = SaleItem::query()
            ->whereHas('sale', fn ($query) => $query->whereBetween('sold_at', [$startDate, $endDate]))
            ->select('service_name', DB::raw('SUM(quantity) as quantity'), DB::raw('SUM(subtotal) as total'))
            ->groupBy('service_name')
            ->orderByDesc('total')
            ->get();

        // Por barbero: servicios realizados, valor cobrado, lo que ganó por servicios y por insumos (igual que Nómina).
        $salesByBarber = Sale::query()
            ->whereBetween('sold_at', [$startDate, $endDate])
            ->select('barber_id', DB::raw('COUNT(*) as sales_count'), DB::raw('SUM(total) as total'), DB::raw('SUM(commission_amount) as commission'))
            ->groupBy('barber_id')
            ->get()
            ->keyBy('barber_id');
        $qtyByBarber = SaleItem::query()
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->whereBetween('sales.sold_at', [$startDate, $endDate])
            ->groupBy('sales.barber_id')
            ->select('sales.barber_id', DB::raw('SUM(sale_items.quantity) as qty'))
            ->get()
            ->mapWithKeys(fn ($r) => [$r->barber_id => (int) $r->qty]);
        $suppliesByBarber = \App\Models\SupplyDelivery::query()
            ->whereBetween('delivered_at', [$startDate->toDateString(), $endDate->toDateString()])
            ->groupBy('barber_id')
            ->select('barber_id', DB::raw('SUM(amount) as total'))
            ->get()
            ->mapWithKeys(fn ($r) => [$r->barber_id => (float) $r->total]);
        $paidByBarber = CommissionPayment::query()
            ->whereBetween('paid_at', [$startDate->toDateString(), $endDate->toDateString()])
            ->groupBy('barber_id')
            ->select('barber_id', DB::raw('SUM(amount) as total'))
            ->get()
            ->mapWithKeys(fn ($r) => [$r->barber_id => (float) $r->total]);
        $byBarber = \App\Models\User::barbers()->orderBy('name')->get()
            ->map(fn ($barber) => (object) [
                'barber' => $barber,
                'sales_count' => (int) ($salesByBarber[$barber->id]->sales_count ?? 0),
                'services' => (int) ($qtyByBarber[$barber->id] ?? 0),
                'total' => (float) ($salesByBarber[$barber->id]->total ?? 0),
                'commission' => (float) ($salesByBarber[$barber->id]->commission ?? 0),
                'supplies' => (float) ($suppliesByBarber[$barber->id] ?? 0),
                'paid' => (float) ($paidByBarber[$barber->id] ?? 0),
            ])
            ->filter(fn ($row) => $row->total > 0 || $row->supplies > 0 || $row->paid > 0)
            ->sortByDesc('total')
            ->values();

        $byPayment = Sale::query()
            ->whereBetween('sold_at', [$startDate, $endDate])
            ->select('payment_method', DB::raw('COUNT(*) as sales_count'), DB::raw('SUM(total) as total'))
            ->groupBy('payment_method')
            ->orderByDesc('total')
            ->get();

        $byCategory = Expense::query()
            ->whereBetween('expense_date', [$startDate->toDateString(), $endDate->toDateString()])
            ->select('category', DB::raw('COUNT(*) as count'), DB::raw('SUM(amount) as total'))
            ->groupBy('category')
            ->orderByDesc('total')
            ->get();

        $dailySales = Sale::query()
            ->whereBetween('sold_at', [$startDate, $endDate])
            ->select(DB::raw('DATE(sold_at) as day'), DB::raw('SUM(total) as total'))
            ->groupBy('day')
            ->orderBy('day')
            ->get();

        $appointmentsCount = Appointment::query()
            ->whereBetween('appointment_date', [$startDate->toDateString(), $endDate->toDateString()])
            ->count();
        $turnsCount = \App\Models\Turn::query()
            ->whereBetween('turn_date', [$startDate->toDateString(), $endDate->toDateString()])
            ->count();
        $servicesCount = (int) $byService->sum('quantity');

        return view('reports.index', compact(
            'start',
            'allStart',
            'end',
            'totalSales',
            'salesCount',
            'totalCommissions',
            'totalSupplies',
            'totalExpenses',
            'totalPayouts',
            'profit',
            'averageTicket',
            'globalPending',
            'byService',
            'byBarber',
            'byPayment',
            'byCategory',
            'dailySales',
            'appointmentsCount',
            'turnsCount',
            'servicesCount',
        ));
    }
}
