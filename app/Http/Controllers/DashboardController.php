<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\CashSession;
use App\Models\Expense;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Turn;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $isAdmin = $user->isAdmin();
        $today = now()->toDateString();

        $salesQuery = Sale::query()->whereDate('sold_at', $today);
        if (! $isAdmin) {
            $salesQuery->where('barber_id', $user->id);
        }

        $salesTotal = (float) (clone $salesQuery)->sum('total');
        $salesCount = (clone $salesQuery)->count();
        $commissionsToday = (float) (clone $salesQuery)->sum('commission_amount');
        $servicesCount = (int) SaleItem::whereHas('sale', function ($query) use ($today, $isAdmin, $user) {
            $query->whereDate('sold_at', $today);
            if (! $isAdmin) {
                $query->where('barber_id', $user->id);
            }
        })->sum('quantity');

        $expensesToday = $isAdmin
            ? (float) Expense::whereDate('expense_date', $today)->sum('amount')
            : 0.0;

        $activeSession = CashSession::open()->latest('opened_at')->first();

        $appointmentsQuery = Appointment::query()->whereDate('appointment_date', $today);
        if (! $isAdmin) {
            $appointmentsQuery->where('barber_id', $user->id);
        }
        $appointmentsToday = (clone $appointmentsQuery)->count();
        $nextAppointments = (clone $appointmentsQuery)
            ->with(['barber', 'service'])
            ->orderBy('appointment_time')
            ->take(5)
            ->get();

        $isWalkIn = ! $isAdmin && $user->attention_mode === 'llegada';

        $turnsQuery = Turn::query()->whereDate('turn_date', $today);
        if (! $isAdmin) {
            $turnsQuery->where('barber_id', $user->id);
        }
        $turnsToday = (clone $turnsQuery)->count();
        $waitingTurns = (clone $turnsQuery)->where('status', 'esperando')->count();
        $nextTurns = (clone $turnsQuery)
            ->with(['barber', 'service'])
            ->whereIn('status', ['esperando', 'atendiendo'])
            ->orderBy('turn_number')
            ->take(5)
            ->get();

        $recentSales = (clone $salesQuery)
            ->with(['barber', 'items'])
            ->latest('sold_at')
            ->take(6)
            ->get();

        $chartSales = collect(range(6, 0))->map(function ($daysAgo) use ($isAdmin, $user) {
            $date = now()->subDays($daysAgo);

            $query = Sale::query()->whereDate('sold_at', $date->toDateString());
            if (! $isAdmin) {
                $query->where('barber_id', $user->id);
            }

            return [
                'label' => $date->format('d/m'),
                'total' => (float) $query->sum('total'),
            ];
        });

        $topServices = SaleItem::query()
            ->whereHas('sale', function ($query) use ($today, $isAdmin, $user) {
                $query->whereDate('sold_at', $today);
                if (! $isAdmin) {
                    $query->where('barber_id', $user->id);
                }
            })
            ->select('service_name', DB::raw('SUM(quantity) as quantity'), DB::raw('SUM(subtotal) as total'))
            ->groupBy('service_name')
            ->orderByDesc('quantity')
            ->take(5)
            ->get();

        $pendingCommissions = $isAdmin
            ? User::barbers()->get()->sum(fn (User $barber) => $barber->pendingCommission())
            : $user->pendingCommission();

        // Lo que ganan los barberos por insumos también es un costo para el negocio.
        $suppliesToday = $isAdmin ? (float) \App\Models\SupplyDelivery::whereDate('delivered_at', $today)->sum('amount') : 0.0;
        $profitToday = $isAdmin ? round($salesTotal - $expensesToday - $commissionsToday - $suppliesToday, 2) : null;

        return view('dashboard', compact(
            'isAdmin',
            'salesTotal',
            'salesCount',
            'commissionsToday',
            'servicesCount',
            'expensesToday',
            'profitToday',
            'suppliesToday',
            'activeSession',
            'appointmentsToday',
            'nextAppointments',
            'isWalkIn',
            'turnsToday',
            'waitingTurns',
            'nextTurns',
            'recentSales',
            'chartSales',
            'topServices',
            'pendingCommissions',
        ));
    }
}
