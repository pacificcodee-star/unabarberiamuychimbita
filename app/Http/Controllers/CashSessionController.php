<?php

namespace App\Http\Controllers;

use App\Models\CashSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CashSessionController extends Controller
{
    public function index()
    {
        $openSession = CashSession::open()->with('openedBy')->latest('opened_at')->first();
        $sessions = CashSession::query()
            ->with(['openedBy', 'closedBy'])
            ->orderByDesc('opened_at')
            ->paginate(10);

        return view('cash.index', compact('openSession', 'sessions'));
    }

    public function show(CashSession $cashSession)
    {
        $cashSession->load(['sales.barber', 'expenses.user', 'openedBy', 'closedBy']);
        $expected = $cashSession->status === 'abierta'
            ? $cashSession->calculateExpected()
            : (float) $cashSession->expected_amount;

        return view('cash.show', compact('cashSession', 'expected'));
    }

    public function open(Request $request)
    {
        $data = $request->validate([
            'initial_amount' => ['required', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:300'],
        ]);

        // La barbería tiene una sola caja: si ya está abierta (por cualquier usuario), no se abre otra.
        $cashSession = DB::transaction(function () use ($request, $data) {
            if (CashSession::open()->lockForUpdate()->exists()) {
                return null;
            }

            return CashSession::create([
                'opened_by' => $request->user()->id,
                'initial_amount' => $data['initial_amount'],
                'notes' => $data['notes'] ?? null,
                'status' => 'abierta',
                'opened_at' => now(),
            ]);
        });

        if (! $cashSession) {
            return redirect()->route('cash.index')->with('error', 'La caja ya está abierta. Solo hay una caja para toda la barbería.');
        }

        return redirect()
            ->route('cash.show', $cashSession)
            ->with('success', 'Caja abierta correctamente.');
    }

    public function close(Request $request, CashSession $cashSession)
    {
        abort_unless($cashSession->status === 'abierta', 422, 'Esta caja ya está cerrada.');

        $data = $request->validate([
            'counted_amount' => ['required', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $expected = $cashSession->calculateExpected();
        $difference = round((float) $data['counted_amount'] - $expected, 2);

        $cashSession->update([
            'closed_by' => $request->user()->id,
            'expected_amount' => $expected,
            'counted_amount' => $data['counted_amount'],
            'difference' => $difference,
            'notes' => trim(($cashSession->notes ? $cashSession->notes."\n" : '').($data['notes'] ?? '')),
            'status' => 'cerrada',
            'closed_at' => now(),
        ]);

        return redirect()
            ->route('cash.show', $cashSession)
            ->with('success', 'Cierre de caja realizado correctamente.');
    }
}
