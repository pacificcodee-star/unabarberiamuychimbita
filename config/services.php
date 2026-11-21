<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    // Google Maps (mapa de la página de Contacto). Crea la clave en Google Cloud con "Maps JavaScript API"
    // y "Geocoding API" activadas, y restríngela a tu dominio. Ponla en el .env como GOOGLE_MAPS_API_KEY.
    'google_maps' => [
        'key' => env('GOOGLE_MAPS_API_KEY'),
        'map_id' => env('GOOGLE_MAPS_MAP_ID'),
    ],

    // Notificaciones push (Web Push estándar con claves VAPID). Genera las claves con: php artisan barberia:push-keys
    'webpush' => [
        'public_key' => env('VAPID_PUBLIC_KEY'),
        'private_key' => env('VAPID_PRIVATE_KEY'),
        'subject' => env('VAPID_SUBJECT', 'mailto:'.env('MAIL_FROM_ADDRESS', 'hola@example.com')),
        // Ruta a un archivo de certificados (cacert.pem) si PHP no tiene uno configurado. Opcional.
        'ca_bundle' => env('WEBPUSH_CA_BUNDLE'),
    ],

    // WhatsApp: el canal activo por defecto es Evolution API (número normal, QR, sin plantillas de Meta).
    // Cambia WHATSAPP_DRIVER=cloud para volver a la Cloud API oficial.
    'whatsapp' => [
        'driver' => env('WHATSAPP_DRIVER', 'evolution'),
        'token' => env('WHATSAPP_CLOUD_TOKEN'),
        'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID'),
        'default_country' => env('WHATSAPP_DEFAULT_COUNTRY', '57'),
        'template_language' => env('WHATSAPP_TEMPLATE_LANGUAGE', 'es_CO'),
        'templates' => [
            'turn_confirmed' => env('WHATSAPP_TPL_TURN_CONFIRMED'),
            'turn_next' => env('WHATSAPP_TPL_TURN_NEXT'),
            'turn_called' => env('WHATSAPP_TPL_TURN_CALLED'),
            'turn_cancelled' => env('WHATSAPP_TPL_CANCELLED'),
            'appt_booked' => env('WHATSAPP_TPL_APPT_BOOKED'),
            'appt_confirmed' => env('WHATSAPP_TPL_APPT_BOOKED'),
            'appt_reminder_60' => env('WHATSAPP_TPL_APPT_REMINDER'),
            'appt_reminder_15' => env('WHATSAPP_TPL_APPT_REMINDER'),
            'appt_reminder_0' => env('WHATSAPP_TPL_APPT_REMINDER'),
            'appt_cancelled' => env('WHATSAPP_TPL_CANCELLED'),
            'appt_rescheduled' => env('WHATSAPP_TPL_APPT_BOOKED'),
        ],
    ],

    // Evolution API (WhatsApp propio, sin cuotas de Meta). Corre en Northflank y tu Laravel
    // se conecta por HTTP con la cabecera "apikey". Ver docs/WHATSAPP.md.
    'evolution' => [
        'url' => rtrim((string) env('EVOLUTION_URL', ''), '/'),
        'apikey' => env('EVOLUTION_APIKEY'),
        'instance' => env('EVOLUTION_INSTANCE', 'barberia'),
        'timeout' => (int) env('EVOLUTION_TIMEOUT', 20),
        // Secreto que se exige en cada webhook entrante (campo "apikey" del cuerpo).
        'webhook_secret' => env('EVOLUTION_WEBHOOK_SECRET'),
        // URL a la que Evolution envía los mensajes entrantes. Vacío = APP_URL/webhook/evolution.
        // Si Evolution corre en el mismo equipo o red que Laravel, conviene la dirección interna
        // (p. ej. http://127.0.0.1:8000/webhook/evolution): no depende de túneles ni de HTTPS públicos.
        'webhook_url' => env('EVOLUTION_WEBHOOK_URL'),
        // Bot automático: responde confirmaciones, cancelaciones y consultas frecuentes.
        'bot_enabled' => (bool) env('WHATSAPP_BOT_ENABLED', true),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
