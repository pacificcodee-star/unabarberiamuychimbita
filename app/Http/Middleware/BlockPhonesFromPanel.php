<?php

namespace App\Http\Middleware;

use App\Models\SiteSetting;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * El panel administrativo solo se abre desde computadores y tablets.
 * Un celular recibe una página explicativa en lugar del formulario de ingreso.
 */
class BlockPhonesFromPanel
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! self::isPhone($request)) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'El panel administrativo solo está disponible en computadores y tablets.',
            ], 403);
        }

        $settings = SiteSetting::currentData();

        return response()->view('auth.device-blocked', [
            'brandName' => (string) ($settings['brand_name'] ?? config('app.name')),
            'whatsapp' => (string) ($settings['whatsapp'] ?? ''),
        ], 403);
    }

    /** Celular (no tablet) según el navegador que hace la petición. */
    public static function isPhone(Request $request): bool
    {
        $ua = strtolower((string) $request->userAgent());

        if ($ua === '') {
            return false;
        }

        // Tabletas: iPad, Android sin la marca "Mobile", Kindle/Fire y similares.
        if (preg_match('/ipad|tablet|kindle|silk|playbook|sm-t\d|gt-p\d|mediapad|nexus 7|nexus 9|lenovo tb|tab\d/i', $ua)) {
            return false;
        }

        return (bool) preg_match('/iphone|ipod|android.+mobile|windows phone|iemobile|blackberry|bb10|opera mini|opera mobi|webos|symbian|palm/i', $ua);
    }
}
