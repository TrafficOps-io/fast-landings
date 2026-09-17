#!/bin/sh
set -u

worker=
stopping=0

stop_worker() {
    stopping=1
    if [ -n "$worker" ] && kill -0 "$worker" 2>/dev/null; then
        kill -TERM "$worker" 2>/dev/null || true
    fi
}

fail_closed() {
    php /opt/fast-landings/projection-sync.php --disable >/dev/null 2>&1 || true
}

trap stop_worker HUP INT TERM

php /opt/fast-landings/projection-sync.php &
worker=$!
status=0

while kill -0 "$worker" 2>/dev/null; do
    wait "$worker" || status=$?
done

fail_closed

if [ "$stopping" -eq 1 ]; then
    exit 0
fi
if [ "$status" -eq 0 ]; then
    # The continuous reconciler is not expected to terminate by itself.
    exit 1
fi
exit "$status"
