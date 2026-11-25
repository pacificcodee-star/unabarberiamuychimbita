<?php

preg_match_all(
    '/[0-9a-fA-F:.]+\\/\\d+/',
    (string) file_get_contents(base_path('deploy/caddy/cloudflare_ips.caddy')),
    $cloudflareRanges,
);

$cloudflareRanges = $cloudflareRanges[0];

$configured = trim((string) env('TRUSTED_PROXIES', ''));

if ($configured === '*' || $configured === '**') {
    $proxies = $configured;
} elseif ($configured !== '') {
    $proxies = array_values(array_filter(
        array_map('trim', explode(',', $configured)),
        static fn (string $proxy): bool => $proxy !== '',
    ));
} else {
    $proxies = $cloudflareRanges;
}

return [
    'proxies' => $proxies,
];
