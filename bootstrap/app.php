<?php

use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\BlockPhonesFromPanel;
use App\Http\Middleware\EnsureUserIsAdmin;
use App\Http\Middleware\ForceHttps;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Render (y cualquier proxy TLS) termina HTTPS antes del contenedor:
        // sin esto, Laravel genera URLs http:// y rompe assets y webhooks.
        $middleware->trustProxies(at: '*');
        // Redirige http:// → https:// en producción y añade HSTS y cabeceras de seguridad.
        $middleware->prepend(ForceHttps::class);
        // Identificador de correlación en todos los registros y en la cabecera X-Request-Id.
        $middleware->prepend(AssignRequestId::class);

        $middleware->alias([
            'admin' => EnsureUserIsAdmin::class,
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
