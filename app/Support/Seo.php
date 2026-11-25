<?php

namespace App\Support;

/**
 * Títulos y descripciones para Google de las páginas públicas.
 *
 * Son textos aparte de los del panel: los del panel se ven en la página y pueden ser
 * creativos; estos solo salen en los resultados de búsqueda y llevan las palabras que la
 * gente escribe en Google (barbería, corte de cabello, barba, ciudad).
 * Títulos de hasta ~60 caracteres y descripciones de hasta ~155 para que Google no los corte.
 */
class Seo
{
    /** @return array{title: string, description: string} */
    public static function page(string $page, array $settings): array
    {
        $brand = $settings['brand_name'] ?? 'Una Barberia Muy Chimbita';
        $city = trim(explode(',', $settings['city'] ?? 'Bogotá')[0]) ?: 'Bogotá';

        return match ($page) {
            'landing' => [
                'title' => "{$brand} · Barbería en {$city}",
                'description' => "Barbería en {$city}: cortes de cabello para hombre, arreglo de barba y cejas. Reserva tu cita o toma tu turno en línea, sin crear cuenta.",
            ],
            'services' => [
                'title' => "Precios de corte y barba en {$city} · {$brand}",
                'description' => "Precios de corte de cabello para hombre, arreglo de barba, cejas y más en nuestra barbería en {$city}. Elige tu servicio y reserva en línea.",
            ],
            'booking' => [
                'title' => "Reservar cita en barbería en {$city} · {$brand}",
                'description' => "Reserva tu cita de barbería en {$city} o toma un turno en línea. Elige barbero, servicio y hora en un minuto, sin crear cuenta.",
            ],
            'barbers' => [
                'title' => "Barberos en {$city} · {$brand}",
                'description' => "Conoce a los barberos de {$brand} en {$city}: cortes clásicos y modernos, degradados y barba. Reserva con el barbero que prefieras.",
            ],
            'reviews' => [
                'title' => "Opiniones de clientes · {$brand}, barbería en {$city}",
                'description' => "Lo que dicen los clientes de {$brand}, barbería en {$city}. Opiniones verificadas sobre cortes, barba y atención.",
            ],
            'about' => [
                'title' => "Nosotros · {$brand}, barbería en {$city}",
                'description' => "Conoce {$brand}, barbería en {$city} para hombres que cuidan su estilo: cortes, barba y una atención sin afán.",
            ],
            'contact' => [
                'title' => "Dirección y horarios · {$brand}, barbería en {$city}",
                'description' => "Dirección, horarios, teléfono y WhatsApp de {$brand}, barbería en {$city}. Escríbenos o reserva tu cita en línea.",
            ],
        };
    }
}
