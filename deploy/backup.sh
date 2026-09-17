#!/bin/sh
set -eu

umask 077

PATH_FILE=/etc/fast-landings-home
[ "$(id -u)" -eq 0 ] || {
    echo "Run fast-landings backup as root." >&2
    exit 1
}
[ -r "$PATH_FILE" ] || {
    echo "Fast Landings installation metadata was not found." >&2
    exit 1
}

contains_control_character() {
    LC_ALL=C printf '%s' "$1" | od -An -tu1 | awk '
        {
            for (field_index = 1; field_index <= NF; field_index++) {
                if ($field_index < 32 || $field_index == 127) {
                    found = 1
                }
            }
        }
        END { exit found ? 0 : 1 }
    '
}

safe_absolute_path() {
    contains_control_character "$1" && return 1
    case "$1" in
        /|/bin|/boot|/dev|/etc|/home|/lib|/lib64|/media|/mnt|/opt|/proc|/root|/run|/sbin|/srv|/sys|/tmp|/usr|/usr/local|/var|/var/backups|/var/lib|'') return 1 ;;
        /tmp/*|/var/tmp/*) return 1 ;;
        /*) ;;
        *) return 1 ;;
    esac
    case "$1" in
        *//*|*/./*|*/.|*/../*|*/..) return 1 ;;
    esac
    printf '%s' "$1" | grep -Eq '^/[A-Za-z0-9._/-]+$'
}

has_no_symlink_component() (
    current=
    old_ifs=$IFS
    IFS=/
    set -f
    for component in $1; do
        [ -n "$component" ] || continue
        current=$current/$component
        [ ! -L "$current" ] || exit 1
    done
    IFS=$old_ifs
)

canonical_path() {
    command -v realpath >/dev/null 2>&1 || {
        echo "GNU realpath is required for safe path validation." >&2
        exit 1
    }
    realpath -m -- "$1"
}

INSTALL_ROOT=$(sed -n '1p' "$PATH_FILE")
safe_absolute_path "$INSTALL_ROOT" && has_no_symlink_component "$INSTALL_ROOT" || {
    echo "Fast Landings installation path is unsafe." >&2
    exit 1
}
INSTALL_ROOT=$(canonical_path "$INSTALL_ROOT")
RELEASE_ENV=$INSTALL_ROOT/release.env
COMPOSE_FILE=$INSTALL_ROOT/compose.yaml

env_value() {
    key=$1
    awk -v wanted="$key" '
        index($0, "=") > 0 && substr($0, 1, index($0, "=") - 1) == wanted {
            print substr($0, index($0, "=") + 1)
            exit
        }
    ' "$RELEASE_ENV"
}

DATA_ROOT=$(env_value FAST_LANDINGS_DATA_DIR)
BACKUP_ROOT=${FAST_LANDINGS_BACKUP_DIR:-/var/backups/fast-landings}

safe_absolute_path "$DATA_ROOT" && has_no_symlink_component "$DATA_ROOT" || {
    echo "Persistent data path is unsafe." >&2
    exit 1
}
safe_absolute_path "$BACKUP_ROOT" && has_no_symlink_component "$BACKUP_ROOT" || {
    echo "Backup path is unsafe." >&2
    exit 1
}
DATA_ROOT=$(canonical_path "$DATA_ROOT")
BACKUP_ROOT=$(canonical_path "$BACKUP_ROOT")

case "$BACKUP_ROOT/" in
    "$DATA_ROOT/"*|"$INSTALL_ROOT/"*) echo "Backup path must be outside application data and metadata." >&2; exit 1 ;;
esac
case "$DATA_ROOT/" in "$BACKUP_ROOT/"*) echo "Backup path cannot contain application data." >&2; exit 1 ;; esac
case "$INSTALL_ROOT/" in "$BACKUP_ROOT/"*) echo "Backup path cannot contain deployment metadata." >&2; exit 1 ;; esac

compose() {
    env \
        -u COMPOSE_FILE \
        -u COMPOSE_PROFILES \
        -u COMPOSE_PROJECT_NAME \
        -u APP_IMAGE \
        -u WEB_IMAGE \
        -u LANDING_PHP_IMAGE \
        -u POSTGRES_IMAGE \
        -u CADDY_IMAGE \
        -u PHP_VERSION \
        -u NODE_VERSION \
        -u NGINX_VERSION \
        -u FAST_LANDINGS_SOURCE_DIR \
        -u FAST_LANDINGS_DATA_DIR \
        -u FAST_LANDINGS_APP_ENV \
        -u FAST_LANDINGS_EDGE_ENV \
        -u FAST_LANDINGS_ADMIN_ENV \
        -u FAST_LANDINGS_POSTGRES_PASSWORD_FILE \
        -u FAST_LANDINGS_CADDYFILE \
        -u FAST_LANDINGS_ADMIN_PASSWORD \
        -u PANEL_HOST \
        -u SYSTEM_HOST_SUFFIX \
        -u ORIGIN_HOST \
        -u ACME_EMAIL \
        -u ADMIN_EMAIL \
        -u ADMIN_NAME \
        docker compose --env-file "$RELEASE_ENV" --file "$COMPOSE_FILE" "$@"
}

