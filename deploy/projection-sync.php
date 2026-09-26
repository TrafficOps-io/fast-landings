#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Support\ServableProjection;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

$sourceRoot = rtrim((string) getenv('LANDINGS_PATH'), '/');
$sourceRoot = $sourceRoot !== '' ? $sourceRoot : '/var/lib/fast-landings/landings';
$publicRoot = rtrim((string) getenv('FAST_LANDINGS_PUBLIC_PATH'), '/');
$publicRoot = $publicRoot !== '' ? $publicRoot : '/var/lib/fast-landings/public';
$hostsRoot = $publicRoot.'/hosts';
$releasesRoot = $publicRoot.'/releases';
$quarantineRoot = $publicRoot.'/quarantine';
$heartbeat = $hostsRoot.'/.projection-heartbeat';
$runOnce = in_array('--once', $argv, true);
$disableOnly = in_array('--disable', $argv, true);
$running = true;
$globalFailureStartedAt = null;

if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, static function () use (&$running): void {
        $running = false;
    });
    pcntl_signal(SIGINT, static function () use (&$running): void {
        $running = false;
    });
}

foreach ([$hostsRoot, $releasesRoot, $quarantineRoot] as $directory) {
    if (! is_dir($directory) && ! mkdir($directory, 0750, true) && ! is_dir($directory)) {
        fwrite(STDERR, "Unable to create a landing projection directory.\n");
        exit(1);
    }
}

if ($disableOnly) {
    try {
        $removed = reconcileHosts($hostsRoot, $quarantineRoot, []);
        @unlink($heartbeat);
        fwrite(STDERR, sprintf("[%s] disabled %d host projection(s)\n", gmdate(DATE_ATOM), $removed));
        exit(0);
    } catch (Throwable $exception) {
        @unlink($heartbeat);
        fwrite(STDERR, sprintf("[%s] unable to disable host projections: %s\n", gmdate(DATE_ATOM), $exception->getMessage()));
        exit(1);
    }
}

require '/var/www/html/vendor/autoload.php';

$app = require '/var/www/html/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

/** @return array{0: array<string, string>, 1: array<string, true>, 2: list<string>} */
$desiredProjection = static function () use ($sourceRoot, $releasesRoot): array {
    // The serving predicate is Domain::scopeServable(); ServableProjection joins
    // the active release onto it. tests/Feature/ProjectionSyncPredicateTest.php
    // asserts both select the same domains.
    $rows = ServableProjection::rows();

    $sourceRootPath = realpath($sourceRoot);
    if ($sourceRootPath === false) {
        throw new RuntimeException('The private landing store is unavailable.');
    }

    $projection = [];
    $seenHosts = [];
    $errors = [];

    foreach ($rows as $row) {
        $hostname = strtolower(rtrim((string) $row->hostname, '.'));
        $landingId = (string) $row->landing_id;
        $releaseId = (string) $row->release_id;
        $storagePath = trim((string) $row->storage_path, '/');

        if (! validHostname($hostname)
            || ! validUlid($landingId)
            || ! validUlid($releaseId)
            || ! safeRelativePath($storagePath)) {
            unset($projection[$hostname]);
            $errors[] = 'An active projection contains invalid persisted data.';

            continue;
        }

        if (isset($seenHosts[$hostname])) {
            unset($projection[$hostname]);
            $errors[] = "More than one active release resolves hostname {$hostname}.";

            continue;
        }
        $seenHosts[$hostname] = true;

        $source = realpath($sourceRoot.'/'.$storagePath);
        if ($source === false
            || ! str_starts_with($source.'/', $sourceRootPath.'/')
            || ! hasWebsiteIndex($source)) {
            unset($projection[$hostname]);
            $errors[] = "The active release for {$hostname} is unavailable.";

            continue;
        }

        $publicRelative = $landingId.'/'.$releaseId;
        try {
            publishRelease($source, $releasesRoot.'/'.$publicRelative, $releasesRoot);
        } catch (Throwable $exception) {
            unset($projection[$hostname]);
            $errors[] = "Unable to publish {$hostname}: {$exception->getMessage()}";

            continue;
        }

        $projection[$hostname] = '../releases/'.$publicRelative;
    }

    $publishedReleases = [];
    foreach ($projection as $target) {
        $publishedReleases[substr($target, strlen('../releases/'))] = true;
    }

    return [$projection, $publishedReleases, $errors];
};

