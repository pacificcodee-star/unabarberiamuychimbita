<?php

namespace App\Http\Controllers;

use App\Models\Sale;
use App\Models\User;
use Illuminate\Http\Request;

class InvoiceController extends Controller
{
    public function index(Request $request)
    {
        $query = Sale::query()->with(['barber', 'items'])->orderByDesc('sold_at')->orderByDesc('id');

        if ($request->filled('from')) {
            $query->whereDate('sold_at', '>=', $request->input('from'));
        }

        if ($request->filled('to')) {
            $query->whereDate('sold_at', '<=', $request->input('to'));
        }

        if ($request->filled('barber_id')) {
            $query->where('barber_id', $request->input('barber_id'));
        }

        if ($request->filled('payment_method')) {
            $query->where('payment_method', $request->input('payment_method'));
        }

        if ($request->filled('q')) {
            $q = trim((string) $request->input('q'));
            $query->where(function ($sub) use ($q) {
                $sub->where('client_name', 'like', "%{$q}%");
                if (ctype_digit($q)) {
                    $sub->orWhere('id', (int) $q);
                }
            });
        }

        $invoices = $query->paginate(20)->withQueryString();
        $barbers = User::barbers()->orderBy('name')->get();

        $totals = [
            'total' => (float) (clone $query)->sum('total'),
            'count' => (clone $query)->count(),
        ];

        return view('invoices.index', compact('invoices', 'barbers', 'totals'));
    }

    public function show(Sale $invoice)
    {
        $invoice->load(['barber', 'items.service', 'cashSession']);

        return view('invoices.show', ['sale' => $invoice]);
    }
}
