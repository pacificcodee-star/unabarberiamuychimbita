<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Crea una copia WebP junto a cada imagen PNG/JPG (foto.png → foto.png.webp) sin tocar el original.
 *
 * El servidor web (Caddy) entrega la copia .webp en lugar del original cuando existe, así que no hay
 * que cambiar rutas en la base de datos ni en las vistas, y lo que se suba desde el panel se convierte
 * solo en la siguiente pasada del programador.
 *
 * Calidad: WebP con pérdida a calidad 90, alfa sin pérdida y "sharp YUV". Las pruebas dan 40–45 dB de
 * PSNR (diferencias que el ojo no distingue) con archivos entre 5 y 10 veces más livianos en los PNG.
 * Si la copia no pesa menos que el original (pasa con algunos JPG ya muy comprimidos), no se guarda.
 */
class ConvertImagesToWebp extends Command
{
    protected $signature = 'barberia:webp
        {--solo-storage : Solo las imágenes subidas (storage/app/public), sin las del sitio (public/images)}
        {--calidad=90 : Calidad WebP de 1 a 100}';

    protected $description = 'Crea copias WebP livianas de las imágenes PNG/JPG (el original no se toca)';

    private const EXTENSIONS = ['png', 'jpg', 'jpeg'];

    public function handle(): int
    {
        $cwebp = (new ExecutableFinder)->find('cwebp');
        if ($cwebp === null) {
            $this->warn('No está instalado cwebp (paquete libwebp-tools). No se convirtió nada.');

            return self::SUCCESS;
        }

        $roots = [storage_path('app/public')];
        if (! $this->option('solo-storage')) {
            $roots[] = public_path('images');
        }

        $quality = max(1, min(100, (int) $this->option('calidad')));
        $skipFile = storage_path('framework/webp-omitidos.json');
        $skipped = is_file($skipFile) ? (json_decode((string) file_get_contents($skipFile), true) ?: []) : [];
        $created = $omitted = $failed = $savedBytes = 0;

        foreach ($roots as $root) {
            if (! is_dir($root)) {
                continue;
            }

            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                /** @var \SplFileInfo $file */
                if (! $file->isFile() || ! in_array(strtolower($file->getExtension()), self::EXTENSIONS, true)) {
                    continue;
                }

                $source = $file->getPathname();
                $target = $source.'.webp';
                $signature = $file->getMTime().':'.$file->getSize();

                if (is_file($target) && filemtime($target) >= $file->getMTime()) {
                    continue; // ya está al día
                }
                if (($skipped[$source] ?? null) === $signature) {
                    continue; // ya se probó y el WebP no pesaba menos
                }

                $temp = $target.'.tmp';
                $args = [$cwebp, '-quiet', '-mt', '-m', '6', '-q', (string) $quality, '-sharp_yuv', '-metadata', 'icc'];
                if (strtolower($file->getExtension()) === 'png') {
                    $args = [...$args, '-alpha_q', '100', '-exact'];
                }

                $process = new Process([...$args, $source, '-o', $temp]);
                $process->setTimeout(120)->run();

                if (! $process->isSuccessful() || ! is_file($temp)) {
                    @unlink($temp);
                    $failed++;
                    $this->warn('No se pudo convertir '.$this->relative($source).': '.trim($process->getErrorOutput()));

                    continue;
                }

                $newSize = (int) filesize($temp);
                if ($newSize >= $file->getSize()) {
                    @unlink($temp);
                    @unlink($target); // una copia vieja ya no corresponde al original nuevo
                    $skipped[$source] = $signature;
                    $omitted++;

                    continue;
                }

                rename($temp, $target);
                @chmod($target, 0644);
                unset($skipped[$source]);
                $created++;
                $savedBytes += $file->getSize() - $newSize;
                $this->line(sprintf('  %s  %s → %s', $this->relative($source), $this->size($file->getSize()), $this->size($newSize)));
            }
        }

        // Copias huérfanas: si se borró el original, también su .webp (si no, Caddy seguiría sirviéndola).
        foreach ($roots as $root) {
            if (! is_dir($root)) {
                continue;
            }
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
                $path = $file->getPathname();
                if (preg_match('/\.(png|jpe?g)\.webp$/i', $path) && ! is_file(substr($path, 0, -5))) {
                    @unlink($path);
                }
            }
        }

        @file_put_contents($skipFile, json_encode($skipped, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

        if ($created || $omitted || $failed) {
            $this->info(sprintf(
                'WebP: %d creadas (%s menos), %d omitidas porque no pesaban menos, %d con error.',
                $created,
                $this->size($savedBytes),
                $omitted,
                $failed,
            ));
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function relative(string $path): string
    {
        return ltrim(str_replace([base_path(), '\\'], ['', '/'], $path), '/');
    }

    private function size(int $bytes): string
    {
        return $bytes >= 1048576 ? round($bytes / 1048576, 1).' MB' : round($bytes / 1024).' KB';
    }
}