do {
    try {
        [$desiredHosts, $desiredReleases, $projectionErrors] = $desiredProjection();
        $changes = reconcileHosts($hostsRoot, $quarantineRoot, $desiredHosts);
        cleanupReleases($releasesRoot, $desiredReleases);
        $globalFailureStartedAt = null;

        if ($projectionErrors === []) {
            writeHeartbeat($heartbeat);
        } else {
            @unlink($heartbeat);
            foreach ($projectionErrors as $projectionError) {
                fwrite(STDERR, sprintf("[%s] projection degraded: %s\n", gmdate(DATE_ATOM), $projectionError));
            }
        }

        if ($changes > 0) {
            fwrite(STDOUT, sprintf("[%s] applied %d host projection change(s)\n", gmdate(DATE_ATOM), $changes));
        }
    } catch (Throwable $exception) {
        $globalFailureStartedAt ??= time();
        fwrite(STDERR, sprintf("[%s] projection sync failed: %s\n", gmdate(DATE_ATOM), $exception->getMessage()));
        DB::disconnect();
        @unlink($heartbeat);

        if (time() - $globalFailureStartedAt >= 30) {
            try {
                $removed = reconcileHosts($hostsRoot, $quarantineRoot, []);
                if ($removed > 0) {
                    fwrite(STDERR, sprintf("[%s] removed %d projection(s) after prolonged global failure\n", gmdate(DATE_ATOM), $removed));
                }
            } catch (Throwable $cleanupException) {
                fwrite(STDERR, sprintf("[%s] fail-closed cleanup failed: %s\n", gmdate(DATE_ATOM), $cleanupException->getMessage()));
            }
        }

        if ($runOnce) {
            exit(1);
        }
    }

    if (! $runOnce && $running) {
        sleep(5);
    }
} while (! $runOnce && $running);

/** @param array<string, string> $desired */
function reconcileHosts(string $hostsRoot, string $quarantineRoot, array $desired): int
{
    $changes = 0;

    // Clear anything except the exact managed symlinks before publishing the
    // desired map. In particular, a regular file or directory named after a
    // host must never remain directly servable by Caddy.
    foreach (scandir($hostsRoot) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..' || str_starts_with($entry, '.')) {
            continue;
        }

        $path = $hostsRoot.'/'.$entry;
        if (is_link($path)) {
            if (! isset($desired[$entry])) {
                if (! unlink($path)) {
                    throw new RuntimeException("Unable to remove stale projection: {$entry}");
                }
                $changes++;
            }

            continue;
        }

        quarantineProjection($path, $entry, $quarantineRoot);
        $changes++;
    }

    foreach ($desired as $hostname => $target) {
        $link = $hostsRoot.'/'.$hostname;
        if (is_link($link) && readlink($link) === $target) {
            continue;
        }

        if (file_exists($link) && ! is_link($link)) {
            quarantineProjection($link, $hostname, $quarantineRoot);
        }

        $temporary = $hostsRoot.'/.'.$hostname.'.'.bin2hex(random_bytes(8)).'.tmp';
        if (! symlink($target, $temporary)) {
            throw new RuntimeException("Unable to stage projection: {$hostname}");
        }

        if (! rename($temporary, $link)) {
            @unlink($temporary);
            throw new RuntimeException("Unable to publish projection: {$hostname}");
        }

        $changes++;
    }

    return $changes;
}

function quarantineProjection(string $path, string $entry, string $quarantineRoot): void
{
    if (! file_exists($path) && ! is_link($path)) {
        return;
    }

    $safeName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $entry) ?: 'invalid';
    $destination = $quarantineRoot.'/'.$safeName.'.'.bin2hex(random_bytes(12));
    if (! rename($path, $destination)) {
        throw new RuntimeException("Unable to quarantine unsafe projection: {$entry}");
    }

    // Quarantine is outside every path mounted into Caddy. Removal is best
    // effort; even an unusual filesystem entry cannot remain web reachable.
    removeManagedTree($destination);
}

