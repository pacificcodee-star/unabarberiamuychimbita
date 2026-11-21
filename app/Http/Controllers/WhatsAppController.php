<?php

namespace App\Http\Controllers;

use App\Models\WhatsAppConversation;
use App\Services\WhatsApp\EvolutionClient;
use App\Services\WhatsApp\WhatsAppInbox;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Redirector;
use Illuminate\Support\Facades\Log;

/**
 * Panel de WhatsApp (solo administrador): estado de la instancia de Evolution API,
 * QR de conexión, logout y alta del webhook hacia este sitio.
 */
class WhatsAppController extends Controller
{
    public function __construct(private readonly EvolutionClient $evolution)
    {
    }

    public function index()
    {
        $webhook = null;
        $state = null;

        if ($this->evolution->isConfigured()) {
            $state = $this->evolution->state();
            if ($state !== null) {
                $webhook = $this->evolution->findWebhook();
            }
        }

        return view('whatsapp.instance', [
            'configured' => $this->evolution->isConfigured(),
            'instance' => $this->evolution->instance(),
            'baseUrl' => $this->evolution->baseUrl(),
            'state' => $state,
            'webhook' => $webhook,
            'webhookUrl' => $this->webhookUrl(),
        ]);
    }

    /** URL del webhook: la interna configurada (EVOLUTION_WEBHOOK_URL) o la pública del sitio. */
    protected function webhookUrl(): string
    {
        return (string) (config('services.evolution.webhook_url') ?: route('webhook.evolution'));
    }

    /** Estado para el refresco automático del panel (JSON). */
    public function status(): JsonResponse
    {
        $state = $this->evolution->isConfigured() ? $this->evolution->state() : null;

        return response()->json([
            'configured' => $this->evolution->isConfigured(),
            'instance' => $this->evolution->instance(),
            'state' => $state,
            'connected' => $state === 'open',
            'cached' => $state === null ? cache('evolution.connection_state') : null,
        ]);
    }

    /**
     * Crea la instancia si hace falta y pide el QR de conexión.
     * El panel lo muestra y va consultando /whatsapp/status hasta que diga "open".
     */
    public function connect(): JsonResponse
    {
        if (! $this->evolution->isConfigured()) {
            return response()->json([
                'error' => 'Falta EVOLUTION_URL y EVOLUTION_APIKEY en el archivo .env.',
            ], 422);
        }

        $this->evolution->ensureInstance();
        // Si la instancia se creó de nuevo (o alguien cambió el webhook), se deja bien antes del QR:
        // sin webhook el número se vincula pero el bot nunca recibe los mensajes.
        $this->evolution->ensureWebhook($this->webhookUrl());
        $result = $this->evolution->connect();

        // Nunca se reinicia la instancia aquí: reiniciar cambia el QR que el cliente está escaneando y
        // WhatsApp rechaza la vinculación. Pedir el QR mientras está "connecting" devuelve el vigente,
        // así que solo se espera unos segundos a que Evolution lo genere.
        for ($i = 0; $i < 6 && $result['state'] !== 'open' && empty($result['qr']); $i++) {
            usleep(1_500_000);
            $result = $this->evolution->connect();
        }

        // Solo si la instancia de verdad no existe (la borró un cierre de sesión desde el teléfono, por ejemplo)
        // se crea de nuevo, limpia y con su webhook.
        if ($result['state'] !== 'open' && empty($result['qr']) && $this->evolution->state() === null) {
            Log::warning('Instancia de Evolution inexistente al pedir el QR: se crea de nuevo');
            $this->evolution->resetInstance($this->webhookUrl());
            $result = $this->evolution->connect();
        }

        if ($result['state'] === 'open') {
            cache()->forget('evolution.connection_state');
        }

        return response()->json($result);
    }

    /** Desvincula el número para volver a escanear un QR nuevo. */
    public function logout(Redirector $redirect): RedirectResponse
    {
        if (! $this->evolution->isConfigured()) {
            return $redirect->route('whatsapp.index')->with('error', 'Configura EVOLUTION_URL y EVOLUTION_APIKEY en el .env primero.');
        }

        $ok = $this->evolution->resetInstance($this->webhookUrl());
        cache()->forget('evolution.connection_state');

        if (! $ok) {
            return $redirect->route('whatsapp.index')
                ->with('error', 'No se pudo desvincular: Evolution API no respondió o no dejó borrar la sesión. Revisa que esté encendida e inténtalo de nuevo.');
        }

        Log::info('Número de WhatsApp desvinculado desde el panel');

        return $redirect
            ->route('whatsapp.index')
            ->with('success', 'Número desvinculado. Pulsa «Conectar / ver QR» para vincular el mismo u otro número.');
    }

    /**
     * Registra este sitio como webhook en Evolution API: así los mensajes entrantes
     * llegan al bot y a la bandeja del panel sin tocar Northflank a mano.
     */
    public function configureWebhook(Redirector $redirect): RedirectResponse
    {
        if (! $this->evolution->isConfigured()) {
            return $redirect->route('whatsapp.index')->with('error', 'Configura EVOLUTION_URL y EVOLUTION_APIKEY en el .env primero.');
        }

        $this->evolution->ensureInstance();

        $url = $this->webhookUrl();
        $ok = $this->evolution->setWebhook($url, enabled: true);

        if ($ok) {
            Log::info('Webhook de Evolution API configurado', ['url' => $url]);

            return $redirect->route('whatsapp.index')->with('success', 'Webhook conectado: los mensajes entrantes ya llegan a tu sitio.');
        }

        return $redirect->route('whatsapp.index')->with('error', 'Evolution API rechazó el webhook. Revisa los datos de conexión en el .env.');
    }

    /**
     * Envía una promoción a los clientes que escribieron en los últimos 30 días
     * (ventana de conversación de WhatsApp; máximo 50 números por envío).
     */
    public function sendPromotion(Request $request, WhatsAppInbox $inbox): RedirectResponse
    {
        $data = $request->validate([
            'message' => ['required', 'string', 'min:5', 'max:600'],
        ]);

        $phones = WhatsAppConversation::query()
            ->where('last_message_at', '>=', now()->subDays(30))
            ->orderByDesc('last_message_at')
            ->limit(50)
            ->pluck('phone');

        if ($phones->isEmpty()) {
            return back()->with('error', 'No hay clientes que hayan escrito en los últimos 30 días.');
        }

        $sent = 0;
        foreach ($phones as $phone) {
            if ($inbox->sendToPhone((string) $phone, $data['message'])) {
                $sent++;
            }
        }

        return back()->with($sent > 0 ? 'success' : 'error', $sent > 0
            ? "Promoción enviada a {$sent} de {$phones->count()} clientes de los últimos 30 días."
            : 'No se pudo enviar la promoción. Revisa la conexión de WhatsApp.');
    }
}
