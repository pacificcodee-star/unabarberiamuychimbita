<?php

use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\BlockPhonesFromPanel;
use App\Http\Middleware\EnsureUserIsAdmin;
use App\Http\Middleware\ForceHttps;
use App\Http\Middleware\PublicPageCache;
use App\Http\Middleware\RevalidatePublicContent;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Solo Cloudflare (o el proxy explícito de otra plataforma) puede enviar la IP y el protocolo reales.
        $proxyConfig = require __DIR__.'/../config/proxies.php';
        $middleware->trustProxies(
            at: $proxyConfig['proxies'],
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO
                | Request::HEADER_X_FORWARDED_PREFIX,
        );
        // Redirige http:// → https:// en producción y añade HSTS y cabeceras de seguridad.
        $middleware->prepend(ForceHttps::class);
        // Identificador de correlación en todos los registros y en la cabecera X-Request-Id.
        $middleware->prepend(AssignRequestId::class);
        // Páginas públicas ya armadas para visitantes anónimos: se responden antes de abrir la sesión
        // (sin consultas ni escrituras en la base de datos). Ver App\Support\PageCache.
        $middleware->web(prepend: [PublicPageCache::class]);

        $middleware->alias([
            'admin' => EnsureUserIsAdmin::class,
            // Cada visita pública consulta el servidor, sin copias viejas del navegador.
            'public-fresh' => RevalidatePublicContent::class,
            // El panel solo se usa en computadores y tablets; los celulares ven un aviso.
            'panel-desktop' => BlockPhonesFromPanel::class,
        ]);
        // El Service Worker renueva la suscripción push sin página abierta, así que no puede enviar el token CSRF.
        // El webhook de Evolution API tampoco pasa por el navegador (valida apikey propia).
        $middleware->validateCsrfTokens(except: ['push/renovar', 'webhook/evolution', 'errores-cliente']);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Errores de validación, 404 y sesión vencida son normales: no ensucian el registro de errores.
        $exceptions->dontReport([
            \Illuminate\Session\TokenMismatchException::class,
        ]);
        // La base de datos caída es crítica: se marca para que salte a la vista en el registro.
        $exceptions->report(function (\Illuminate\Database\QueryException $e) {
            if (str_contains($e->getMessage(), 'SQLSTATE[HY000] [2002]') || str_contains($e->getMessage(), 'Connection refused')) {
                \Illuminate\Support\Facades\Log::critical('db.conexion_fallida', ['codigo' => $e->getCode()]);
            }
        });
        // En las respuestas JSON de error (peticiones del navegador) va el id para soporte, nunca el detalle técnico.
        $exceptions->respond(function (\Symfony\Component\HttpFoundation\Response $response) {
            if ($response instanceof \Illuminate\Http\JsonResponse && $response->getStatusCode() >= 500 && ! config('app.debug')) {
                $response->setData(['message' => 'Tuvimos un problema en el servidor. Intenta de nuevo en un momento.', 'request_id' => \App\Support\RequestId::current()]);
            }

            return $response;
        });
    })->create();
