#!/bin/sh
set -eu

umask 077

PROGRAM=${0##*/}
SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
DISCOVERED_SOURCE=$SCRIPT_DIR

INSTALL_ROOT=${FAST_LANDINGS_INSTALL_DIR:-/opt/fast-landings}
DATA_ROOT=${FAST_LANDINGS_DATA_DIR:-/var/lib/fast-landings}
SOURCE_ROOT=${FAST_LANDINGS_SOURCE_DIR:-$DISCOVERED_SOURCE}
RELEASE_URL=${FAST_LANDINGS_RELEASE_URL:-}
RELEASE_SHA256=${FAST_LANDINGS_RELEASE_SHA256:-}
NON_INTERACTIVE=${FAST_LANDINGS_NON_INTERACTIVE:-0}
RESUME=0

DATA_ROOT_REQUESTED=0
SOURCE_ROOT_REQUESTED=0
PANEL_HOST_REQUESTED=0
SYSTEM_HOST_REQUESTED=0
ORIGIN_HOST_REQUESTED=0
ACME_EMAIL_REQUESTED=0
ADMIN_EMAIL_REQUESTED=0
ADMIN_NAME_REQUESTED=0
[ "${FAST_LANDINGS_DATA_DIR+x}" = x ] && DATA_ROOT_REQUESTED=1
[ "${FAST_LANDINGS_SOURCE_DIR+x}" = x ] && SOURCE_ROOT_REQUESTED=1
[ "${FAST_LANDINGS_PANEL_DOMAIN+x}" = x ] && PANEL_HOST_REQUESTED=1
[ "${FAST_LANDINGS_SYSTEM_DOMAIN+x}" = x ] && SYSTEM_HOST_REQUESTED=1
[ "${FAST_LANDINGS_ORIGIN_TARGET+x}" = x ] && ORIGIN_HOST_REQUESTED=1
[ "${FAST_LANDINGS_ACME_EMAIL+x}" = x ] && ACME_EMAIL_REQUESTED=1
[ "${FAST_LANDINGS_ADMIN_EMAIL+x}" = x ] && ADMIN_EMAIL_REQUESTED=1
[ "${FAST_LANDINGS_ADMIN_NAME+x}" = x ] && ADMIN_NAME_REQUESTED=1

PANEL_HOST=${FAST_LANDINGS_PANEL_DOMAIN:-}
SYSTEM_HOST_SUFFIX=${FAST_LANDINGS_SYSTEM_DOMAIN:-}
ORIGIN_HOST=${FAST_LANDINGS_ORIGIN_TARGET:-}
ACME_EMAIL=${FAST_LANDINGS_ACME_EMAIL:-}
ADMIN_EMAIL=${FAST_LANDINGS_ADMIN_EMAIL:-}
ADMIN_NAME=${FAST_LANDINGS_ADMIN_NAME:-Administrator}
ADMIN_PASSWORD=${FAST_LANDINGS_ADMIN_PASSWORD:-}
unset FAST_LANDINGS_ADMIN_PASSWORD || true

TTY_ECHO_DISABLED=0
ADMIN_ENV_FILE=
TEMP_SOURCE_DIR=
DOWNLOADED_SOURCE_DIR=

log() {
    printf '%s\n' "[fast-landings] $*"
}

warn() {
    printf '%s\n' "[fast-landings] WARNING: $*" >&2
}

die() {
    printf '%s\n' "[fast-landings] ERROR: $*" >&2
    exit 1
}

usage() {
    cat <<'USAGE'
Usage: sudo ./install.sh [options]

Options:
  --panel-host HOST          Administration hostname (for example panel.example.com)
  --system-host-suffix HOST System landing suffix (for example go.example.com)
  --origin-target IP_OR_HOST Public server IPv4/IPv6 (A/AAAA) or hostname (CNAME)
  --origin-host IP_OR_HOST   Backwards-compatible alias for --origin-target
  --acme-email EMAIL         ACME expiry/contact email
  --admin-email EMAIL        Initial administrator login
  --admin-name NAME          Initial administrator display name
  --source-dir PATH          Repository/release source directory
  --release-url HTTPS_URL    Download source when no checkout is present
  --release-sha256 SHA256    Required SHA-256 for --release-url
  --install-dir PATH         Deployment metadata directory (default /opt/fast-landings)
  --data-dir PATH            Persistent data directory (default /var/lib/fast-landings)
  --non-interactive          Never prompt; require missing values through environment
  --resume                   Resume an interrupted existing installation
  --help                     Show this help

The administrator password is intentionally not accepted as an argument. Set
FAST_LANDINGS_ADMIN_PASSWORD for unattended installation, or enter it at the TTY.
USAGE
}

need_value() {
    [ "$#" -ge 2 ] || die "Missing value for $1."
}

while [ "$#" -gt 0 ]; do
    case "$1" in
        --panel-host)
            need_value "$@"
            PANEL_HOST=$2
            PANEL_HOST_REQUESTED=1
            shift 2
            ;;
        --system-host-suffix)
            need_value "$@"
            SYSTEM_HOST_SUFFIX=$2
            SYSTEM_HOST_REQUESTED=1
            shift 2
            ;;
        --origin-target|--origin-host)
            need_value "$@"
            ORIGIN_HOST=$2
            ORIGIN_HOST_REQUESTED=1
            shift 2
            ;;
        --acme-email)
            need_value "$@"
            ACME_EMAIL=$2
            ACME_EMAIL_REQUESTED=1
            shift 2
            ;;
        --admin-email)
            need_value "$@"
            ADMIN_EMAIL=$2
            ADMIN_EMAIL_REQUESTED=1
            shift 2
            ;;
        --admin-name)
            need_value "$@"
            ADMIN_NAME=$2
            ADMIN_NAME_REQUESTED=1
            shift 2
            ;;
        --source-dir)
            need_value "$@"
            SOURCE_ROOT=$2
            SOURCE_ROOT_REQUESTED=1
            shift 2
            ;;
        --release-url)
            need_value "$@"
            RELEASE_URL=$2
            shift 2
            ;;
        --release-sha256)
            need_value "$@"
            RELEASE_SHA256=$2
            shift 2
            ;;
        --install-dir)
            need_value "$@"
            INSTALL_ROOT=$2
            shift 2
            ;;
        --data-dir)
            need_value "$@"
            DATA_ROOT=$2
            DATA_ROOT_REQUESTED=1
            shift 2
            ;;
        --non-interactive)
            NON_INTERACTIVE=1
            shift
            ;;
        --resume)
            RESUME=1
            shift
            ;;
        --help|-h)
            usage
            exit 0
            ;;
        --admin-password|--admin-password=*)
            die "Do not pass a password on the command line; use a TTY or FAST_LANDINGS_ADMIN_PASSWORD."
            ;;
        *)
            die "Unknown option: $1"
            ;;
    esac
