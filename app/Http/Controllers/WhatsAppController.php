<?php

namespace App\Http\Controllers;

use App\Services\WhatsApp\EvolutionClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redirector;

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
            'webhookUrl' => route('webhook.evolution'),
        ]);
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
        $result = $this->evolution->connect();

        if ($result['state'] === 'open') {
            cache()->forget('evolution.connection_state');
        }

        return response()->json($result);
    }

    /** Desvincula el número para volver a escanear un QR nuevo. */
    public function logout(Redirector $redirect): Redirector
    {
        $ok = $this->evolution->logout();
        cache()->forget('evolution.connection_state');

        return $redirect
            ->route('whatsapp.index')
            ->with('success', $ok
                ? 'Número desvinculado. Escanea un QR nuevo cuando quieras.'
                : 'No se pudo desvincular. Revisa EVOLUTION_URL / EVOLUTION_APIKEY.');
    }

    /**
     * Registra este sitio como webhook en Evolution API: así los mensajes entrantes
     * llegan al bot y a la bandeja del panel sin tocar Northflank a mano.
     */
    public function configureWebhook(Redirector $redirect): Redirector
    {
        if (! $this->evolution->isConfigured()) {
            return $redirect->route('whatsapp.index')->with('error', 'Configura EVOLUTION_URL y EVOLUTION_APIKEY en el .env primero.');
        }

        $this->evolution->ensureInstance();

        $url = route('webhook.evolution');
        $ok = $this->evolution->setWebhook($url, enabled: true);

        if ($ok) {
            Log::info('Webhook de Evolution API configurado', ['url' => $url]);

            return $redirect->route('whatsapp.index')->with('success', 'Webhook conectado: los mensajes entrantes ya llegan a tu sitio.');
        }

        return $redirect->route('whatsapp.index')->with('error', 'Evolution API rechazó el webhook. Revisa los datos de conexión en el .env.');
    }
}
