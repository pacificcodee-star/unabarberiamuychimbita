<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Recibe los errores de JavaScript de los visitantes (navigator.sendBeacon) y los guarda en
 * storage/logs/navegador-*.log. Sin datos personales: se recortan los textos y se ocultan
 * los tokens de los enlaces de seguimiento y los parámetros de las URLs.
 */
class ClientErrorController extends Controller
{
    public function __invoke(Request $request)
    {
        try {
            $data = json_decode((string) $request->getContent(), true);
            if (! is_array($data)) {
                return response()->noContent();
            }

            Log::channel('browser')->warning('navegador.error', [
                'tipo' => Str::limit(preg_replace('/[^a-z_]/', '', (string) ($data['type'] ?? 'error')), 20, ''),
                'mensaje' => $this->clean((string) ($data['message'] ?? ''), 300),
                'origen' => $this->clean((string) ($data['source'] ?? ''), 200),
                'linea' => (int) ($data['line'] ?? 0),
                'pagina' => $this->clean((string) ($data['page'] ?? ''), 200),
                'pila' => $this->clean((string) ($data['stack'] ?? ''), 1000),
                'navegador' => Str::limit((string) $request->userAgent(), 160, ''),
            ]);
        } catch (Throwable) {
            // Reportar un error nunca debe provocar otro.
        }

        return response()->noContent();
    }

    private function clean(string $text, int $max): string
    {
        $text = preg_replace('#(/seguimiento/(?:turno|cita)/)[A-Za-z0-9]{20,}#', '$1[token]', $text);
        $text = preg_replace('#\?[^\s\'")]*#', '?…', $text); // sin parámetros (pueden llevar datos)
        $text = preg_replace('/[A-Za-z0-9]{32,}/', '[oculto]', $text);

        return Str::limit(trim($text), $max, '…');
    }
}
