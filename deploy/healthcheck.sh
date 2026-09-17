#!/bin/sh
set -eu

exec php -r '
$socket = @fsockopen("127.0.0.1", 9000, $errorCode, $errorMessage, 2);
if ($socket === false) {
    fwrite(STDERR, $errorMessage." (".$errorCode.")\n");
    exit(1);
}
fclose($socket);
'
