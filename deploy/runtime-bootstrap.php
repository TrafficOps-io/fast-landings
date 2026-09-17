<?php

declare(strict_types=1);

// Runs inside the dedicated landing container, before each uploaded script.
// Its application image and database credentials are never present here.
(static function (): void {
    $root = realpath((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''));
    $script = realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? ''));
    if ($root === false || $script === false
        || ! str_starts_with($root.'/', '/srv/landings/')
        || $root === '/srv/landings'
        || ! str_starts_with($script, $root.'/')
        || ! str_ends_with($script, '.php')
        || ! is_file($script)) {
        http_response_code(404);
        exit;
    }

    // A release gets its own temporary and session files, outside the public
    // document root. They deliberately expire when the container is recreated.
    $temporary = '/tmp/sites/'.hash('sha256', $root);
    $sessions = $temporary.'/sessions';
    if (! is_dir($sessions) && ! mkdir($sessions, 0700, true) && ! is_dir($sessions)) {
        http_response_code(503);
        exit;
    }

    $allowed = [$root, $temporary];
    $collectUpload = static function (mixed $value) use (&$collectUpload, &$allowed): void {
        if (is_array($value)) {
            foreach ($value as $child) {
                $collectUpload($child);
            }
        } elseif (is_string($value) && is_uploaded_file($value)) {
            // PHP parses multipart bodies before auto_prepend_file. Allow only
            // this request's files, preserving normal read/move_uploaded_file.
            $allowed[] = $value;
        }
    };
    foreach ($_FILES as $upload) {
        $collectUpload($upload['tmp_name'] ?? null);
    }

    $_SERVER['DOCUMENT_ROOT'] = $root;
    $_SERVER['FAST_LANDINGS_TMP_DIR'] = $temporary;
    putenv('TMPDIR='.$temporary);
    ini_set('session.save_path', $sessions);
    ini_set('session.cookie_secure', ($_SERVER['HTTPS'] ?? '') === 'on' ? '1' : '0');
    chdir(dirname($script));
    // PHP permits later tightening of open_basedir, never widening it. Keep
    // shared /tmp, neighbouring releases and the runtime itself outside it.
    if (ini_set('open_basedir', implode(PATH_SEPARATOR, $allowed)) === false) {
        http_response_code(503);
        exit;
    }
})();
