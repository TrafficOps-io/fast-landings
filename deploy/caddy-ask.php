#!/usr/bin/env php
<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require '/var/www/html/vendor/autoload.php';

$app = require '/var/www/html/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$deny = static function (int $status): never {
    http_response_code($status);
    header('Cache-Control: no-store');
    exit;
};

$expectedToken = (string) config('fast-landings.caddy_ask_token');
$providedToken = (string) ($_GET['token'] ?? '');

if ($expectedToken === '' || ! hash_equals($expectedToken, $providedToken)) {
    $deny(403);
}

$requested = (string) ($_GET['domain'] ?? '');
$hostname = strtolower(rtrim($requested, '.'));

if ($hostname === '' || strlen($hostname) > 253 || $requested !== $hostname || ! validAskHostname($hostname)) {
    $deny(404);
}

if ($hostname === (string) config('fast-landings.panel_domain')) {
    $deny(404);
}

$release = DB::table('domains as domains')
    ->join('landings as landings', 'landings.id', '=', 'domains.landing_id')
    ->join('landing_releases as releases', 'releases.landing_id', '=', 'landings.id')
    ->where('domains.hostname', $hostname)
    ->where('domains.status', 'active')
    ->where('landings.is_active', true)
    ->where('releases.is_active', true)
    ->orderByDesc('releases.activated_at')
    ->first(['releases.storage_path']);

if ($release === null) {
    $deny(404);
}

$storagePath = trim((string) $release->storage_path, '/');
$landingRoot = rtrim((string) getenv('LANDINGS_PATH'), '/');
$landingRoot = $landingRoot !== '' ? $landingRoot : '/var/lib/fast-landings/landings';
$rootPath = realpath($landingRoot);
$releasePath = safeAskRelativePath($storagePath) ? realpath($landingRoot.'/'.$storagePath) : false;

if ($rootPath === false || $releasePath === false || ! str_starts_with($releasePath.'/', $rootPath.'/') || ! is_file($releasePath.'/index.html')) {
    $deny(404);
}

http_response_code(204);
header('Cache-Control: no-store');

function validAskHostname(string $hostname): bool
{
    if (! preg_match('/^[a-z0-9.-]+$/D', $hostname)) {
        return false;
    }

    foreach (explode('.', $hostname) as $label) {
        if ($label === '' || strlen($label) > 63 || ! preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/D', $label)) {
            return false;
        }
    }

    return true;
}

function safeAskRelativePath(string $path): bool
{
    if ($path === '' || str_contains($path, "\0") || str_contains($path, '\\')) {
        return false;
    }

    foreach (explode('/', $path) as $segment) {
        if ($segment === '' || $segment === '.' || $segment === '..') {
            return false;
        }
    }

    return true;
}
