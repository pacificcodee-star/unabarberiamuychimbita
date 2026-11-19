<?php

namespace App\Http\Controllers;

use App\Models\CashSession;
use App\Models\PosProduct;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Service;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SaleController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $query = Sale::query()->with(['barber', 'items'])->orderByDesc('sold_at');

        if (! $user->isAdmin()) {
            $query->where('barber_id', $user->id);
        }

        if ($request->filled('from')) {
            $query->whereDate('sold_at', '>=', $request->input('from'));
        }

        if ($request->filled('to')) {
            $query->whereDate('sold_at', '<=', $request->input('to'));
        }

        if ($user->isAdmin() && $request->filled('barber_id')) {
            $query->where('barber_id', $request->input('barber_id'));
        }

        if ($request->filled('payment_method')) {
            $query->where('payment_method', $request->input('payment_method'));
        }

        $totals = [
            'total' => (float) (clone $query)->sum('total'),
            'commission' => (float) (clone $query)->sum('commission_amount'),
            'count' => (clone $query)->count(),
            // Servicios realizados (cantidad), igual que en Informes.
            'services' => (int) \App\Models\SaleItem::whereIn('sale_id', (clone $query)->reorder()->select('id'))->sum('quantity'),
        ];

        $sales = $query->paginate(15)->withQueryString();
        $barbers = $user->isAdmin()
            ? User::barbers()->orderBy('name')->get()
            : collect();

        return view('sales.index', compact('sales', 'totals', 'barbers'));
    }

    public function create(Request $request)
    {
        $user = $request->user();
        $barbers = $user->isAdmin()
            ? User::barbers()->where('active', true)->orderBy('name')->get()
            : collect([$user]);

        $services = Service::active()->orderBy('name')->get();

        return view('sales.create', compact('barbers', 'services'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'barber_id' => ['required', 'exists:users,id'],
            'client_name' => ['nullable', 'string', 'max:120'],
            'payment_method' => ['required', 'in:'.implode(',', array_keys(Sale::PAYMENT_METHODS))],
            'sold_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:500'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.service_id' => ['required', 'exists:services,id'],
            'items.*.price' => ['required', 'numeric', 'min:0'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:20'],
        ]);

        $barber = User::findOrFail($data['barber_id']);
        abort_unless($barber->role === 'barbero', 422, 'El usuario seleccionado no es un barbero.');

        if (! $request->user()->isAdmin()) {
            abort_unless($barber->id === $request->user()->id, 403);
        }

        $soldAt = $data['sold_at'] ? Carbon::parse($data['sold_at']) : now();
        $activeSession = CashSession::open()->latest('opened_at')->first();

        $sale = DB::transaction(function () use ($data, $barber, $soldAt, $activeSession) {
            $total = 0;
            $commission = 0;
            $items = [];
            $barber->load('serviceCommissions');

            foreach ($data['items'] as $row) {
                $service = Service::findOrFail($row['service_id']);
                $quantity = (int) $row['quantity'];
                $price = round((float) $row['price'], 2);
                $subtotal = round($price * $quantity, 2);
                $total += $subtotal;
                // % que gana el barbero en ESTE servicio (o su % general si el admin no le puso uno propio).
                $percent = $barber->commissionPercentFor($service->id);
                $itemCommission = round($subtotal * $percent / 100, 2);
                $commission += $itemCommission;

                $items[] = [
                    'service_id' => $service->id,
                    'service_name' => $service->name,
                    'price' => $price,
                    'quantity' => $quantity,
                    'subtotal' => $subtotal,
                    'commission_percentage' => $percent,
                    'commission_amount' => $itemCommission,
                ];
            }

            $total = round($total, 2);
            $commission = round($commission, 2);

            $sale = Sale::create([
                'barber_id' => $barber->id,
                'cash_session_id' => $activeSession?->id,
                'client_name' => $data['client_name'] ?? null,
                'payment_method' => $data['payment_method'],
                'total' => $total,
                'commission_percentage' => $total > 0 ? round($commission / $total * 100, 2) : 0,
                'commission_amount' => $commission,
                'notes' => $data['notes'] ?? null,
                'sold_at' => $soldAt,
            ]);

            $sale->items()->createMany($items);

            return $sale;
        });

        return redirect()
            ->route('sales.show', $sale)
            ->with('success', 'Venta registrada correctamente. La comisión fue calculada de forma automática.');
    }

    public function show(Request $request, Sale $sale)
    {
        $this->authorizeSale($request, $sale);
        $sale->load(['barber', 'items.service', 'cashSession']);

        return view('sales.show', compact('sale'));
    }

    public function receipt(Request $request, Sale $sale)
    {
        $this->authorizeSale($request, $sale);
        $sale->load(['barber', 'items.service']);

        return view('sales.receipt', compact('sale'));
    }

    public function destroy(Request $request, Sale $sale)
    {
        abort_unless($request->user()->isAdmin(), 403);

        DB::transaction(function () use ($sale) {
            $sale->load('items');
            foreach ($sale->items as $item) {
                if ($item->pos_product_id) {
                    $restore = max(0, (int) $item->quantity - (int) $item->returned_quantity);
                    if ($restore > 0) {
                        PosProduct::query()->whereKey($item->pos_product_id)->lockForUpdate()->increment('stock', $restore);
                    }
                }
            }
            $sale->delete();
        });

        return redirect()
            ->route('sales.index')
            ->with('success', 'Venta/comprobante eliminado. El stock de productos POS fue restaurado.');
    }

    public function returnItem(Request $request, Sale $sale, SaleItem $item)
    {
        abort_unless($request->user()->isAdmin(), 403);
        abort_unless($item->sale_id === $sale->id, 404);
        if (! $item->pos_product_id) {
            return back()->with('error', 'Solo se pueden devolver productos POS.');
        }

        $data = $request->validate([
            'quantity' => ['required', 'integer', 'min:1'],
        ]);

        try {
            DB::transaction(function () use ($item, $data) {
                $item = SaleItem::query()->lockForUpdate()->findOrFail($item->id);
                $product = PosProduct::query()->lockForUpdate()->findOrFail($item->pos_product_id);
                $qty = (int) $data['quantity'];
                $available = max(0, (int) $item->quantity - (int) $item->returned_quantity);
                if ($qty > $available) {
                    throw ValidationException::withMessages(['quantity' => 'La cantidad a devolver supera lo disponible en esta venta.']);
                }
                $item->increment('returned_quantity', $qty);
                $product->increment('stock', $qty);
            });
        } catch (ValidationException $e) {
            throw $e;
        }

        return back()->with('success', 'Producto devuelto y stock actualizado correctamente.');
    }

    private function authorizeSale(Request $request, Sale $sale): void
    {
        $user = $request->user();
        abort_unless($user->isAdmin() || $sale->barber_id === $user->id, 403);
    }
}
