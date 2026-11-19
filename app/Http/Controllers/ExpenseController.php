<?php

namespace App\Http\Controllers;

use App\Models\CashSession;
use App\Models\Expense;
use Illuminate\Http\Request;

class ExpenseController extends Controller
{
    public function index(Request $request)
    {
        $query = Expense::query()->with('user')->orderByDesc('expense_date')->orderByDesc('id');

        if ($request->filled('from')) {
            $query->whereDate('expense_date', '>=', $request->input('from'));
        }

        if ($request->filled('to')) {
            $query->whereDate('expense_date', '<=', $request->input('to'));
        }

        if ($request->filled('category')) {
            $query->where('category', $request->input('category'));
        }

        $total = (float) (clone $query)->sum('amount');
        $expenses = $query->paginate(15)->withQueryString();

        return view('expenses.index', compact('expenses', 'total'));
    }

    public function create()
    {
        return view('expenses.create');
    }

    public function store(Request $request)
    {
        $data = $this->validatedData($request);
        $activeSession = CashSession::open()->latest('opened_at')->first();

        Expense::create([
            ...$data,
            'user_id' => $request->user()->id,
            'cash_session_id' => $activeSession?->id,
        ]);

        return redirect()
            ->route('expenses.index')
            ->with('success', 'Gasto registrado correctamente.');
    }

    public function edit(Expense $expense)
    {
        return view('expenses.edit', compact('expense'));
    }

    public function update(Request $request, Expense $expense)
    {
        $expense->update($this->validatedData($request));

        return redirect()
            ->route('expenses.index')
            ->with('success', 'Gasto actualizado correctamente.');
    }

    public function destroy(Expense $expense)
    {
        $expense->delete();

        return redirect()
            ->route('expenses.index')
            ->with('success', 'Gasto eliminado correctamente.');
    }

    private function validatedData(Request $request): array
    {
        return $request->validate([
            'category' => ['required', 'in:'.implode(',', array_keys(Expense::CATEGORIES))],
            'description' => ['required', 'string', 'max:160'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'expense_date' => ['required', 'date'],
        ]);
    }
}
