<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Las páginas públicas se revalidan siempre: así un cambio hecho en el panel
 * aparece en la siguiente visita aunque el navegador o el Service Worker tengan una copia.
 */
class RevalidatePublicContent
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($request->isMethod('GET')) {
            $personal = $request->cookies->has('mis_servicios');
            $response->headers->set('Cache-Control', $personal ? 'private, no-store' : 'no-cache, must-revalidate, max-age=0');
        }

        return $response;
    }
}
