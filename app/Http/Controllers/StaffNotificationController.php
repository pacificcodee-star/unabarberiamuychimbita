<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\ClientNotification;
use App\Models\StaffNotification;
use App\Models\Turn;
use Illuminate\Http\Request;

/** Campana del panel: avisos para el equipo e historial de avisos enviados a los clientes. */
class StaffNotificationController extends Controller
{
    /** Datos para la campana (se consulta cada 30 segundos). */
    public function feed(Request $request)
    {
        $user = $request->user();
        $seenAt = $user->notifications_seen_at;

        $items = StaffNotification::visibleTo($user)->latest('id')->limit(15)->get();
        $unread = StaffNotification::visibleTo($user)
            ->when($seenAt, fn ($query) => $query->where('created_at', '>', $seenAt))
            ->count();

        return response()->json([
            'unread' => $unread,
            'items' => $items->map(fn (StaffNotification $n) => [
                'id' => $n->id,
                'icon' => $n->icon(),
                'title' => $n->title,
                'body' => $n->body,
                'url' => $n->url,
                'ago' => $n->created_at->locale('es')->diffForHumans(),
                'unread' => ! $seenAt || $n->created_at->gt($seenAt),
            ]),
        ])->header('Cache-Control', 'no-store, private');
    }

    /** El usuario abrió la campana: el contador vuelve a cero. */
    public function seen(Request $request)
    {
        $request->user()->forceFill(['notifications_seen_at' => now()])->save();

        return response()->json(['ok' => true]);
    }

    public function index(Request $request)
    {
        $user = $request->user();
        $tab = $request->input('tab') === 'clientes' ? 'clientes' : 'equipo';

        $staff = $tab === 'equipo'
            ? StaffNotification::visibleTo($user)->with('barber')->latest('id')->paginate(30)->withQueryString()
            : null;

        $clients = $tab === 'clientes'
            ? ClientNotification::query()
                ->with('notifiable')
                ->when(! $user->isAdmin(), fn ($query) => $query->whereHasMorph(
                    'notifiable',
                    [Turn::class, Appointment::class],
                    fn ($subject) => $subject->where('barber_id', $user->id)
                ))
                ->where('type', '!=', 'push_enabled')
                ->latest('id')
                ->paginate(30)
                ->withQueryString()
            : null;

        if ($tab === 'equipo') {
            $user->forceFill(['notifications_seen_at' => now()])->save();
        }

        return view('staff-notifications.index', compact('tab', 'staff', 'clients'));
    }
}
