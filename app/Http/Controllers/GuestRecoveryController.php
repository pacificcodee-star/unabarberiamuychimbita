<?php

namespace App\Http\Controllers;

use App\Services\GuestRecoveryService;
use App\Support\ClientServiceAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class GuestRecoveryController extends Controller
{
    public function show(GuestRecoveryService $recovery): View
    {
        return view('public.recover-services', [
            'step' => 'phone',
            'available' => $recovery->isAvailable(),
        ]);
    }

    public function send(Request $request, GuestRecoveryService $recovery): RedirectResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'max:30'],
            'consent' => ['accepted'],
        ], [
            'consent.accepted' => 'Confirma que el número es tuyo y acepta la política de privacidad.',
        ]);

        $recovery->request($data['phone'], $request->ip());

        $request->session()->put('guest_recovery_phone', $data['phone']);

        return redirect()->route('my-services.recover.verify');
    }

    public function verifyForm(Request $request): View|RedirectResponse
    {
        $phone = $request->session()->get('guest_recovery_phone');
        if (! $phone) {
            return redirect()->route('my-services.recover');
        }

        return view('public.recover-services', [
            'step' => 'code',
            'phone' => $phone,
            'available' => true,
        ]);
    }

    public function verify(Request $request, GuestRecoveryService $recovery): RedirectResponse
    {
        $phone = $request->session()->get('guest_recovery_phone');
        if (! $phone) {
            return redirect()->route('my-services.recover');
        }

        $data = $request->validate([
            'code' => ['required', 'string', 'max:12'],
        ]);

        $visitor = $recovery->verify($phone, $data['code']);

        ClientServiceAccess::issueCredential($request, $visitor);
        $request->session()->regenerate();
        $request->session()->forget('guest_recovery_phone');

        return redirect()->route('my-services.index')
            ->with('status', 'Listo. Recuperamos los servicios de este número en este dispositivo.');
    }
}