done

cleanup() {
    if [ "$TTY_ECHO_DISABLED" -eq 1 ] && [ -r /dev/tty ]; then
        stty echo < /dev/tty 2>/dev/null || true
        printf '\n' > /dev/tty
    fi

    if [ -n "$ADMIN_ENV_FILE" ] && [ -f "$ADMIN_ENV_FILE" ]; then
        : > "$ADMIN_ENV_FILE"
        chmod 0600 "$ADMIN_ENV_FILE" 2>/dev/null || true
    fi

    if [ -n "$TEMP_SOURCE_DIR" ]; then
        case "$TEMP_SOURCE_DIR" in
            "$INSTALL_ROOT"/.source.*) rm -rf "$TEMP_SOURCE_DIR" ;;
        esac
    fi

    if [ -n "$DOWNLOADED_SOURCE_DIR" ]; then
        case "$DOWNLOADED_SOURCE_DIR" in
            /tmp/fast-landings-release.*) rm -rf "$DOWNLOADED_SOURCE_DIR" ;;
        esac
    fi
}

trap cleanup 0
trap 'exit 1' HUP INT TERM

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
        /|/bin|/boot|/dev|/etc|/home|/lib|/lib64|/media|/mnt|/opt|/proc|/root|/run|/sbin|/srv|/sys|/tmp|/usr|/usr/local|/var|/var/backups|/var/lib) return 1 ;;
        /*) ;;
        *) return 1 ;;
    esac

    printf '%s' "$1" | grep -Eq '^/[A-Za-z0-9._/-]+$'
}

raw_path_is_safe() {
    case "$1" in
        /*) ;;
        *) return 1 ;;
    esac
    case "$1" in
        *//*|*/./*|*/.|*/../*|*/..) return 1 ;;
    esac
    safe_absolute_path "$1"
}

