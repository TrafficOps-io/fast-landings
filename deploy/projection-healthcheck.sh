#!/bin/sh
set -eu

heartbeat=${FAST_LANDINGS_PUBLIC_PATH:-/var/lib/fast-landings/public}/hosts/.projection-heartbeat

exec php -r '
$heartbeat = $argv[1];
$modified = @filemtime($heartbeat);
exit($modified !== false && time() - $modified <= 20 ? 0 : 1);
' "$heartbeat"
