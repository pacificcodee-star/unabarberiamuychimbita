<?php

namespace App\Http\Controllers;

use App\Models\Supply;
use App\Models\SupplyDelivery;
use App\Models\User;
use Illuminate\Http\Request;

/** Insumos del negocio: por cada insumo que el barbero usa/entrega gana un % de su valor, que se suma a su nómina. */
class SupplyController extends Controller
{
    public function index(Request $request)
    {
        $supplies = Supply::orderByDesc('active')->orderBy('name')->get();
        $barbers = User::barbers()->where('active', true)->with('supplyPercentages')->orderBy('name')->get();

        $deliveries = SupplyDelivery::query()
            ->with(['barber', 'registeredBy'])
            ->when($request->filled('barber'), fn ($q) => $q->where('barber_id', $request->integer('barber')))
            ->orderByDesc('delivered_at')
            ->orderByDesc('id')
            ->paginate(15)
            ->withQueryString();

        // % que se le descuenta a cada barbero por cada insumo, para calcular en vivo en el formulario.
        $rates = $barbers->mapWithKeys(fn (User $barber) => [
            $barber->id => $supplies->mapWithKeys(fn (Supply $supply) => [$supply->id => $barber->supplyPercentFor($supply)]),
        ]);

        return view('supplies.index', compact('supplies', 'barbers', 'deliveries', 'rates'));
    }

    public function create()
    {
        return view('supplies.form', ['supply' => null]);
    }

    public function store(Request $request)
    {
        Supply::create($this->validatedData($request) + ['active' => $request->boolean('active', true)]);

        return redirect()->route('supplies.index')->with('success', 'Insumo registrado.');
    }

    public function edit(Supply $supply)
    {
        return view('supplies.form', compact('supply'));
    }

    public function update(Request $request, Supply $supply)
    {
        $supply->update($this->validatedData($request) + ['active' => $request->boolean('active')]);

        return redirect()->route('supplies.index')->with('success', 'Insumo actualizado.');
    }

    public function destroy(Supply $supply)
    {
        if ($supply->deliveries()->exists()) {
            $supply->update(['active' => false]);

            return redirect()->route('supplies.index')->with('success', 'El insumo ya tiene entregas registradas, por eso se desactivó en lugar de eliminarse.');
        }

        $supply->delete();

        return redirect()->route('supplies.index')->with('success', 'Insumo eliminado.');
    }

    /** Registra que se le entregó un insumo a un barbero. */
    public function storeDelivery(Request $request)
    {
        $data = $request->validate([
            'barber_id' => ['required', 'exists:users,id'],
            'supply_id' => ['required', 'exists:supplies,id'],
            'quantity' => ['required', 'integer', 'min:1', 'max:1000'],
            'delivered_at' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:300'],
        ]);

        $barber = User::with('supplyPercentages')->findOrFail($data['barber_id']);
        abort_unless($barber->role === 'barbero', 422, 'El usuario seleccionado no es un barbero.');
        $supply = Supply::findOrFail($data['supply_id']);

        $percentage = $barber->supplyPercentFor($supply);
        $amount = round((float) $supply->cost * $data['quantity'] * $percentage / 100, 2);

        SupplyDelivery::create([
            'barber_id' => $barber->id,
            'supply_id' => $supply->id,
            'supply_name' => $supply->name,
            'quantity' => $data['quantity'],
            'unit_cost' => $supply->cost,
            'percentage' => $percentage,
            'amount' => $amount,
            'delivered_at' => $data['delivered_at'],
            'notes' => $data['notes'] ?? null,
            'registered_by' => $request->user()->id,
        ]);

        return redirect()->route('supplies.index')->with('success', 'Insumo registrado: '.$barber->name.' gana '.format_money($amount).' y se suma a su nómina.');
    }

    public function destroyDelivery(SupplyDelivery $delivery)
    {
        $delivery->delete();

        return redirect()->route('supplies.index')->with('success', 'Registro eliminado; ya no se suma a la nómina.');
    }

    private function validatedData(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'unit' => ['nullable', 'string', 'max:40'],
            'cost' => ['required', 'numeric', 'min:0'],
            'default_percentage' => ['required', 'numeric', 'min:0', 'max:100'],
            'notes' => ['nullable', 'string', 'max:500'],
        ], [
            'default_percentage.max' => 'El porcentaje debe estar entre 0 y 100.',
        ]);
    }
}
