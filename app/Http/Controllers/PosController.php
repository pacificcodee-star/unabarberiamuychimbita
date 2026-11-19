<?php

namespace App\Http\Controllers;

use App\Models\CashSession;
use App\Models\PosProduct;
use App\Models\Sale;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PosController extends Controller
{
    public function index()
    {
        $products = PosProduct::query()->where('active', true)->orderBy('name')->get();

        return view('pos.index', compact('products'));
    }

    public function history(Request $request)
    {
        $from = $request->filled('from') ? Carbon::parse($request->input('from'))->toDateString() : null;
        $to = $request->filled('to') ? Carbon::parse($request->input('to'))->toDateString() : null;
        $paymentMethod = $request->input('payment_method');
        $q = trim((string) $request->input('q', ''));

        $base = Sale::query()
            ->whereNull('barber_id')
            ->whereHas('items', fn ($query) => $query->whereNotNull('pos_product_id'))
            ->when($from, fn ($query) => $query->whereDate('sold_at', '>=', $from))
            ->when($to, fn ($query) => $query->whereDate('sold_at', '<=', $to))
            ->when($paymentMethod, fn ($query) => $query->where('payment_method', $paymentMethod))
            ->when($q !== '', function ($query) use ($q) {
                $query->where(function ($sub) use ($q) {
                    $sub->where('client_name', 'like', "%{$q}%");
                    if (ctype_digit($q)) {
                        $sub->orWhere('id', (int) $q);
                    }
                });
            });

        $totals = [
            'count' => (clone $base)->count(),
            'amount' => (float) (clone $base)->sum('total'),
        ];
        $totals['average'] = $totals['count'] > 0 ? round($totals['amount'] / $totals['count'], 2) : 0;

        $dailySales = (clone $base)
            ->select(DB::raw('DATE(sold_at) as day'), DB::raw('SUM(total) as total'), DB::raw('COUNT(*) as count'))
            ->groupBy('day')
            ->orderBy('day')
            ->get();

        $byPayment = (clone $base)
            ->select('payment_method', DB::raw('COUNT(*) as count'), DB::raw('SUM(total) as total'))
            ->groupBy('payment_method')
            ->orderByDesc('total')
            ->get();

        $sales = (clone $base)
            ->with(['items'])
            ->orderByDesc('sold_at')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        return view('pos.history', compact('sales', 'totals', 'dailySales', 'byPayment', 'from', 'to', 'paymentMethod', 'q'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'client_name' => ['nullable', 'string', 'max:120'],
            'payment_method' => ['required', 'in:'.implode(',', array_keys(Sale::PAYMENT_METHODS))],
            'sold_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:500'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'exists:pos_products,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:9999'],
            'items.*.price' => ['nullable', 'numeric', 'min:0'],
        ]);

        $soldAt = !empty($data['sold_at']) ? Carbon::parse($data['sold_at']) : now();
        $activeSession = CashSession::open()->latest('opened_at')->first();

        DB::transaction(function () use ($data, $soldAt, $activeSession) {
            $total = 0;
            $items = [];

            foreach ($data['items'] as $row) {
                $product = PosProduct::query()->lockForUpdate()->findOrFail($row['product_id']);
                $quantity = (int) $row['quantity'];
                $price = round((float) ($row['price'] ?? $product->sale_price), 2);

                if (! $product->active) {
                    throw ValidationException::withMessages(['items' => "El producto {$product->name} no está activo."]);
                }
                if ($product->stock < $quantity) {
                    throw ValidationException::withMessages(['items' => "Stock insuficiente para {$product->name}. Disponible: {$product->stock}."]);
                }

                $subtotal = round($price * $quantity, 2);
                $total += $subtotal;

                $product->decrement('stock', $quantity);

                $items[] = [
                    'service_id' => null,
                    'pos_product_id' => $product->id,
                    'service_name' => $product->name,
                    'price' => $price,
                    'quantity' => $quantity,
                    'returned_quantity' => 0,
                    'subtotal' => $subtotal,
                    'commission_percentage' => 0,
                    'commission_amount' => 0,
                ];
            }

            $sale = Sale::create([
                'barber_id' => null,
                'cash_session_id' => $activeSession?->id,
                'client_name' => $data['client_name'] ?? null,
                'payment_method' => $data['payment_method'],
                'total' => round($total, 2),
                'commission_percentage' => 0,
                'commission_amount' => 0,
                'notes' => $data['notes'] ?? null,
                'sold_at' => $soldAt,
            ]);

            $sale->items()->createMany($items);
        });

        return redirect()->route('pos.index')->with('success', 'Venta POS registrada, stock actualizado e ingreso sumado al negocio.');
    }

    public function inventory(Request $request)
    {
        $q = trim((string) $request->input('q', ''));
        $products = PosProduct::query()
            ->when($q !== '', fn ($query) => $query->where(function ($sub) use ($q) {
                $sub->where('name', 'like', "%{$q}%")->orWhere('reference', 'like', "%{$q}%");
            }))
            ->orderBy('name')
            ->get();

        $stats = [
            'count' => PosProduct::count(),
            'stock' => (int) PosProduct::sum('stock'),
            'low' => PosProduct::whereNotNull('min_stock')->whereColumn('stock', '<=', 'min_stock')->count(),
        ];

        $categories = PosProduct::query()->whereNotNull('category')->where('category', '!=', '')->distinct()->orderBy('category')->pluck('category');

        return view('pos.inventory', compact('products', 'stats', 'q', 'categories'));
    }

    public function storeProduct(Request $request)
    {
        $data = $this->validatedProduct($request);
        PosProduct::create($data);

        return redirect()->route('pos.inventory')->with('success', 'Producto creado correctamente.');
    }

    public function updateProduct(Request $request, PosProduct $product)
    {
        $data = $this->validatedProduct($request);
        $product->update($data);

        return redirect()->route('pos.inventory')->with('success', 'Producto actualizado correctamente.');
    }

    public function destroyProduct(PosProduct $product)
    {
        $product->delete();

        return redirect()->route('pos.inventory')->with('success', 'Producto eliminado correctamente.');
    }

    public function adjustStock(Request $request, PosProduct $product)
    {
        $data = $request->validate([
            'adjustment' => ['required', 'integer', 'not_in:0'],
        ]);

        $newStock = (int) $product->stock + (int) $data['adjustment'];
        if ($newStock < 0) {
            return back()->with('error', 'El ajuste dejaría el stock en negativo.');
        }

        $product->update(['stock' => $newStock]);

        return redirect()->route('pos.inventory')->with('success', 'Stock actualizado correctamente.');
    }

    private function validatedProduct(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'reference' => ['nullable', 'string', 'max:50'],
            'category' => ['nullable', 'string', 'max:60'],
            'sale_price' => ['required', 'numeric', 'min:0'],
            'cost_price' => ['nullable', 'numeric', 'min:0'],
            'stock' => ['required', 'integer', 'min:0'],
            'min_stock' => ['nullable', 'integer', 'min:0'],
            'expiry_date' => ['nullable', 'date'],
            'active' => ['nullable', 'boolean'],
        ]);
        $data['active'] = $request->boolean('active');

        return $data;
    }
}
