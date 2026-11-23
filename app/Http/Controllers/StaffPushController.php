<?php

namespace App\Http\Controllers;

use App\Models\PushSubscription;
use App\Services\PushSender;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class StaffPushController extends Controller
{
    private const PUSH_HOSTS = [
        'fcm.googleapis.com',
        'updates.push.services.mozilla.com',
        'web.push.apple.com',
        '.push.apple.com',
        '.notify.windows.com',
        '.push.services.mozilla.com',
    ];

    public function store(Request $request)
    {
        $data = $request->validate([
            'endpoint' => ['required', 'url', 'max:2000'],
            'keys' => ['required', 'array'],
            'keys.p256dh' => ['required', 'string', 'max:255'],
            'keys.auth' => ['required', 'string', 'max:255'],
            'contentEncoding' => ['nullable', 'in:aes128gcm,aesgcm'],
        ]);

        $host = strtolower((string) parse_url($data['endpoint'], PHP_URL_HOST));
        $allowed = collect(self::PUSH_HOSTS)->contains(fn (string $pattern) => str_starts_with($pattern, '.')
            ? str_ends_with($host, $pattern) && $host !== substr($pattern, 1)
            : $host === $pattern);
        if (! $allowed || parse_url($data['endpoint'], PHP_URL_SCHEME) !== 'https') {
            return response()->json(['message' => 'El servicio de avisos del navegador no es válido.'], 422);
        }

        $user = $request->user();
        $hash = PushSubscription::hashEndpoint($data['endpoint']);
        $existing = $user->pushSubscriptions()->where('endpoint_hash', $hash)->exists();
        if (! $existing && $user->pushSubscriptions()->count() >= 10) {
            return response()->json(['message' => 'Este usuario ya tiene 10 dispositivos registrados.'], 422);
        }

        $subscription = $user->pushSubscriptions()->updateOrCreate(
            ['endpoint_hash' => $hash],
            [
                'endpoint' => $data['endpoint'],
                'p256dh' => $data['keys']['p256dh'],
                'auth' => $data['keys']['auth'],
                'content_encoding' => $data['contentEncoding'] ?? 'aes128gcm',
                'user_agent' => Str::limit((string) $request->userAgent(), 250, ''),
            ]
        );

        if (! $existing) {
            $result = app(PushSender::class)->send($subscription, [
                'title' => 'Avisos del equipo activados',
                'body' => 'Este dispositivo recibirá notificaciones de reservas y cambios de turno.',
                'url' => '/dashboard',
                'tag' => 'staff-push-enabled-'.$user->id,
            ], 60);
            if ($result['gone']) $subscription->delete();
        }

        return response()->json(['ok' => true]);
    }

    /** "Probar" en la campana: manda un aviso real a todos los dispositivos de esta persona. */
    public function test(Request $request, PushSender $sender)
    {
        $subscriptions = $request->user()->pushSubscriptions()->get();
        if ($subscriptions->isEmpty()) {
            return response()->json(['message' => 'Este usuario todavía no tiene dispositivos con avisos activos. Toca «Activar».'], 422);
        }
        if (! $sender->isConfigured()) {
            return response()->json(['message' => 'El servidor no tiene configuradas las claves de avisos (VAPID).'], 503);
        }

        $sent = 0;
        $errors = [];
        foreach ($subscriptions as $subscription) {
            $result = $sender->send($subscription, [
                'title' => 'Prueba de avisos',
                'body' => 'Si escuchaste el sonido, así te llegarán las reservas y los cambios de turno.',
                'url' => '/notificaciones',
                'tag' => 'staff-test-'.$subscription->id.'-'.now()->timestamp,
            ], 120);

            if ($result['ok']) {
                $sent++;
                $subscription->forceFill(['last_success_at' => now()])->save();
            } elseif ($result['gone']) {
                $subscription->delete();
            } else {
                $errors[] = $result['error'];
            }
        }

        $total = $subscriptions->count();
        $message = $sent > 0
            ? 'Aviso enviado a '.$sent.' '.($sent === 1 ? 'dispositivo' : 'dispositivos').'. Debe aparecer en unos segundos con sonido.'
            : 'No se pudo entregar el aviso. Vuelve a activar los avisos en este dispositivo.';

        return response()->json(['devices' => $total, 'sent' => $sent, 'errors' => $errors, 'message' => $message], $sent > 0 ? 200 : 502);
    }

    public function destroy(Request $request)
    {
        $endpoint = $request->validate(['endpoint' => ['required', 'url', 'max:2000']])['endpoint'];
        $request->user()->pushSubscriptions()
            ->where('endpoint_hash', PushSubscription::hashEndpoint($endpoint))
            ->delete();

        return response()->json(['ok' => true]);
    }
}