mkdir -p "$BACKUP_ROOT"
chmod 0700 "$BACKUP_ROOT"

LOCK_DIR=$INSTALL_ROOT/.state/backup.lock
if ! mkdir "$LOCK_DIR" 2>/dev/null; then
    echo "Another Fast Landings backup is already running." >&2
    exit 1
fi

timestamp=$(date -u +%Y%m%dT%H%M%SZ)
stage=$BACKUP_ROOT/.fast-landings-$timestamp.$$
archive_tmp=$BACKUP_ROOT/.fast-landings-$timestamp.tar.gz.$$
archive=$BACKUP_ROOT/fast-landings-$timestamp.tar.gz
services_stopped=0
maintenance_enabled=0

cleanup() {
    status=$?
    trap - 0

    if [ "$maintenance_enabled" -eq 1 ]; then
        compose up --detach app >/dev/null 2>&1 || true
        if compose exec -T app php artisan up >/dev/null 2>&1; then
            maintenance_enabled=0
        else
            rm -f "$DATA_ROOT/laravel-storage/framework/down"
        fi
    fi
    if [ "$services_stopped" -eq 1 ]; then
        compose up --detach --remove-orphans --wait --wait-timeout 240 >/dev/null 2>&1 || true
    fi

    case "$stage" in "$BACKUP_ROOT"/.fast-landings-*) rm -rf "$stage" ;; esac
    case "$archive_tmp" in "$BACKUP_ROOT"/.fast-landings-*) rm -f "$archive_tmp" ;; esac
    rmdir "$LOCK_DIR" 2>/dev/null || true

    exit "$status"
}

trap cleanup 0
trap 'exit 1' HUP INT TERM

mkdir -p "$stage/database" "$stage/data" "$stage/deployment"
chmod 0700 "$stage"

echo "Putting the panel into maintenance mode..."
maintenance_enabled=1
compose exec -T app php artisan down --retry=60 >/dev/null

services_stopped=1
compose stop --timeout 150 edge web landing-php queue scheduler projection app >/dev/null

echo "Dumping PostgreSQL..."
compose exec -T --user postgres postgres \
    pg_dump --format=custom --no-owner --no-acl --username=fast_landings --dbname=fast_landings \
    > "$stage/database/fast-landings.dump"
compose exec -T --user postgres postgres \
    pg_dumpall --globals-only --username=fast_landings \
    > "$stage/database/globals.sql"

echo "Archiving persistent files and deployment metadata..."
tar -C "$DATA_ROOT" \
    --exclude=postgres \
    --exclude=laravel-storage/framework/down \
    -czf "$stage/data/persistent-files.tar.gz" \
    laravel-storage caddy-data caddy-config landings

cp "$INSTALL_ROOT/compose.yaml" "$INSTALL_ROOT/Caddyfile" "$INSTALL_ROOT/release.env" "$INSTALL_ROOT/backup.sh" "$stage/deployment/"
cp -R "$INSTALL_ROOT/secrets" "$stage/deployment/secrets"
tar -C "$INSTALL_ROOT" -czf "$stage/deployment/source.tar.gz" source
if [ -f /usr/local/sbin/fast-landings ]; then
    cp /usr/local/sbin/fast-landings "$stage/deployment/operator"
fi

cat > "$stage/manifest.txt" <<EOF
created_at=$timestamp
database=PostgreSQL custom dump
application_data=persistent-files.tar.gz
deployment_metadata=deployment/
immutable_source=deployment/source.tar.gz
warning=This archive contains APP_KEY and database credentials; keep it encrypted and off-host.
EOF

if command -v sha256sum >/dev/null 2>&1; then
    (
        cd "$stage"
        sha256sum database/fast-landings.dump database/globals.sql data/persistent-files.tar.gz > SHA256SUMS
    )
fi

tar -C "$stage" -czf "$archive_tmp" .
chmod 0600 "$archive_tmp"
mv "$archive_tmp" "$archive"

compose up --detach app >/dev/null
compose exec -T app php artisan up >/dev/null
maintenance_enabled=0
compose up --detach --remove-orphans --wait --wait-timeout 240 >/dev/null
services_stopped=0

echo "Backup created: $archive"
echo "It contains secrets. Encrypt it and copy it off this server now."
