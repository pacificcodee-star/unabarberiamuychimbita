<?php

namespace App\Http\Middleware;

use App\Support\PageCache;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sirve las páginas públicas desde la caché de archivos (App\Support\PageCache) antes de abrir la
 * sesión: un visitante anónimo no genera consultas a la base de datos ni escrituras de sesión.
 * Va al principio del grupo "web" (bootstrap/app.php), antes de StartSession.
 */
class PublicPageCache
{
    public function handle(Request $request, Closure $next): Response
    {
        $file = PageCache::fileFor($request);

        if ($file !== null && ($html = PageCache::fresh($file)) !== null) {
            return response($request->isMethod('HEAD') ? '' : $html, 200, [
                'Content-Type' => 'text/html; charset=UTF-8',
                // Igual que RevalidatePublicContent: el navegador siempre vuelve a preguntar.
                'Cache-Control' => 'no-cache, must-revalidate, max-age=0',
                'X-Page-Cache' => 'HIT',
            ]);
        }

        $response = $next($request);

        if ($file !== null && $this->storable($request, $response)) {
            PageCache::store($file, PageCache::sanitize((string) $response->getContent()));
            $response->headers->set('X-Page-Cache', 'MISS');
        }

        return $response;
    }

    private function storable(Request $request, Response $response): bool
    {
        if ($request->isMethod('HEAD') || $response->getStatusCode() !== 200
            || ! str_contains((string) $response->headers->get('Content-Type'), 'text/html')) {
            return false;
        }

        // Página armada para alguien en particular: sesión iniciada o un mensaje de una sola vez.
        if (auth()->check()) {
            return false;
        }
        if ($request->hasSession() && ($request->session()->get('_flash.old', []) !== [] || $request->session()->has('errors'))) {
            return false;
        }

        return true;
    }
}
