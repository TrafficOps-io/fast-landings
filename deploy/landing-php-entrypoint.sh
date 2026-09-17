#!/bin/sh
set -eu
umask 077
mkdir -p /tmp/uploads /tmp/sites
exec "$@"
