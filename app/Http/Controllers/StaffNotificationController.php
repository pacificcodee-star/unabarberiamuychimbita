<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\ClientNotification;
use App\Models\StaffNotification;
use App\Models\Turn;
use App\Models\User;
use Illuminate\Http\Request;

/** Campana del panel: avisos para el equipo e historial de avisos enviados a los clientes. */
class StaffNotificationController extends Controller
{
    /** Datos para la campana (se consulta cada 30 segundos y al instante cuando llega un push). */
    public function feed(Request $request)
    {
        $user = $request->user();

        // Orden de llegada (número de aviso): no depende de la hora guardada.
        $items = StaffNotification::visibleTo($user)->latest('id')->limit(15)->get();
        $unread = StaffNotification::visibleTo($user)
            ->when($user->notifications_seen_id, fn ($query) => $query->where('id', '>', $user->notifications_seen_id))
            ->count();

        return response()->json([
            'unread' => $unread,
            'total' => StaffNotification::visibleTo($user)->count(),
            'items' => $items->map(fn (StaffNotification $n) => [
                'id' => $n->id,
                'icon' => $n->icon(),
                'tone' => $n->tone(),
                'title' => $n->title,
                'body' => $n->body,
                'url' => $n->url,
                'day' => $n->dayLabel(),
                'time' => $n->created_at->format('g:i a'),
                'ago' => $n->created_at->locale('es')->diffForHumans(),
                'unread' => $n->isUnreadFor($user),
            ]),
        ])->header('Cache-Control', 'no-store, private');
    }

    /**
     * "Eliminar todas": vacía la bandeja de esta persona. No borra filas porque los avisos se comparten
     * (el administrador y el barbero ven los mismos); los demás conservan los suyos.
     */
    public function clear(Request $request)
    {
        $last = (int) StaffNotification::max('id');
        $request->user()->forceFill([
            'notifications_cleared_id' => $last,
            'notifications_seen_id' => $last,
            'notifications_cleared_at' => now(),
            'notifications_seen_at' => now(),
        ])->save();

        if ($request->expectsJson()) {
            return response()->json(['ok' => true]);
        }

        return redirect()->route('staff-notifications.index')->with('success', 'Se eliminaron todas tus notificaciones.');
    }

    /** El usuario abrió la campana: el contador vuelve a cero. */
    public function seen(Request $request)
    {
        $this->markSeen($request->user());

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
            $this->markSeen($user);
        }

        return view('staff-notifications.index', compact('tab', 'staff', 'clients'));
    }

    private function markSeen(User $user): void
    {
        $user->forceFill([
            'notifications_seen_id' => (int) StaffNotification::max('id'),
            'notifications_seen_at' => now(),
        ])->save();
    }
}
