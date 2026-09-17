# Fast Landings production bundle

This directory is the Docker Compose production appliance. The supported entry
point is the repository-level installer:

```sh
sudo ./install.sh
```

The same script can bootstrap a host without a repository checkout when a
release publisher provides a `.tar.gz` source bundle and its SHA-256. Supply
both values explicitly; the installer accepts HTTPS, verifies the digest before
extraction, and rejects archive links and special files:

```sh
sudo env \
  FAST_LANDINGS_RELEASE_URL='https://downloads.example.com/fast-landings-VERSION.tar.gz' \
  FAST_LANDINGS_RELEASE_SHA256='64_HEXADECIMAL_CHARACTERS_FROM_THE_RELEASE' \
  ./install.sh
```

The example URL is a placeholder, not a live download. Obtain the installer,
archive URL, and digest from the same trusted release channel.

The installer supports Ubuntu/Debian on amd64 and arm64, installs Docker Engine
from Docker's signed apt repository when needed, creates durable secrets and
storage, builds the panel, landing PHP-FPM and nginx images, migrates PostgreSQL, creates the
administrator, and waits for every service to become healthy.

## Unattended installation

The password is read from the environment, never from a command-line argument:

```sh
sudo env \
  FAST_LANDINGS_NON_INTERACTIVE=1 \
  FAST_LANDINGS_PANEL_DOMAIN=panel.example.com \
  FAST_LANDINGS_SYSTEM_DOMAIN=go.example.com \
  FAST_LANDINGS_ORIGIN_TARGET=203.0.113.10 \
  FAST_LANDINGS_ACME_EMAIL=ops@example.com \
  FAST_LANDINGS_ADMIN_EMAIL=admin@example.com \
  FAST_LANDINGS_ADMIN_PASSWORD='replace-with-a-long-secret' \
  ./install.sh
```

Omit `FAST_LANDINGS_SYSTEM_DOMAIN` to use the panel/installation domain itself,
which produces names such as `offer.panel.example.com`. Use `--resume` after an
interrupted installation. Existing keys and database credentials are preserved.

Replace `203.0.113.10` with the VPS public IPv4 provided by DigitalOcean, Vultr,
Linode, or your host. `--origin-target IP_OR_HOST` is the equivalent CLI option
(`--origin-host` remains a compatibility alias). IPv4 selects A records, IPv6
selects AAAA, and a hostname selects CNAME. The target is required, not guessed.
IPv6-only targets require working public IPv6 connectivity on the server.

Before opening the panel, point `panel.example.com` to the server IP. Add
`*.go.example.com A 203.0.113.10` for system subdomains (substitute your values).
For custom domains the panel either displays the exact record or creates it in
the selected existing Cloudflare zone. A/AAAA also work at the zone root (`@`);
only hostname/CNAME targets need apex ALIAS/ANAME or provider-side flattening.

## Network and storage boundary

- Caddy alone publishes TCP 80 and TCP/UDP 443.
- The exact panel host is proxied through nginx to PHP-FPM.
- Landing hosts serve complete multi-page sites from a read-only projection.
  Caddy serves static files and sends `.php` requests, including POST bodies,
  to the dedicated `landing-php` FPM service. They never enter Laravel session,
  CSRF, Livewire, upload, or health routes.
- The on-demand TLS ask request uses an unlogged container-only nginx listener.
- PostgreSQL is reachable only on the internal backend network. App, queue, and
  scheduler additionally receive outbound access for DNS and Cloudflare APIs.
- Private ZIP/release storage is not mounted into Caddy. The projection worker
  materializes only active releases, switches host links atomically, and becomes
  unhealthy if its reconciliation heartbeat stops.
- The landing runtime has no panel source, environment, secrets, private ZIPs
  or backend-network connection. It uses a separate outbound network for HTTP
  APIs. Its non-root filesystem is read-only except for bounded temporary
  storage; each release has its own temporary/session directory and PHP
  `open_basedir` restriction. Shell execution and `.user.ini` overrides are off.

## Landing PHP behavior

ZIP sites may contain `index.php` or `index.html`, additional PHP/HTML pages,
includes and assets. Directory indexes prefer `index.php`. A form such as
`<form method="post" action="success.php">` reaches that script with normal
`$_GET`, `$_POST`, `$_FILES`, cookies, headers and redirects. PHP includes are
relative to the requested script's directory. Static HTML destinations do not
execute PHP or read POST data; static endpoints accept GET/HEAD only. Template
builds produce the same runtime files: `index.tpl.php` becomes `index.php` and
`success.tpl.html` becomes `success.html`.

Missing scripts/assets return 404. Sites without an `index.php` retain the
existing GET/HEAD extensionless `index.html` SPA fallback; an arbitrary path does not become a PHP
front-controller route. Dotfiles, template/include source, configuration files
and backups cannot be downloaded, nor can PHP source be served if FPM is down.
Only canonical lowercase `.php` endpoints execute. Apache `.htaccess` rules
and PHP configuration overrides are not interpreted.

`session_start()` works across form requests within the current release.
`sys_get_temp_dir()` and `$_SERVER['FAST_LANDINGS_TMP_DIR']` identify the release's
writable temporary directory. Use it as the target for `move_uploaded_file()`;
the published site itself stays immutable. Temporary files and sessions reset
when the runtime container is recreated or a different release is activated.
Durable storage requires an external service. Request limits are 16 MiB total,
10 MiB per file, 128 MiB PHP memory and 30 seconds execution time.

Upload PHP only from trusted authors. The shared runtime provides separation
from the panel and filesystem restrictions for ordinary scripts, not a hostile
multi-tenant PHP sandbox. Outbound calls are available and applications remain
responsible for validating form input, CSRF protection where needed, and
handling their own API credentials.

The deployment HTTP smoke test runs the production Caddy route against the
actual FPM image, including form bodies, sessions, multipart files, source
protection, active-host switching/rollback and runtime outages:

```sh
docker build --target landing-php -t fast-landings-landing-php:runtime-test \
  -f deploy/Dockerfile .
python3 deploy/tests/runtime-smoke.py
```

Run both commands from the repository root. The smoke test removes its own
containers, networks, volumes and fixtures when it finishes.

Host data defaults to `/var/lib/fast-landings`; deployment metadata and secrets
default to `/opt/fast-landings`. Never hand-edit generated `secrets/app.env` or
lose `APP_KEY`, because it protects stored integration credentials.

## Operations

```sh
sudo fast-landings status
sudo fast-landings doctor
sudo fast-landings logs edge
sudo fast-landings restart
sudo fast-landings backup
```

`backup` briefly pauses the panel and filesystem writers, creates a PostgreSQL
custom dump plus persistent files, deployment secrets, and the immutable source
bundle needed to rebuild the local images, then restores service.
The root-only archive is written under `/var/backups/fast-landings` by default.
It contains secrets: encrypt it and copy it to independent off-host storage.
Bare-host restore is currently a documented manual operation rather than an
automated command; verify it on a separate machine before relying on it.

The example env/secret files in this directory are for Compose validation only.
`compose.yaml` deliberately fails closed unless installer-generated absolute
paths are supplied.
