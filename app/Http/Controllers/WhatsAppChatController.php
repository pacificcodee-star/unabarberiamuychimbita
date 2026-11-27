<?php

namespace App\Http\Controllers;

use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use App\Services\WhatsApp\WhatsappBotService;
use App\Services\WhatsApp\WhatsAppInbox;
use App\Services\WhatsApp\WhatsAppLive;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Chat de WhatsApp del panel: bandeja de conversaciones y envío manual del equipo.
 * El bot automático queda pausable por conversación desde esta misma pantalla.
 */
class WhatsAppChatController extends Controller
{
    public function __construct(private readonly WhatsAppInbox $inbox) {}

    public function index(Request $request)
    {
        $search = trim((string) $request->query('q', ''));
        $digits = preg_replace('/\D+/', '', $search);
        $username = mb_strtolower(ltrim($search, '@'), 'UTF-8');

        $conversations = WhatsAppConversation::query()
            ->with('lastMessage')
            ->when($search !== '', function ($query) use ($search, $digits, $username) {
                $query->where(function ($q) use ($search, $digits, $username) {
                    $q->where('client_name', 'like', "%{$search}%");
                    if ($digits !== '') {
                        $q->orWhere('phone', 'like', "%{$digits}%");
                    }
                    if ($username !== '') {
                        $q->orWhereRaw('LOWER(username) LIKE ?', ['%'.$username.'%']);
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

    public function show(Request $request, string $conversation)
    {
        $chat = ctype_digit($conversation) ? WhatsAppConversation::query()->find($conversation) : null;

        if (! $chat) {
            abort_if($request->expectsJson(), 404);

            return redirect()->route('whatsapp.chat.index');
        }

        $chat->load(['messages' => fn ($query) => $query->latest('id')->limit(200)])->load('lastMessage');
        $chat->markAsRead();

        return view('whatsapp.chat.show', [
            'conversation' => $chat,
            'messages' => $chat->messages->sortBy('id')->values(),
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

        $conversation->refresh();
        $receipts = $conversation->messages()->where('direction', WhatsAppMessage::DIRECTION_OUT)
            ->latest('id')->limit(30)->get(['id', 'status']);

        return response()->json([
            'messages' => $messages->map(fn (WhatsAppMessage $message) => [
                'id' => $message->id,
                'direction' => $message->direction,
                'body' => $message->body,
                'type' => $message->type,
                'status' => $message->statusLabel(),
                'sender_name' => $message->sender_name,
                'created_at' => $message->created_at?->format('d/m/Y H:i'),
                'status_key' => $message->status,
                'time' => $message->created_at?->format('H:i'),
                'day' => $message->created_at?->format('d/m/Y'),
            ]),
            'unread' => $conversation->unread_count,
            'receipts' => $receipts->map(fn (WhatsAppMessage $message) => [
                'id' => $message->id, 'status' => $message->statusLabel(), 'status_key' => $message->status,
            ]),
            'bot_active' => $conversation->bot_active,
            'advisor_wait_until' => $conversation->advisor_wait_until?->toIso8601String(),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function authorizeLive(Request $request, WhatsAppConversation $conversation, WhatsAppLive $live): JsonResponse
    {
        abort_unless(config('reverb.chat_enabled'), 404);
        abort_unless($request->user()->active, 403);

        $validated = $request->validate([
            'socket_id' => ['required', 'string', 'max:64', 'regex:/^\d+\.\d+$/D'],
            'channel_name' => ['required', 'string', Rule::in([WhatsAppLive::channel($conversation->id)])],
        ]);

        return response()->json($live->authorize($conversation->id, $validated['socket_id']))
            ->header('Cache-Control', 'private, no-store');
    }

    /** Envía un mensaje del equipo (izquierda → derecha en el hilo). */
    public function send(Request $request, WhatsAppConversation $conversation): RedirectResponse|JsonResponse
    {
        $validated = $request->validate([
            'body' => ['required', 'string', 'max:2000'],
        ], [
            'body.required' => 'Escribe un mensaje antes de enviarlo.',
            'body.max' => 'El mensaje no puede pasar de 2000 caracteres.',
        ]);

        $sentAt = now();
        $sent = $this->inbox->send($conversation, trim($validated['body']));
        if ($sent) {
            $conversation->advisorReplied($sentAt);
        }

        $message = $sent
            ? 'Mensaje enviado.'
            : 'No se pudo enviar. Revisa la conexión de WhatsApp (menú WhatsApp) y que Evolution API esté en línea.';

        if ($request->expectsJson()) {
            return response()->json(['message' => $message], $sent ? 200 : 502);
        }

        return back()->with($sent ? 'success' : 'error', $message);
    }

    /**
     * Elimina el chat de la bandeja del panel con todos sus mensajes.
     * No borra nada del teléfono ni de WhatsApp: si el cliente vuelve a escribir, aparece como chat nuevo.
     */
    public function destroy(WhatsAppConversation $conversation, WhatsappBotService $bot): RedirectResponse
    {
        $name = $conversation->contactLabel();

        $bot->forgetConversation($conversation->botIdentity());
        $conversation->delete(); // los mensajes se borran en cascada

        return redirect()
            ->route('whatsapp.chat.index')
            ->with('success', 'Chat con '.$name.' eliminado de la bandeja.');
    }

    /** Pausa o reanuda el bot automático para este chat (útil cuando responde el equipo). */
    public function toggleBot(WhatsAppConversation $conversation): RedirectResponse
    {
        $conversation->update(['bot_active' => ! $conversation->bot_active, 'advisor_wait_until' => null]);

        return back()->with(
            'success',
            $conversation->bot_active
                ? 'Bot automático activado para este chat.'
                : 'Bot automático pausado para este chat: ahora responde el equipo.'
        );
    }
}
