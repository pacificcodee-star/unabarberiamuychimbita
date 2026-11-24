<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Obliga a usar HTTPS (FORCE_HTTPS, activo por defecto en producción):
 * redirige http:// a https:// con un 301 y envía HSTS y cabeceras básicas de seguridad.
 * Cloudflare o Render termina el TLS; solo confiamos en los proxies configurados,
 * así que $request->secure() refleja lo que vio el visitante sin aceptar cabeceras directas.
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
        // El sitio no usa cámara, micrófono, ubicación ni pagos del navegador: se bloquean para todos.
        if (! $headers->has('Permissions-Policy')) {
            $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=(), serial=(), bluetooth=()');
        }

        if (! $headers->has('Content-Security-Policy') && str_contains((string) $headers->get('Content-Type'), 'text/html')) {
            // CSP aplicada: lo que no puede romper nada y corta ataques comunes
            // (etiqueta <base> inyectada, plugins, el sitio dentro de iframes ajenos, formularios a otros dominios).
            $enforced = "base-uri 'self'; object-src 'none'; frame-ancestors 'self'; form-action 'self'"
                .($enabled && $request->secure() ? '; upgrade-insecure-requests' : '');
            $headers->set('Content-Security-Policy', $enforced);
            // CSP completa en modo observación: informa en la consola qué se bloquearía, sin bloquear.
            // Para aplicarla falta pasar los <script> en línea a archivos o a nonces (ver docs/PRODUCCION.md).
            $headers->set('Content-Security-Policy-Report-Only', implode('; ', [
                "default-src 'self'",
                "script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com https://www.googletagmanager.com https://maps.googleapis.com",
                "style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com https://fonts.googleapis.com https://fonts.bunny.net",
                "font-src 'self' data: https://fonts.gstatic.com https://cdn.jsdelivr.net https://cdnjs.cloudflare.com https://fonts.bunny.net",
                "img-src 'self' data: blob: https:",
                "media-src 'self' blob:",
                "connect-src 'self' https://*.google-analytics.com https://*.analytics.google.com https://www.googletagmanager.com https://maps.googleapis.com",
                "frame-src 'self' https://www.google.com",
                "worker-src 'self'",
                "manifest-src 'self'",
                "base-uri 'self'",
                "object-src 'none'",
                "frame-ancestors 'self'",
                "form-action 'self'",
            ]));
        }

        return $response;
    }
}
