<?php

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

        $middleware->alias([
            'admin' => EnsureUserIsAdmin::class,
            // El panel solo se usa en computadores y tablets; los celulares ven un aviso.
            'panel-desktop' => BlockPhonesFromPanel::class,
        ]);
        // El Service Worker renueva la suscripción push sin página abierta, así que no puede enviar el token CSRF.
        // El webhook de Evolution API tampoco pasa por el navegador (valida apikey propia).
        $middleware->validateCsrfTokens(except: ['push/renovar', 'webhook/evolution']);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
