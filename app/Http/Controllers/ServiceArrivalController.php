<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\Turn;
use App\Services\ServiceArrival;
use Illuminate\Http\Request;

class ServiceArrivalController extends Controller
{
    public function __invoke(Request $request, string $type, int $id, ServiceArrival $arrival)
    {
        $decision = $request->validate(['decision' => 'required|in:llego,no_llego'])['decision'];
        $subject = $type === 'turno' ? Turn::findOrFail($id) : Appointment::findOrFail($id);
        $arrival->decide($subject, $decision === 'llego');

        return back()->with('success', $decision === 'llego' ? 'Llegada confirmada. El servicio está en atención.' : 'Inasistencia registrada sin generar ingreso.');
    }
}
