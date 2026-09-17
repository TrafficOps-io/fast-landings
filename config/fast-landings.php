<?php

$appHost = parse_url((string) env('APP_URL', 'http://localhost'), PHP_URL_HOST) ?: 'localhost';
$panelDomain = strtolower(rtrim((string) env('FAST_LANDINGS_PANEL_DOMAIN', $appHost), '.'));

return [
    'panel_domain' => $panelDomain,
    'system_domain' => strtolower(rtrim((string) env('FAST_LANDINGS_SYSTEM_DOMAIN', $panelDomain), '.')),
    'origin_target' => strtolower(rtrim((string) env('FAST_LANDINGS_ORIGIN_TARGET', $panelDomain), '.')),
    'storage_disk' => 'landings',
    'php' => [
        'address' => env('FAST_LANDINGS_PHP_ADDRESS', 'tcp://127.0.0.1:9070'),
        'storage_root' => env('FAST_LANDINGS_PHP_STORAGE_ROOT', '/srv/landings'),
        'timeout' => (int) env('FAST_LANDINGS_PHP_TIMEOUT', 35),
        'max_response_bytes' => (int) env('FAST_LANDINGS_PHP_MAX_RESPONSE_MB', 32) * 1024 * 1024,
    ],
    'previews' => [
        'enabled' => (bool) env('FAST_LANDINGS_PREVIEWS_ENABLED', true),
        'node_binary' => env('FAST_LANDINGS_NODE_BINARY', 'node'),
        'chromium_path' => env('FAST_LANDINGS_CHROMIUM_PATH'),
    ],
    'max_upload_kb' => (int) env('FAST_LANDINGS_MAX_UPLOAD_MB', 100) * 1024,
    'max_extracted_bytes' => (int) env('FAST_LANDINGS_MAX_EXTRACTED_MB', 300) * 1024 * 1024,
    'max_files' => (int) env('FAST_LANDINGS_MAX_FILES', 5000),
    'max_expansion_ratio' => (int) env('FAST_LANDINGS_MAX_EXPANSION_RATIO', 200),
    'max_path_bytes' => (int) env('FAST_LANDINGS_MAX_PATH_BYTES', 1024),
    'max_path_depth' => (int) env('FAST_LANDINGS_MAX_PATH_DEPTH', 32),
    'templates' => [
        'media_disk' => env('FAST_LANDINGS_MEDIA_DISK', env('FILESYSTEM_DISK', 'local')),
        'max_definition_bytes' => 2 * 1024 * 1024,
        'max_render_bytes' => 8 * 1024 * 1024,
        'max_image_kb' => 10 * 1024,
    ],
    'spa_fallback' => (bool) env('FAST_LANDINGS_SPA_FALLBACK', true),
    'caddy_ask_token' => env('FAST_LANDINGS_CADDY_ASK_TOKEN'),
    'reserved_subdomains' => ['admin', 'api', 'app', 'mail', 'www'],
    'domain_checks' => [
        'pending_interval' => 60,
        'active_interval' => 600,
        'error_interval' => 300,
        'request_lease' => 600,
    ],
];
