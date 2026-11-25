<?php

$appKey = (string) env('APP_KEY', '');

return [
    'chat_enabled' => (bool) env('WHATSAPP_LIVE_ENABLED', false),
    'default' => 'reverb',
    'servers' => [
        'reverb' => [
            'host' => env('REVERB_SERVER_HOST', '127.0.0.1'),
            'port' => (int) env('REVERB_SERVER_PORT', 8091),
            'hostname' => null,
            'options' => [],
            'scaling' => ['enabled' => false],
            'pulse_ingest_interval' => 15,
        ],
    ],
    'apps' => [
        'provider' => 'config',
        'apps' => [[
            'app_id' => 'barberia-whatsapp',
            'key' => substr(hash_hmac('sha256', 'barberia-reverb-public', $appKey), 0, 32),
            'secret' => hash_hmac('sha256', 'barberia-reverb-private', $appKey),
            'options' => [
                'host' => env('WHATSAPP_LIVE_HOST', '127.0.0.1'),
                'port' => (int) env('REVERB_SERVER_PORT', 8091),
                'scheme' => 'http',
            ],
            'allowed_origins' => [parse_url((string) env('APP_URL', 'http://localhost'), PHP_URL_HOST) ?: 'localhost'],
            'ping_interval' => 30,
            'activity_timeout' => 30,
            'max_connections' => 64,
            'max_message_size' => 4096,
            'max_channels_per_connection' => 1,
            'accept_client_events_from' => 'none',
        ]],
    ],
];