managed_path_is_safe() {
    raw_path_is_safe "$1" || return 1
    case "$1" in
        /tmp/*|/var/tmp/*) return 1 ;;
    esac
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
    command -v realpath >/dev/null 2>&1 || die "GNU realpath is required for safe path validation."
    realpath -m -- "$1"
}

path_component_is_root_protected() {
    [ -d "$1" ] && [ ! -L "$1" ] || return 1
    metadata=$(stat -c '%u:%a' -- "$1" 2>/dev/null) || return 1
    owner=${metadata%%:*}
    mode=${metadata#*:}
    [ "$owner" = 0 ] || return 1
    case "$mode" in ''|*[!0-7]*) return 1 ;; esac
    [ $((0$mode & 022)) -eq 0 ]
}

# Validate top-down so every directory entry used to reach the nearest existing
# ancestor is controlled by root. That makes the subsequent root-owned mkdir
# safe from an unprivileged symlink/rename race.
secure_managed_path_chain() (
    target=$1
    require_complete=${2:-0}
    current=
    missing=0

    path_component_is_root_protected / || exit 1

    old_ifs=$IFS
    IFS=/
    set -f
    for component in $target; do
        [ -n "$component" ] || continue
        current=$current/$component

        if [ "$missing" -eq 1 ]; then
            [ ! -e "$current" ] && [ ! -L "$current" ] || exit 1
            continue
        fi

        if [ -e "$current" ] || [ -L "$current" ]; then
            path_component_is_root_protected "$current" || exit 1
        else
            missing=1
        fi
    done
    IFS=$old_ifs

    [ "$require_complete" -eq 0 ] || [ "$missing" -eq 0 ]
)

revalidate_created_managed_path() {
    secure_managed_path_chain "$1" 1 || return 1
    [ "$(canonical_path "$1")" = "$1" ]
}

validate_hostname() {
    contains_control_character "$1" && return 1
    printf '%s\n' "$1" | awk '
        length($0) < 1 || length($0) > 253 { exit 1 }
        $0 !~ /^[a-z0-9.-]+$/ { exit 1 }
        {
            count = split($0, labels, ".")
            for (label_index = 1; label_index <= count; label_index++) {
                if (length(labels[label_index]) < 1 || length(labels[label_index]) > 63 ||
                    labels[label_index] !~ /^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/) {
                    exit 1
                }
            }
        }
    '
}

normalize_origin_target() {
    normalized_target=$(printf '%s' "$1" | tr '[:upper:]' '[:lower:]')
    case "$normalized_target" in
        *:*) ;;
        *[!0-9.]*) normalized_target=${normalized_target%.} ;;
    esac
    printf '%s' "$normalized_target"
}

ip_record_type() {
    contains_control_character "$1" && return 1
    printf '%s\n' "$1" | awk '
        function ipv4(value, octets, size, position) {
            size = split(value, octets, ".")
            if (size != 4) return 0
            for (position = 1; position <= size; position++) {
                if (octets[position] !~ /^[0-9]+$/ || length(octets[position]) > 3 ||
                    (length(octets[position]) > 1 && substr(octets[position], 1, 1) == "0") ||
                    octets[position] + 0 > 255) return 0
            }
            return 1
        }
        function groups(value, parts, size, position) {
            if (value == "") return 0
            size = split(value, parts, ":")
            for (position = 1; position <= size; position++) {
                if (parts[position] !~ /^[0-9a-fA-F]+$/ || length(parts[position]) > 4) return -1
            }
            return size
        }
        {
            value = $0
            if (index(value, ":") == 0) {
                if (!ipv4(value)) exit 1
                print "A"
                exit
            }
            if (value !~ /^[0-9a-fA-F:.]+$/ || index(value, ":::") > 0) exit 1
            if (index(value, ".") > 0) {
                if (!match(value, /:[^:]*$/)) exit 1
                tail_start = RSTART
                if (!ipv4(substr(value, tail_start + 1))) exit 1
                value = substr(value, 1, tail_start) "0:0"
            }
            halves_count = split(value, halves, "::")
            if (halves_count > 2) exit 1
            if (halves_count == 2) {
                left_groups = groups(halves[1])
                right_groups = groups(halves[2])
                if (left_groups < 0 || right_groups < 0 || left_groups + right_groups >= 8) exit 1
            } else if (groups(value) != 8) exit 1
            print "AAAA"
        }
    '
}

origin_record_type() {
    case "$1" in
        *:*) ip_record_type "$1" ;;
        *[!0-9.]*) validate_hostname "$1" && printf '%s\n' CNAME ;;
        *) ip_record_type "$1" ;;
    esac
}

validate_email() {
    contains_control_character "$1" && return 1
    printf '%s\n' "$1" | grep -Eq '^[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,63}$'
}

env_value() {
    key=$1
    file=$2
    awk -v wanted="$key" '
        index($0, "=") > 0 && substr($0, 1, index($0, "=") - 1) == wanted {
            print substr($0, index($0, "=") + 1)
            exit
        }
    ' "$file"
}

prompt_value() {
    label=$1
    default_value=${2:-}

    [ "$NON_INTERACTIVE" -eq 0 ] || die "$label is required in non-interactive mode."
    [ -r /dev/tty ] || die "A controlling TTY is required for interactive installation."

    if [ -n "$default_value" ]; then
        printf '%s [%s]: ' "$label" "$default_value" > /dev/tty
    else
        printf '%s: ' "$label" > /dev/tty
    fi

    IFS= read -r answer < /dev/tty || die "Unable to read $label."
    if [ -z "$answer" ]; then
        answer=$default_value
    fi
    printf '%s' "$answer"
}

prompt_password() {
    [ "$NON_INTERACTIVE" -eq 0 ] || die "FAST_LANDINGS_ADMIN_PASSWORD is required in non-interactive mode."
    [ -r /dev/tty ] || die "A controlling TTY is required to read the administrator password."

    printf 'Administrator password (minimum 12 characters): ' > /dev/tty
    stty -echo < /dev/tty
    TTY_ECHO_DISABLED=1
    IFS= read -r first < /dev/tty || die "Unable to read the administrator password."
    stty echo < /dev/tty
    TTY_ECHO_DISABLED=0
    printf '\nConfirm administrator password: ' > /dev/tty
    stty -echo < /dev/tty
    TTY_ECHO_DISABLED=1
    IFS= read -r second < /dev/tty || die "Unable to confirm the administrator password."
    stty echo < /dev/tty
    TTY_ECHO_DISABLED=0
    printf '\n' > /dev/tty

    [ "$first" = "$second" ] || die "Administrator passwords do not match."
    printf '%s' "$first"
}

source_layout_valid() {
    [ -f "$1/package.json" ] \
        && [ -f "$1/package-lock.json" ] \
        && [ -f "$1/composer.lock" ] \
        && [ -f "$1/deploy/compose.yaml" ] \
        && [ -f "$1/resources/js/vendor/trafficops-template-language.cjs" ] \
        && [ -d "$1/packages/ui/src" ]
}

validate_source() {
    source_layout_valid "$SOURCE_ROOT" || die "A complete Fast Landings source bundle was not found under $SOURCE_ROOT."
}

ensure_release_tools() {
    missing_tools=
    for tool in curl sha256sum tar; do
        command -v "$tool" >/dev/null 2>&1 || missing_tools="$missing_tools $tool"
    done
    [ -z "$missing_tools" ] && return

    command -v apt-get >/dev/null 2>&1 || die "Missing release download tools:$missing_tools"
    log "Installing verified-release download tools..."
    apt-get update
    DEBIAN_FRONTEND=noninteractive apt-get install -y ca-certificates coreutils curl tar
}

prepare_source() {
    if [ -z "$RELEASE_URL" ] && source_layout_valid "$SOURCE_ROOT"; then
        return
    fi

    [ -n "$RELEASE_URL" ] || die "No local source bundle was found. Set FAST_LANDINGS_RELEASE_URL and FAST_LANDINGS_RELEASE_SHA256 for standalone installation."
    [ "$SOURCE_ROOT_REQUESTED" -eq 0 ] || die "Use either --source-dir or --release-url, not both."
    contains_control_character "$RELEASE_URL" && die "Release URL contains control characters."
    case "$RELEASE_URL" in https://*) ;; *) die "Release archives must use HTTPS." ;; esac
    RELEASE_SHA256=$(printf '%s' "$RELEASE_SHA256" | tr '[:upper:]' '[:lower:]')
    printf '%s\n' "$RELEASE_SHA256" | grep -Eq '^[a-f0-9]{64}$' || die "A 64-character FAST_LANDINGS_RELEASE_SHA256 is required."

    ensure_release_tools
    DOWNLOADED_SOURCE_DIR=$(mktemp -d /tmp/fast-landings-release.XXXXXX)
    archive=$DOWNLOADED_SOURCE_DIR/release.tar.gz
    extracted=$DOWNLOADED_SOURCE_DIR/extracted
    listing=$DOWNLOADED_SOURCE_DIR/archive.list
    mkdir -m 0700 "$extracted"

    log "Downloading the pinned Fast Landings release archive..."
    curl --fail --location --silent --show-error \
        --proto '=https' --proto-redir '=https' \
        --connect-timeout 20 --retry 3 \
        --output "$archive" "$RELEASE_URL"
    actual_sha256=$(sha256sum "$archive" | awk '{ print $1 }')
    [ "$actual_sha256" = "$RELEASE_SHA256" ] || die "Release archive SHA-256 does not match."

    LC_ALL=C tar -tvzf "$archive" > "$listing"
    awk '$1 !~ /^[-d]/ { exit 1 }' "$listing" || die "Release archive contains links or special filesystem entries."
    tar --extract --gzip --file "$archive" --directory "$extracted" \
        --no-same-owner --no-same-permissions

    if source_layout_valid "$extracted"; then
        SOURCE_ROOT=$extracted
    else
        candidate=
        candidate_count=0
        for entry in "$extracted"/*; do
            [ -d "$entry" ] || continue
            candidate=$entry
            candidate_count=$((candidate_count + 1))
        done
        [ "$candidate_count" -eq 1 ] && source_layout_valid "$candidate" \
            || die "Release archive does not contain exactly one valid source bundle."
        SOURCE_ROOT=$candidate
    fi
}

install_docker() {
    command -v apt-get >/dev/null 2>&1 || die "Install Docker Engine and Docker Compose v2, then re-run with --resume."
    [ -r /etc/os-release ] || die "Cannot identify this Linux distribution."

    os_id=$(sed -n 's/^ID=//p' /etc/os-release | tr -d '"' | head -n 1)
    codename=$(sed -n 's/^VERSION_CODENAME=//p' /etc/os-release | tr -d '"' | head -n 1)
    case "$os_id" in
        ubuntu|debian) ;;
        *) die "Automatic Docker installation supports Ubuntu and Debian only." ;;
    esac
    [ -n "$codename" ] || die "The distribution codename is missing from /etc/os-release."

    log "Installing Docker Engine and Compose from Docker's signed apt repository..."
    apt-get update
    DEBIAN_FRONTEND=noninteractive apt-get install -y ca-certificates curl gnupg openssl
    install -m 0755 -d /etc/apt/keyrings
    key_tmp=$(mktemp)
    curl -fsSL "https://download.docker.com/linux/$os_id/gpg" -o "$key_tmp"
    gpg --batch --yes --dearmor -o /etc/apt/keyrings/docker.gpg "$key_tmp"
    rm -f "$key_tmp"
    chmod 0644 /etc/apt/keyrings/docker.gpg

    architecture=$(dpkg --print-architecture)
    printf 'deb [arch=%s signed-by=/etc/apt/keyrings/docker.gpg] https://download.docker.com/linux/%s %s stable\n' \
        "$architecture" "$os_id" "$codename" > /etc/apt/sources.list.d/docker.list
    apt-get update
    DEBIAN_FRONTEND=noninteractive apt-get install -y \
        docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
}

ensure_runtime() {
    if ! command -v docker >/dev/null 2>&1 || ! docker compose version >/dev/null 2>&1; then
        install_docker
    fi

    if command -v systemctl >/dev/null 2>&1; then
        systemctl enable --now docker
    fi

    docker info >/dev/null 2>&1 || die "Docker daemon is not available."
    docker compose version >/dev/null 2>&1 || die "Docker Compose v2 is not available."
    command -v openssl >/dev/null 2>&1 || die "openssl is required to generate installation secrets."
}

copy_source_bundle() {
    destination=$INSTALL_ROOT/source
    if [ -d "$destination" ]; then
        log "Using existing immutable source bundle at $destination."
        return
    fi

    TEMP_SOURCE_DIR=$INSTALL_ROOT/.source.$$
    mkdir -p "$TEMP_SOURCE_DIR/packages/ui"

    for directory in app bootstrap config database public resources routes deploy scripts; do
        cp -R "$SOURCE_ROOT/$directory" "$TEMP_SOURCE_DIR/$directory"
    done
    for file in artisan composer.json composer.lock package.json package-lock.json vite.config.js install.sh README.md LICENSE; do
        if [ -f "$SOURCE_ROOT/$file" ]; then
            cp "$SOURCE_ROOT/$file" "$TEMP_SOURCE_DIR/$file"
        fi
    done

    for directory in resources src; do
        cp -R "$SOURCE_ROOT/packages/ui/$directory" "$TEMP_SOURCE_DIR/packages/ui/$directory"
    done
    cp "$SOURCE_ROOT/packages/ui/composer.json" "$SOURCE_ROOT/packages/ui/package.json" "$TEMP_SOURCE_DIR/packages/ui/"

    mv "$TEMP_SOURCE_DIR" "$destination"
    TEMP_SOURCE_DIR=
}

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
        -u FAST_LANDINGS_RELEASE_URL \
        -u FAST_LANDINGS_RELEASE_SHA256 \
        -u PANEL_HOST \
        -u SYSTEM_HOST_SUFFIX \
        -u ORIGIN_HOST \
        -u ACME_EMAIL \
        -u ADMIN_EMAIL \
        -u ADMIN_NAME \
        docker compose \
        --env-file "$INSTALL_ROOT/release.env" \
        --file "$INSTALL_ROOT/compose.yaml" \
        "$@"
}

show_failure_logs() {
    compose ps >&2 || true
    compose logs --tail 80 postgres app queue scheduler projection web edge landing-php >&2 || true
}

[ "$(id -u)" -eq 0 ] || die "Run this installer as root (for example with sudo)."
[ "$(uname -s)" = Linux ] || die "Production installation is supported on Linux only."

case "$(uname -m)" in
    x86_64|aarch64|arm64) ;;
    *) die "Supported architectures are amd64 and arm64." ;;
esac

case "$NON_INTERACTIVE" in 0|1) ;; *) die "FAST_LANDINGS_NON_INTERACTIVE must be 0 or 1." ;; esac

managed_path_is_safe "$INSTALL_ROOT" || die "Unsafe installation path: $INSTALL_ROOT"
secure_managed_path_chain "$INSTALL_ROOT" || die "Installation path has an untrusted, writable, or non-root-owned ancestor."

INSTALL_ROOT=$(canonical_path "$INSTALL_ROOT")
managed_path_is_safe "$INSTALL_ROOT" || die "Unsafe canonical installation path: $INSTALL_ROOT"
secure_managed_path_chain "$INSTALL_ROOT" || die "Canonical installation path is not root-protected."

if [ -e "$INSTALL_ROOT" ] && [ ! -f "$INSTALL_ROOT/release.env" ] && [ "$RESUME" -ne 1 ]; then
    die "$INSTALL_ROOT already exists. Inspect it, then re-run with --resume if it belongs to Fast Landings."
fi
if [ -f "$INSTALL_ROOT/release.env" ] && [ "$RESUME" -ne 1 ]; then
    die "Fast Landings is already installed. Use --resume to finish an interrupted installation."
fi

if [ -f "$INSTALL_ROOT/release.env" ]; then
    [ -z "$RELEASE_URL" ] && [ -z "$RELEASE_SHA256" ] || die "A resumed installation always uses its stored immutable source bundle."
    stored_data=$(env_value FAST_LANDINGS_DATA_DIR "$INSTALL_ROOT/release.env")
    stored_source=$(env_value FAST_LANDINGS_SOURCE_DIR "$INSTALL_ROOT/release.env")
    managed_path_is_safe "$stored_data" || die "Stored data path is unsafe."
    raw_path_is_safe "$stored_source" || die "Stored source path is unsafe."
    secure_managed_path_chain "$stored_data" || die "Stored data path is not root-protected."
    has_no_symlink_component "$stored_source" || die "Stored source path contains a symlink component."
    stored_data=$(canonical_path "$stored_data")
    stored_source=$(canonical_path "$stored_source")
    managed_path_is_safe "$stored_data" || die "Stored canonical data path is unsafe."
    safe_absolute_path "$stored_source" || die "Stored canonical source path is unsafe."
    secure_managed_path_chain "$stored_data" || die "Stored canonical data path is not root-protected."

    if [ "$DATA_ROOT_REQUESTED" -eq 1 ]; then
        managed_path_is_safe "$DATA_ROOT" && secure_managed_path_chain "$DATA_ROOT" || die "Requested data path is unsafe or not root-protected."
        requested_data=$(canonical_path "$DATA_ROOT")
        [ "$requested_data" = "$stored_data" ] || die "--data-dir does not match this installation's stored data path."
    fi
    if [ "$SOURCE_ROOT_REQUESTED" -eq 1 ]; then
        raw_path_is_safe "$SOURCE_ROOT" && has_no_symlink_component "$SOURCE_ROOT" || die "Requested source path is unsafe."
        requested_source=$(canonical_path "$SOURCE_ROOT")
        [ "$requested_source" = "$stored_source" ] || die "--source-dir does not match this installation's immutable source bundle."
    fi
    DATA_ROOT=$stored_data
    SOURCE_ROOT=$stored_source

    stored_panel=$(env_value PANEL_HOST "$INSTALL_ROOT/release.env")
    stored_system=$(env_value SYSTEM_HOST_SUFFIX "$INSTALL_ROOT/release.env")
    stored_origin=$(env_value ORIGIN_HOST "$INSTALL_ROOT/release.env")
    stored_acme=$(env_value ACME_EMAIL "$INSTALL_ROOT/release.env")
    stored_admin_email=$(env_value ADMIN_EMAIL "$INSTALL_ROOT/release.env")
    stored_admin_name=$(env_value ADMIN_NAME "$INSTALL_ROOT/release.env")
    [ -n "$stored_panel" ] && [ -n "$stored_system" ] && [ -n "$stored_origin" ] \
        && [ -n "$stored_acme" ] && [ -n "$stored_admin_email" ] && [ -n "$stored_admin_name" ] \
        || die "Stored installation settings are incomplete."

    [ "$PANEL_HOST_REQUESTED" -eq 0 ] || [ "$PANEL_HOST" = "$stored_panel" ] || die "Requested panel host differs from the installed value."
    [ "$SYSTEM_HOST_REQUESTED" -eq 0 ] || [ "$SYSTEM_HOST_SUFFIX" = "$stored_system" ] || die "Requested system host suffix differs from the installed value."
    [ "$ORIGIN_HOST_REQUESTED" -eq 0 ] || [ "$ORIGIN_HOST" = "$stored_origin" ] || die "Requested origin target differs from the installed value."
    [ "$ACME_EMAIL_REQUESTED" -eq 0 ] || [ "$ACME_EMAIL" = "$stored_acme" ] || die "Requested ACME email differs from the installed value."
    [ "$ADMIN_EMAIL_REQUESTED" -eq 0 ] || [ "$ADMIN_EMAIL" = "$stored_admin_email" ] || die "Requested administrator email differs from the installed value."
    [ "$ADMIN_NAME_REQUESTED" -eq 0 ] || [ "$ADMIN_NAME" = "$stored_admin_name" ] || die "Requested administrator name differs from the installed value."

    PANEL_HOST=$stored_panel
    SYSTEM_HOST_SUFFIX=$stored_system
    ORIGIN_HOST=$stored_origin
    ACME_EMAIL=$stored_acme
    ADMIN_EMAIL=$stored_admin_email
    ADMIN_NAME=$stored_admin_name
else
    [ -z "$RELEASE_SHA256" ] || [ -n "$RELEASE_URL" ] || die "--release-sha256 requires --release-url."
    [ -z "$RELEASE_URL" ] || [ "$SOURCE_ROOT_REQUESTED" -eq 0 ] || die "Use either --source-dir or --release-url, not both."
    managed_path_is_safe "$DATA_ROOT" || die "Unsafe data path: $DATA_ROOT"
    secure_managed_path_chain "$DATA_ROOT" || die "Data path has an untrusted, writable, or non-root-owned ancestor."
    DATA_ROOT=$(canonical_path "$DATA_ROOT")
    managed_path_is_safe "$DATA_ROOT" || die "Unsafe canonical data path: $DATA_ROOT"
    secure_managed_path_chain "$DATA_ROOT" || die "Canonical data path is not root-protected."
fi

[ "$INSTALL_ROOT" != "$DATA_ROOT" ] || die "Installation and data paths must be different."
case "$DATA_ROOT/" in "$INSTALL_ROOT/"*) die "Data path must be outside deployment metadata." ;; esac
case "$INSTALL_ROOT/" in "$DATA_ROOT/"*) die "Deployment metadata must be outside the data path." ;; esac

[ -n "$PANEL_HOST" ] || PANEL_HOST=$(prompt_value "Panel hostname")
PANEL_HOST=$(printf '%s' "$PANEL_HOST" | tr '[:upper:]' '[:lower:]' | sed 's/\.$//')

if [ -z "$SYSTEM_HOST_SUFFIX" ]; then
    SYSTEM_HOST_SUFFIX=$(prompt_value "System landing hostname suffix" "$PANEL_HOST")
fi
SYSTEM_HOST_SUFFIX=$(printf '%s' "$SYSTEM_HOST_SUFFIX" | tr '[:upper:]' '[:lower:]' | sed 's/\.$//')

[ -n "$ORIGIN_HOST" ] || ORIGIN_HOST=$(prompt_value "Public server IP (A/AAAA) or hostname (CNAME)")
ORIGIN_HOST=$(normalize_origin_target "$ORIGIN_HOST")

[ -n "$ADMIN_EMAIL" ] || ADMIN_EMAIL=$(prompt_value "Administrator email")
[ -n "$ACME_EMAIL" ] || ACME_EMAIL=$(prompt_value "ACME contact email" "$ADMIN_EMAIL")
[ -n "$ADMIN_NAME" ] || ADMIN_NAME=Administrator

validate_hostname "$PANEL_HOST" || die "Invalid panel hostname. Use lower-case IDNA ASCII without a protocol, port, wildcard, or path."
validate_hostname "$SYSTEM_HOST_SUFFIX" || die "Invalid system hostname suffix."
ORIGIN_RECORD_TYPE=$(origin_record_type "$ORIGIN_HOST") || die "Invalid origin target. Use a bare IPv4/IPv6 address or hostname without a protocol, port, wildcard, or path."
validate_email "$ADMIN_EMAIL" || die "Invalid administrator email."
validate_email "$ACME_EMAIL" || die "Invalid ACME contact email."
contains_control_character "$ADMIN_NAME" && die "Administrator name cannot contain control characters."
printf '%s\n' "$ADMIN_NAME" | LC_ALL=C grep -Eq '^[A-Za-z0-9 ._-]{1,80}$' || die "Administrator name contains unsupported characters."

if [ ! -f "$INSTALL_ROOT/.state/admin-created" ]; then
    [ -n "$ADMIN_PASSWORD" ] || ADMIN_PASSWORD=$(prompt_password)
    contains_control_character "$ADMIN_PASSWORD" && die "Administrator password cannot contain control characters."
    password_length=$(LC_ALL=C printf '%s' "$ADMIN_PASSWORD" | wc -c | tr -d ' ')
    [ "$password_length" -ge 12 ] || die "Administrator password must contain at least 12 characters."
fi

if [ -r /proc/meminfo ]; then
    memory_kb=$(awk '/^MemTotal:/ { print $2; exit }' /proc/meminfo)
    if [ -n "$memory_kb" ] && [ "$memory_kb" -lt 1900000 ]; then
        warn "Less than 2 GiB RAM is available; ZIP extraction and image builds may fail."
    fi
fi

free_kb=$(df -Pk / | awk 'NR == 2 { print $4 }')
[ -z "$free_kb" ] || [ "$free_kb" -ge 5242880 ] || die "At least 5 GiB of free disk space is required."

if [ "$RESUME" -ne 1 ] && command -v ss >/dev/null 2>&1; then
    for port in 80 443; do
        if [ -n "$(ss -H -ltn "sport = :$port" 2>/dev/null)" ]; then
            die "TCP port $port is already in use."
        fi
    done
fi

ensure_runtime

prepare_source
raw_path_is_safe "$SOURCE_ROOT" || die "Unsafe source path: $SOURCE_ROOT"
has_no_symlink_component "$SOURCE_ROOT" || die "Source path contains a symlink component."
SOURCE_ROOT=$(canonical_path "$SOURCE_ROOT")
safe_absolute_path "$SOURCE_ROOT" || die "Unsafe canonical source path: $SOURCE_ROOT"
validate_source

mkdir -p "$INSTALL_ROOT" "$INSTALL_ROOT/secrets" "$INSTALL_ROOT/.state" "$INSTALL_ROOT/releases"
chmod 0755 "$INSTALL_ROOT"
chmod 0700 "$INSTALL_ROOT/secrets" "$INSTALL_ROOT/.state" "$INSTALL_ROOT/releases"
revalidate_created_managed_path "$INSTALL_ROOT" || die "Installation path changed during secure creation."

copy_source_bundle

cp "$INSTALL_ROOT/source/deploy/compose.yaml" "$INSTALL_ROOT/compose.yaml"
cp "$INSTALL_ROOT/source/deploy/Caddyfile" "$INSTALL_ROOT/Caddyfile"
cp "$INSTALL_ROOT/source/deploy/backup.sh" "$INSTALL_ROOT/backup.sh"
chmod 0644 "$INSTALL_ROOT/compose.yaml" "$INSTALL_ROOT/Caddyfile"
chmod 0700 "$INSTALL_ROOT/backup.sh"

mkdir -p \
    "$DATA_ROOT/postgres" \
    "$DATA_ROOT/laravel-storage/app/private" \
    "$DATA_ROOT/laravel-storage/app/public" \
    "$DATA_ROOT/laravel-storage/framework/cache/data" \
    "$DATA_ROOT/laravel-storage/framework/sessions" \
    "$DATA_ROOT/laravel-storage/framework/views" \
    "$DATA_ROOT/laravel-storage/logs" \
    "$DATA_ROOT/caddy-data" \
    "$DATA_ROOT/caddy-config" \
    "$DATA_ROOT/landings/private" \
    "$DATA_ROOT/landings/public/releases" \
    "$DATA_ROOT/landings/public/hosts"
revalidate_created_managed_path "$DATA_ROOT" || die "Data path changed during secure creation."
chmod 0700 "$DATA_ROOT/postgres"
chmod 0750 "$DATA_ROOT/laravel-storage" "$DATA_ROOT/caddy-data" "$DATA_ROOT/caddy-config"
chmod 0755 "$DATA_ROOT/landings"

if [ ! -f "$INSTALL_ROOT/.state/storage-owned-v2" ]; then
    chown -R 10001:10001 "$DATA_ROOT/laravel-storage" "$DATA_ROOT/landings/private"
    chown -R 10003:10003 "$DATA_ROOT/caddy-data" "$DATA_ROOT/caddy-config"
    chown -R 10002:10001 "$DATA_ROOT/landings/public"
    chown 0:0 "$DATA_ROOT/landings"
    chmod 0750 "$DATA_ROOT/landings/private" "$DATA_ROOT/landings/public" \
        "$DATA_ROOT/landings/public/releases" "$DATA_ROOT/landings/public/hosts"
    : > "$INSTALL_ROOT/.state/storage-owned-v2"
fi
revalidate_created_managed_path "$DATA_ROOT" || die "Data path protection changed during ownership setup."

POSTGRES_PASSWORD_FILE=$INSTALL_ROOT/secrets/postgres-password
INTERNAL_TOKEN_FILE=$INSTALL_ROOT/secrets/internal-token
APP_ENV_FILE=$INSTALL_ROOT/secrets/app.env
EDGE_ENV_FILE=$INSTALL_ROOT/secrets/edge.env
ADMIN_ENV_FILE=$INSTALL_ROOT/secrets/admin-bootstrap.env

configuration_committed=0
[ -s "$INSTALL_ROOT/release.env" ] && configuration_committed=1

if [ "$configuration_committed" -eq 1 ]; then
    for required_file in "$POSTGRES_PASSWORD_FILE" "$INTERNAL_TOKEN_FILE" "$APP_ENV_FILE" "$EDGE_ENV_FILE"; do
        [ -s "$required_file" ] || die "Committed installation configuration is incomplete: $required_file"
    done
else
    if [ ! -s "$POSTGRES_PASSWORD_FILE" ]; then
        secret_tmp=$POSTGRES_PASSWORD_FILE.tmp.$$
        openssl rand -hex 32 > "$secret_tmp"
        chmod 0600 "$secret_tmp"
        mv "$secret_tmp" "$POSTGRES_PASSWORD_FILE"
    fi
    if [ ! -s "$INTERNAL_TOKEN_FILE" ]; then
        secret_tmp=$INTERNAL_TOKEN_FILE.tmp.$$
        openssl rand -hex 32 > "$secret_tmp"
        chmod 0600 "$secret_tmp"
        mv "$secret_tmp" "$INTERNAL_TOKEN_FILE"
    fi
fi
chmod 0600 "$POSTGRES_PASSWORD_FILE" "$INTERNAL_TOKEN_FILE"

database_password=$(tr -d '\r\n' < "$POSTGRES_PASSWORD_FILE")
internal_token=$(tr -d '\r\n' < "$INTERNAL_TOKEN_FILE")
printf '%s\n' "$database_password" | grep -Eq '^[a-f0-9]{64}$' || die "PostgreSQL password file is malformed."
printf '%s\n' "$internal_token" | grep -Eq '^[a-f0-9]{64}$' || die "Internal token file is malformed."

if [ "$configuration_committed" -eq 0 ]; then
    app_key=$(openssl rand -base64 32 | tr -d '\r\n')
    app_env_tmp=$APP_ENV_FILE.tmp.$$
    edge_env_tmp=$EDGE_ENV_FILE.tmp.$$
    admin_env_tmp=$ADMIN_ENV_FILE.tmp.$$
    release_env_tmp=$INSTALL_ROOT/release.env.tmp.$$

    cat > "$app_env_tmp" <<EOF
APP_NAME="Fast Landings"
APP_ENV=production
APP_KEY=base64:$app_key
APP_DEBUG=false
APP_URL=https://$PANEL_HOST
APP_LOCALE=en
APP_FALLBACK_LOCALE=en
APP_MAINTENANCE_DRIVER=file
BCRYPT_ROUNDS=12
LOG_CHANNEL=stderr
LOG_LEVEL=warning
DB_CONNECTION=pgsql
DB_HOST=postgres
DB_PORT=5432
DB_DATABASE=fast_landings
DB_USERNAME=fast_landings
DB_PASSWORD=$database_password
SESSION_DRIVER=database
SESSION_LIFETIME=120
SESSION_ENCRYPT=true
SESSION_PATH=/
SESSION_SECURE_COOKIE=true
SESSION_HTTP_ONLY=true
SESSION_SAME_SITE=lax
SESSION_COOKIE=__Host-fast_landings_session
CACHE_STORE=database
QUEUE_CONNECTION=database
FILESYSTEM_DISK=local
LANDINGS_PATH=/var/lib/fast-landings/landings
FAST_LANDINGS_PANEL_DOMAIN=$PANEL_HOST
FAST_LANDINGS_SYSTEM_DOMAIN=$SYSTEM_HOST_SUFFIX
FAST_LANDINGS_ORIGIN_TARGET=$ORIGIN_HOST
FAST_LANDINGS_MAX_UPLOAD_MB=100
FAST_LANDINGS_MAX_EXTRACTED_MB=300
FAST_LANDINGS_MAX_FILES=5000
FAST_LANDINGS_MAX_EXPANSION_RATIO=200
FAST_LANDINGS_MAX_PATH_BYTES=1024
FAST_LANDINGS_MAX_PATH_DEPTH=32
FAST_LANDINGS_SPA_FALLBACK=true
FAST_LANDINGS_CADDY_ASK_TOKEN=$internal_token
FAST_LANDINGS_PUBLIC_PATH=/var/lib/fast-landings/public
TRUSTED_PROXIES=REMOTE_ADDR
MAIL_MAILER=log
MAIL_FROM_ADDRESS=$ADMIN_EMAIL
MAIL_FROM_NAME="Fast Landings"
EOF
    cat > "$edge_env_tmp" <<EOF
PANEL_HOST=$PANEL_HOST
ACME_EMAIL=$ACME_EMAIL
FAST_LANDINGS_CADDY_ASK_TOKEN=$internal_token
EOF
    : > "$admin_env_tmp"
    cat > "$release_env_tmp" <<EOF
COMPOSE_PROJECT_NAME=fast-landings
FAST_LANDINGS_SOURCE_DIR=$INSTALL_ROOT/source
FAST_LANDINGS_DATA_DIR=$DATA_ROOT
FAST_LANDINGS_APP_ENV=$APP_ENV_FILE
FAST_LANDINGS_EDGE_ENV=$EDGE_ENV_FILE
FAST_LANDINGS_ADMIN_ENV=$ADMIN_ENV_FILE
FAST_LANDINGS_POSTGRES_PASSWORD_FILE=$POSTGRES_PASSWORD_FILE
FAST_LANDINGS_CADDYFILE=$INSTALL_ROOT/Caddyfile
PANEL_HOST=$PANEL_HOST
SYSTEM_HOST_SUFFIX=$SYSTEM_HOST_SUFFIX
ORIGIN_HOST=$ORIGIN_HOST
ACME_EMAIL=$ACME_EMAIL
ADMIN_EMAIL=$ADMIN_EMAIL
ADMIN_NAME=$ADMIN_NAME
APP_IMAGE=fast-landings-app:local
WEB_IMAGE=fast-landings-web:local
LANDING_PHP_IMAGE=fast-landings-landing-php:local
POSTGRES_IMAGE=postgres:17.6-alpine3.22
CADDY_IMAGE=caddy:2.10.2-alpine
PHP_VERSION=8.4
NODE_VERSION=22
NGINX_VERSION=1.29-alpine
EOF
    chmod 0600 "$app_env_tmp" "$edge_env_tmp" "$admin_env_tmp" "$release_env_tmp"
    mv "$app_env_tmp" "$APP_ENV_FILE"
    mv "$edge_env_tmp" "$EDGE_ENV_FILE"
    mv "$admin_env_tmp" "$ADMIN_ENV_FILE"
    # release.env is the configuration commit marker and is published last.
    mv "$release_env_tmp" "$INSTALL_ROOT/release.env"
else
    [ "$(env_value DB_PASSWORD "$APP_ENV_FILE")" = "$database_password" ] || die "Application and PostgreSQL credentials are inconsistent."
    [ "$(env_value FAST_LANDINGS_CADDY_ASK_TOKEN "$APP_ENV_FILE")" = "$internal_token" ] || die "Application and internal TLS credentials are inconsistent."
    [ "$(env_value FAST_LANDINGS_CADDY_ASK_TOKEN "$EDGE_ENV_FILE")" = "$internal_token" ] || die "Edge and internal TLS credentials are inconsistent."
    [ "$(env_value FAST_LANDINGS_PANEL_DOMAIN "$APP_ENV_FILE")" = "$PANEL_HOST" ] || die "Stored panel configuration is inconsistent."
    [ "$(env_value FAST_LANDINGS_SYSTEM_DOMAIN "$APP_ENV_FILE")" = "$SYSTEM_HOST_SUFFIX" ] || die "Stored system-domain configuration is inconsistent."
    [ "$(env_value FAST_LANDINGS_ORIGIN_TARGET "$APP_ENV_FILE")" = "$ORIGIN_HOST" ] || die "Stored origin configuration is inconsistent."
    [ "$(env_value PANEL_HOST "$EDGE_ENV_FILE")" = "$PANEL_HOST" ] || die "Stored edge panel configuration is inconsistent."
    [ "$(env_value ACME_EMAIL "$EDGE_ENV_FILE")" = "$ACME_EMAIL" ] || die "Stored edge ACME configuration is inconsistent."
fi

chmod 0600 "$APP_ENV_FILE" "$EDGE_ENV_FILE" "$INSTALL_ROOT/release.env"
: > "$ADMIN_ENV_FILE"
chmod 0600 "$ADMIN_ENV_FILE"

compose config --quiet || die "Generated Docker Compose configuration is invalid."

if [ ! -f "$INSTALL_ROOT/.state/images-built" ] \
    || ! docker image inspect fast-landings-app:local >/dev/null 2>&1 \
    || ! docker image inspect fast-landings-web:local >/dev/null 2>&1 \
    || ! docker image inspect fast-landings-landing-php:local >/dev/null 2>&1; then
    log "Pulling service images..."
    compose pull postgres edge
    log "Building immutable application, web and landing PHP images..."
    compose build app web landing-php
    : > "$INSTALL_ROOT/.state/images-built"
fi

log "Starting PostgreSQL..."
if ! compose up --detach --wait --wait-timeout 180 postgres; then
    show_failure_logs
    die "PostgreSQL did not become healthy."
fi

log "Applying database migrations..."
if ! compose --profile tools run --rm migrate; then
    show_failure_logs
    die "Database migration failed."
fi

if [ -f "$INSTALL_ROOT/.state/admin-created" ]; then
    installation_ready=$(compose exec -T --user postgres postgres \
        psql --no-align --tuples-only --username=fast_landings --dbname=fast_landings \
        --command="SELECT EXISTS (SELECT 1 FROM installations) AND EXISTS (SELECT 1 FROM users WHERE role = 'administrator');" \
        | tr -d '[:space:]')
    [ "$installation_ready" = t ] || die "The admin-created marker does not match the database. Remove the marker and resume with an administrator password after inspecting the data directory."
else
    escaped_password=$(printf '%s' "$ADMIN_PASSWORD" | sed -e 's/\\/\\\\/g' -e 's/"/\\"/g' -e 's/\$/\\$/g')
    printf 'FAST_LANDINGS_ADMIN_PASSWORD="%s"\n' "$escaped_password" > "$ADMIN_ENV_FILE"
    chmod 0600 "$ADMIN_ENV_FILE"

    log "Creating the initial administrator..."
    if ! compose --profile tools run --rm install; then
        show_failure_logs
        die "Administrator creation failed."
    fi

    : > "$INSTALL_ROOT/.state/admin-created"
    : > "$ADMIN_ENV_FILE"
    unset ADMIN_PASSWORD escaped_password FAST_LANDINGS_ADMIN_PASSWORD || true
fi

log "Starting Fast Landings..."
if ! compose up --detach --remove-orphans --wait --wait-timeout 240; then
    show_failure_logs
    die "One or more Fast Landings services did not become healthy."
fi

if ! compose exec -T web wget -q -O /dev/null --header="Host: $PANEL_HOST" http://127.0.0.1:8080/up; then
    show_failure_logs
    die "The panel health route failed through nginx."
fi

cp "$INSTALL_ROOT/source/deploy/fast-landings" /usr/local/sbin/fast-landings
chmod 0755 /usr/local/sbin/fast-landings
printf '%s\n' "$INSTALL_ROOT" > /etc/fast-landings-home
chmod 0644 /etc/fast-landings-home

: > "$INSTALL_ROOT/.state/installed"

if [ "$ORIGIN_RECORD_TYPE" = CNAME ]; then
    PANEL_DNS_INSTRUCTION="$PANEL_HOST -> this server (A/AAAA)"
else
    PANEL_DNS_INSTRUCTION="$PANEL_HOST -> $ORIGIN_HOST ($ORIGIN_RECORD_TYPE)"
fi

cat <<EOF

Fast Landings is running.

Panel: https://$PANEL_HOST/admin/login

DNS still required:
  $PANEL_DNS_INSTRUCTION
  *.$SYSTEM_HOST_SUFFIX -> $ORIGIN_HOST ($ORIGIN_RECORD_TYPE)

Operator commands:
  fast-landings status
  fast-landings doctor
  fast-landings logs [service]
  fast-landings backup

Persistent data: $DATA_ROOT
Deployment metadata and secrets: $INSTALL_ROOT

Copy backups off this server. The installer never prints generated secrets.
EOF
