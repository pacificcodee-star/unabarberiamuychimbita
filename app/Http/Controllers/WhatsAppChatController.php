<?php

namespace App\Http\Controllers;

use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use App\Services\WhatsApp\WhatsAppInbox;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Chat de WhatsApp del panel: bandeja de conversaciones y envío manual del equipo.
 * El bot automático queda pausable por conversación desde esta misma pantalla.
 */
class WhatsAppChatController extends Controller
{
    public function __construct(private readonly WhatsAppInbox $inbox)
    {
    }

    public function index(Request $request)
    {
        $search = trim((string) $request->query('q', ''));
        $digits = preg_replace('/\D+/', '', $search);

        $conversations = WhatsAppConversation::query()
            ->with('lastMessage')
            ->when($search !== '', function ($query) use ($search, $digits) {
                $query->where(function ($q) use ($search, $digits) {
                    $q->where('client_name', 'like', "%{$search}%");
                    if ($digits !== '') {
                        $q->orWhere('phone', 'like', "%{$digits}%");
                    }
                });
            })
            ->orderByRaw('COALESCE(last_message_at, created_at) DESC')
            ->paginate(20)
            ->withQueryString();

        return view('whatsapp.chat.index', [
            'conversations' => $conversations,
            'search' => $search,
            'unreadTotal' => WhatsAppConversation::unreadTotal(),
        ]);
    }

    public function show(WhatsAppConversation $conversation)
    {
        $conversation->load(['messages' => fn ($query) => $query->latest()->limit(200)])->load('lastMessage');
        $conversation->markAsRead();

        return view('whatsapp.chat.show', [
            'conversation' => $conversation,
            'messages' => $conversation->messages->sortBy('id')->values(),
        ]);
    }

    /** Refresco del hilo sin recargar la página (JSON). */
    public function messages(WhatsAppConversation $conversation, Request $request): JsonResponse
    {
        $after = (int) $request->query('after', 0);

        $messages = $conversation->messages()
            ->where('id', '>', $after)
            ->orderBy('id')
            ->get();

        if ($after > 0) {
            $conversation->markAsRead();
        }

        return response()->json([
            'messages' => $messages->map(fn (WhatsAppMessage $message) => [
                'id' => $message->id,
                'direction' => $message->direction,
                'body' => $message->body,
                'type' => $message->type,
                'status' => $message->statusLabel(),
                'sender_name' => $message->sender_name,
                'created_at' => $message->created_at?->format('d/m/Y H:i'),
            ]),
            'unread' => $conversation->fresh()?->unread_count ?? 0,
        ]);
    }

    /** Envía un mensaje del equipo (izquierda → derecha en el hilo). */
    public function send(Request $request, WhatsAppConversation $conversation): RedirectResponse
    {
        $validated = $request->validate([
            'body' => ['required', 'string', 'max:2000'],
        ], [
            'body.required' => 'Escribe un mensaje antes de enviarlo.',
            'body.max' => 'El mensaje no puede pasar de 2000 caracteres.',
        ]);

        $sent = $this->inbox->send($conversation, trim($validated['body']));

        return back()->with(
            $sent ? 'success' : 'error',
            $sent
                ? 'Mensaje enviado.'
                : 'No se pudo enviar. Revisa la conexión de WhatsApp (menú WhatsApp) y que Evolution API esté en línea.'
        );
    }

    /** Pausa o reanuda el bot automático para este chat (útil cuando responde el equipo). */
    public function toggleBot(WhatsAppConversation $conversation): RedirectResponse
    {
        $conversation->update(['bot_active' => ! $conversation->bot_active]);

        return back()->with(
            'success',
            $conversation->bot_active
                ? 'Bot automático activado para este chat.'
                : 'Bot automático pausado para este chat: ahora responde el equipo.'
        );
    }
}
