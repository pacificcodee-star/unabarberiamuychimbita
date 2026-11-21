<?php

namespace App\Support;

use Throwable;

/**
 * Borra la ubicación GPS que traen las fotos tomadas con celular (EXIF de JPEG), en PHP puro
 * (el servidor no tiene GD ni Imagick). Se conserva el resto del EXIF, sobre todo la orientación:
 * quitarla haría que las fotos verticales se vieran giradas.
 *
 * Cómo: dentro del bloque APP1 "Exif" se busca en el IFD0 la etiqueta 0x8825 (puntero GPS),
 * se ponen en cero todos los datos del directorio GPS (queda un directorio vacío, TIFF válido),
 * así ningún visor encuentra coordenadas. El tamaño del archivo no cambia.
 */
class ImageSanitizer
{
    private const GPS_POINTER = 0x8825;

    /** Devuelve true si encontró y borró datos GPS. Nunca lanza excepciones. */
    public static function stripGps(string $path): bool
    {
        try {
            $bytes = @file_get_contents($path);
            if ($bytes === false || strlen($bytes) < 4 || substr($bytes, 0, 2) !== "\xFF\xD8") {
                return false; // no es JPEG
            }

            $offset = 2;
            $length = strlen($bytes);
            $changed = false;
            while ($offset + 4 <= $length && $bytes[$offset] === "\xFF") {
                $marker = ord($bytes[$offset + 1]);
                if ($marker === 0xDA || $marker === 0xD9) {
                    break; // empieza la imagen: ya no hay metadatos
                }
                $segmentLength = unpack('n', substr($bytes, $offset + 2, 2))[1];
                if ($marker === 0xE1 && substr($bytes, $offset + 4, 6) === "Exif\0\0") {
                    $tiffStart = $offset + 10;
                    $changed = self::wipeGpsInTiff($bytes, $tiffStart, $offset + 2 + $segmentLength) || $changed;
                }
                $offset += 2 + $segmentLength;
            }

            if ($changed) {
                file_put_contents($path, $bytes);
            }

            return $changed;
        } catch (Throwable) {
            return false;
        }
    }

    private static function wipeGpsInTiff(string &$bytes, int $tiff, int $end): bool
    {
        $order = substr($bytes, $tiff, 2);
        if ($order !== 'II' && $order !== 'MM') {
            return false;
        }
        $le = $order === 'II';
        $u16 = fn (int $at) => unpack($le ? 'v' : 'n', substr($bytes, $at, 2))[1];
        $u32 = fn (int $at) => unpack($le ? 'V' : 'N', substr($bytes, $at, 4))[1];

        $ifd0 = $tiff + $u32($tiff + 4);
        if ($ifd0 + 2 > $end) {
            return false;
        }
        $count = $u16($ifd0);
        for ($i = 0; $i < $count && $i < 500; $i++) {
            $entry = $ifd0 + 2 + $i * 12;
            if ($entry + 12 > $end || $u16($entry) !== self::GPS_POINTER) {
                continue;
            }
            $gps = $tiff + $u32($entry + 8);
            if ($gps + 2 <= $end) {
                $gpsCount = min($u16($gps), 200);
                if ($gpsCount === 0) {
                    return false; // ya estaba limpio
                }
                $sizes = [1 => 1, 2 => 1, 3 => 2, 4 => 4, 5 => 8, 7 => 1, 9 => 4, 10 => 8];
                for ($g = 0; $g < $gpsCount; $g++) {
                    $gEntry = $gps + 2 + $g * 12;
                    if ($gEntry + 12 > $end) {
                        break;
                    }
                    $bytesLong = ($sizes[$u16($gEntry + 2)] ?? 1) * $u32($gEntry + 4);
                    if ($bytesLong > 4) {
                        $data = $tiff + $u32($gEntry + 8);
                        if ($data >= $tiff && $data + $bytesLong <= $end) {
                            $bytes = substr_replace($bytes, str_repeat("\0", $bytesLong), $data, $bytesLong);
                        }
                    }
                }
                // Borra la tabla de entradas GPS (deja el directorio vacío).
                $tableBytes = min(2 + $gpsCount * 12, $end - $gps);
                $bytes = substr_replace($bytes, str_repeat("\0", $tableBytes), $gps, $tableBytes);
            }
            // El puntero GPS se deja igual: ahora apunta a un directorio vacío (0 entradas),
            // que es TIFF válido, así no se altera el orden de las etiquetas del IFD0.

            return true;
        }

        return false;
    }
}