function publishRelease(string $source, string $destination, string $releasesRoot): void
{
    if (is_file($destination.'/.fast-landings-release') && hasWebsiteIndex($destination)) {
        return;
    }

    if (file_exists($destination) || is_link($destination)) {
        throw new RuntimeException('The managed release destination already exists but is incomplete.');
    }

    $temporary = $releasesRoot.'/.release.'.bin2hex(random_bytes(12)).'.tmp';
    if (! mkdir($temporary, 0750, true) && ! is_dir($temporary)) {
        throw new RuntimeException('Unable to create a temporary public release.');
    }

    try {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $item) {
            $path = $item->getPathname();
            $relative = substr($path, strlen($source) + 1);
            if (! safeRelativePath($relative) || $item->isLink()) {
                throw new RuntimeException('The private release contains an unsafe filesystem entry.');
            }

            $target = $temporary.'/'.$relative;
            if ($item->isDir()) {
                if (! mkdir($target, 0750, true) && ! is_dir($target)) {
                    throw new RuntimeException('Unable to create a public release directory.');
                }

                continue;
            }

            if (! $item->isFile()) {
                throw new RuntimeException('The private release contains a non-regular file.');
            }

            $parent = dirname($target);
            if (! is_dir($parent) && ! mkdir($parent, 0750, true) && ! is_dir($parent)) {
                throw new RuntimeException('Unable to create a public release directory.');
            }

            // Never hard-link private files: publication must be an immutable
            // snapshot that the PHP application uid cannot mutate in place.
            if (! copy($path, $target) || ! chmod($target, 0640)) {
                throw new RuntimeException('Unable to materialize a public release file.');
            }
        }

        if (! hasWebsiteIndex($temporary)) {
            throw new RuntimeException('The public release has no index.php or index.html.');
        }

        if (file_put_contents($temporary.'/.fast-landings-release', "ready\n", LOCK_EX) === false) {
            throw new RuntimeException('Unable to finalize the public release.');
        }

        $parent = dirname($destination);
        if (! is_dir($parent) && ! mkdir($parent, 0750, true) && ! is_dir($parent)) {
            throw new RuntimeException('Unable to create the public release parent.');
        }
        if (! rename($temporary, $destination)) {
            throw new RuntimeException('Unable to atomically publish the release.');
        }
    } catch (Throwable $exception) {
        removeManagedTree($temporary);
        throw $exception;
    }
}

/** @param array<string, true> $desired */
function cleanupReleases(string $releasesRoot, array $desired): void
{
    foreach (scandir($releasesRoot) ?: [] as $landingId) {
        if (! validUlid($landingId)) {
            continue;
        }

        $landingDirectory = $releasesRoot.'/'.$landingId;
        if (! is_dir($landingDirectory) || is_link($landingDirectory)) {
            continue;
        }

        foreach (scandir($landingDirectory) ?: [] as $releaseId) {
            if (! validUlid($releaseId)) {
                continue;
            }

            $relative = $landingId.'/'.$releaseId;
            if (! isset($desired[$relative])) {
                removeManagedTree($landingDirectory.'/'.$releaseId);
            }
        }

        $remaining = array_values(array_diff(scandir($landingDirectory) ?: [], ['.', '..']));
        if ($remaining === []) {
            @rmdir($landingDirectory);
        }
    }
}

function removeManagedTree(string $path): void
{
    if (is_link($path) || is_file($path)) {
        @unlink($path);

        return;
    }
    if (! is_dir($path)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($iterator as $item) {
        if ($item->isLink() || $item->isFile()) {
            @unlink($item->getPathname());
        } elseif ($item->isDir()) {
            @rmdir($item->getPathname());
        }
    }
    @rmdir($path);
}

function writeHeartbeat(string $heartbeat): void
{
    $temporary = $heartbeat.'.'.bin2hex(random_bytes(8)).'.tmp';
    if (file_put_contents($temporary, (string) time(), LOCK_EX) === false || ! rename($temporary, $heartbeat)) {
        @unlink($temporary);
        throw new RuntimeException('Unable to update the projection heartbeat.');
    }
}

function validHostname(string $hostname): bool
{
    if ($hostname === '' || strlen($hostname) > 253 || ! preg_match('/^[a-z0-9.-]+$/D', $hostname)) {
        return false;
    }

    foreach (explode('.', $hostname) as $label) {
        if ($label === '' || strlen($label) > 63 || ! preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/D', $label)) {
            return false;
        }
    }

    return true;
}

function validUlid(string $value): bool
{
    return (bool) preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/D', strtoupper($value));
}

function hasWebsiteIndex(string $directory): bool
{
    return is_file($directory.'/index.php') || is_file($directory.'/index.html');
}

function safeRelativePath(string $path): bool
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
