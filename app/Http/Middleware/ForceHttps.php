<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Obliga a usar HTTPS (FORCE_HTTPS, activo por defecto en producción):
 * redirige http:// a https:// con un 301 y envía HSTS y cabeceras básicas de seguridad.
 * Render termina el TLS en su proxy; como confiamos en él (trustProxies),
 * $request->secure() refleja lo que vio el visitante.
 */
class ForceHttps
{
    public function handle(Request $request, Closure $next): Response
    {
        $enabled = (bool) config('web.force_https');

        // El chequeo de salud de Render entra por http interno: no se redirige.
        if ($enabled && ! $request->secure() && ! $request->is('up')) {
            return redirect()->secure($request->getRequestUri(), 301);
        }

        $response = $next($request);

        $headers = $response->headers;
        if ($enabled && $request->secure()) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }
        if (! $headers->has('X-Content-Type-Options')) {
            $headers->set('X-Content-Type-Options', 'nosniff');
        }
        if (! $headers->has('Referrer-Policy')) {
            $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        }
        if (! $headers->has('X-Frame-Options')) {
            $headers->set('X-Frame-Options', 'SAMEORIGIN');
        }

        return $response;
    }
}
