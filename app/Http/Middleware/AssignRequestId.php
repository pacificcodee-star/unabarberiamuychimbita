<?php

namespace App\Http\Middleware;

use App\Support\RequestId;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Identificador de correlación: cada petición recibe un X-Request-Id que va en todos los registros
 * (Log::shareContext) y vuelve en la respuesta. Con él se sigue una reserva de punta a punta y el
 * cliente puede citarlo en una página de error sin ver ningún detalle técnico.
 */
class AssignRequestId
{
    public function handle(Request $request, Closure $next): Response
    {
        $incoming = (string) $request->headers->get('X-Request-Id', '');
        // Solo se acepta uno entrante si tiene forma segura (lo pone el proxy); si no, se genera.
        $id = preg_match('/^[A-Za-z0-9\-]{8,64}$/', $incoming) ? $incoming : (string) Str::uuid();

        RequestId::set($id);
        Log::shareContext(['request_id' => $id]);

        $response = $next($request);
        $response->headers->set('X-Request-Id', $id);

        return $response;
    }
}
