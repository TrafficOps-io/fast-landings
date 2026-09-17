#!/bin/sh
set -eu

umask 027

for directory in \
    storage/app/private \
    storage/app/public \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    /tmp/uploads
do
    mkdir -p "$directory"
done

if ! test -w storage/framework/views; then
    echo "Fast Landings storage is not writable by uid $(id -u)." >&2
    exit 1
fi

exec "$@"
