<?php

/*
| Datos del sitio público que no se editan desde el panel:
| información legal, analítica, ficha de Google y HTTPS.
| Todo se configura en el archivo .env (ver .env.example).
*/

return [
    // Redirige todo http:// a https:// y envía la cabecera HSTS.
    // Por defecto solo en producción, para no romper el desarrollo local.
    'force_https' => env('FORCE_HTTPS', env('APP_ENV') === 'production'),

    // Caché de páginas públicas para visitantes anónimos (App\Support\PageCache). Activa por defecto
    // solo en producción. PAGE_CACHE_TTL: segundos que se usa una copia (los horarios "Hoy: …" y los
    // cambios del panel se ven como mucho con ese retraso; los cambios del panel la borran al instante).
    'page_cache' => [
        'enabled' => (bool) env('PAGE_CACHE', env('APP_ENV') === 'production'),
        'ttl' => (int) env('PAGE_CACHE_TTL', 60),
    ],

    'legal' => [
        // Titular del sitio y responsable del tratamiento de datos (Ley 1581 de 2012).
        'owner' => env('LEGAL_OWNER'),        // Razón social o nombre del dueño
        'nit' => env('LEGAL_NIT'),            // NIT o cédula
        'email' => env('LEGAL_EMAIL'),        // Correo para consultas de datos personales (si no, el del negocio)
        'updated_at' => env('LEGAL_UPDATED_AT', '2026-11-21'),
    ],

    // Google Analytics 4 (G-XXXXXXX). Solo se carga si el visitante acepta las cookies.
    'ga4_id' => env('ANALYTICS_GA4_ID'),

    'google' => [
        // Enlace a la ficha de Google Business Profile (botón "Califícanos en Google").
        'business_url' => env('GOOGLE_BUSINESS_URL'),
        // Enlace directo para escribir una reseña (https://g.page/r/XXXX/review).
        'review_url' => env('GOOGLE_REVIEW_URL'),
        // Código de verificación de Search Console (meta google-site-verification).
        'site_verification' => env('GOOGLE_SITE_VERIFICATION'),
    ],
];
