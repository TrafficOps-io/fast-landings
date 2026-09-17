# Fast Landings

Open-source, self-hosted HTML/PHP landing deployment for TrafficOps. Upload a ZIP, attach one or more hostnames, and switch any hostname to another landing without rebuilding the release.

[![CI](https://github.com/TrafficOps-io/fast-landings/actions/workflows/ci.yml/badge.svg)](https://github.com/TrafficOps-io/fast-landings/actions/workflows/ci.yml)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](LICENSE)

## What is included

- Laravel 12, Livewire 4, DaisyUI 5, and a repository-local copy of the TrafficOps design system in `packages/ui`.
- PostgreSQL-backed users, immutable landing releases, domains, sessions, cache, and queue.
- Login-only control panel. The first administrator is created during installation; public registration and password-reset routes do not exist.
- System hostnames such as `offer.landings.example.com`.
- Custom hostnames using manual A/AAAA/CNAME records or Cloudflare provisioning from [`trafficops/tops-infra`](https://packagist.org/packages/trafficops/tops-infra).
- A searchable domain registry with unassigned inventory, explicit wildcard subdomain assignments, and separate Cloudflare connection settings for multiple accounts.
- Multiple domains per landing and transactional domain reassignment between landings.
- Secure staged ZIP extraction: traversal, links, path collisions, expansion bombs, excessive file counts, and actual extracted-byte overruns are rejected.
- Reusable text or ZIP templates with typed parameters, grouped settings, repeatable blocks, image uploads, and multipage HTML/PHP compilation.
- Request macros (`query.*`, `headers.*`, `body.*`) with page-specific validation and autocomplete in VS Code, the code editor, and landing content settings.
- Host-based public delivery with SPA fallback, ETags, content types, and revalidating cache headers; production serves projected releases through Caddy, with PHP requests handled by a separate runtime.
- Caddy on-demand TLS allowlist endpoint protected by an installation secret.
- Instance-wide AI integrations with encrypted API keys and background content/image generation when creating landings from templates.
- A searchable landing card library with real Chromium screenshots, release-aware previews, and Spatie Laravel Tags.

## Landing library, screenshots, and tags

`/admin/landings` displays responsive cards with a screenshot, publication status, tags, domain/release counts, and a link to the landing. Search by name, slug, description, or tag; combine a tag filter with a status filter. Results are paginated and filters stay in the URL.

Use **Download ZIP** on a landing card or its details page to download the active release, including generated pages and previously uploaded websites. Each release in the history also has its own download link. The archive contains that release's published website files at the ZIP root, including compiled PHP pages and assets, and excludes private editor metadata. Downloads are available to signed-in panel users even when the landing is paused or has no domain. When hosting elsewhere, PHP pages need PHP support; links to `.html` pages compiled to `.php` need the same `.html` → `.php` fallback used by Fast Landings.

Add or select tags when deploying a ZIP, creating from a template, or saving a landing's settings. Press Enter or comma to add a tag; remove it with ×. Up to 20 tags of 60 characters each are supported. Tags use [Spatie Laravel Tags](https://spatie.be/docs/laravel-tags/v4/introduction) with an ULID polymorphic pivot matching the landing IDs. Updating or rolling back content preserves the landing's tags.

Screenshots are actual 1440 × 1000 Chromium captures of the release HTML, CSS, images, fonts, and JavaScript, created by a Laravel queue job after deployment. They also work before assigning a domain and while a landing is paused. Each release keeps its own image, so rollback immediately restores the corresponding preview. **Refresh preview** regenerates it; **Retry preview** retries a failed capture. Existing images remain visible during refresh. The library polls only while visible cards have pending captures.

For a development checkout, install the renderer once after updating dependencies:

```sh
composer install
php artisan migrate
npm install
npm run previews:install
php artisan fast-landings:previews
```

Keep the queue worker running (`composer dev` includes it). The scheduled `fast-landings:previews` command backfills missing active-release and template images and recovers abandoned jobs. Opening either library also queues missing images. To refresh all active releases and templates with a preview source, including failed captures, run `php artisan fast-landings:previews --force`.

The production Docker image includes Node, Chromium, fonts, and the renderer dependencies; rebuild it when upgrading. The queue service has a 1 GB memory limit to accommodate Chromium. For a custom installation, set `FAST_LANDINGS_NODE_BINARY` and optionally `FAST_LANDINGS_CHROMIUM_PATH`; otherwise Playwright uses its installed Chromium. `FAST_LANDINGS_PREVIEWS_ENABLED=false` disables automatic capture.

Preview PNGs are stored privately alongside (outside) release directories and served only to active panel users. The renderer uses a fresh browser context with no panel cookies. It serves only files inside the selected release, permits public HTTP(S) image/style/font/script resources through a checked, address-pinned fetcher, and blocks private-network resources, external navigation, forms, service workers, and WebSockets. Pages that rely on private assets, authenticated resources, or API calls can look different in the preview. See [Playwright request routing](https://playwright.dev/docs/api/class-browsercontext#browser-context-route).

## Production installation

Use a fresh Ubuntu 24.04/22.04 or Debian 12 VPS with at least 2 GB RAM and 5 GB free disk. The installer installs Docker Engine and Docker Compose v2 from Docker's signed apt repository when they are not already present.

The flow is the same on DigitalOcean, Linode/Akamai, Vultr, or another VPS provider:

1. Create an Ubuntu or Debian server and attach a stable public IP.
2. Allow inbound SSH, TCP 80, TCP 443, and UDP 443 in the provider firewall. Keep database and application ports closed.
3. Point the panel hostname (for example `panel.example.com`) at the server IP. For managed system subdomains, also add `*.go.example.com` with the same target.
4. Connect over SSH and run:

```sh
sudo apt-get update
sudo apt-get install -y git
git clone https://github.com/TrafficOps-io/fast-landings.git
cd fast-landings
sudo ./install.sh
```

The interactive installer asks for the panel hostname, system domain, server public IP or origin hostname, ACME email, and administrator credentials. For unattended provisioning, use the exact environment variables documented in [`deploy/README.md`](deploy/README.md).

If Docker is already managed by the provider image, the installer reuses the working Docker Engine and Compose v2 installation. Before running it, confirm that no other service occupies ports 80 or 443.

From an existing repository checkout, the entry point is simply:

```sh
sudo ./install.sh
```

Use the VPS public IPv4 as the origin target for the usual DigitalOcean, Linode, or Vultr setup; the app will create A records. IPv6 produces AAAA records, and a hostname produces CNAME records. The installer generates durable application, database, and Caddy secrets; builds the immutable images; starts PostgreSQL; migrates; creates the administrator; and health-checks the stack.

Never lose `APP_KEY`: Cloudflare API tokens and AI provider API keys are encrypted with it. Back up the generated deployment `.env`, PostgreSQL, and the landings volume together.

## AI integration settings

Administrators can open **AI integrations** from the profile menu (`/admin/ai-integrations`). Add a name, select OpenAI, OpenRouter, Anthropic, Google Gemini, DeepSeek, or **Other provider**, and enter an API key. Multiple integrations, including multiple keys for the same provider, are allowed and belong to the installation rather than an individual user.

The API URL is optional for named providers and defaults to their standard base URL. **Other provider** requires an explicit HTTP(S) base URL. URLs must not contain embedded credentials, query parameters, or fragments; put the credential in the API key field. Standard URLs follow the official [OpenAI](https://developers.openai.com/api/reference/overview), [OpenRouter](https://openrouter.ai/docs/quickstart), [Anthropic](https://platform.claude.com/docs/en/api/overview), [Gemini](https://ai.google.dev/api), and [DeepSeek](https://api-docs.deepseek.com/) documentation.

Saved keys are encrypted with `APP_KEY` and never shown again. When editing, leave the API key blank to retain it or enter a replacement; changing providers requires a new key. Integrations can also be removed. Connections are available in **Create landing → Generate content with AI**. Saving a connection does not call the provider; generation uses the selected connection and is billed by that provider. Existing installations must run `php artisan migrate` and restart queue workers after upgrading. See [AI-assisted template content](docs/templates.md#ai-assisted-content-generation) for the workflow, supported capabilities, and model configuration.

## Domain modes

Use **Domains** (`/admin/domains`) to connect addresses independently of their landing assignments. A domain can remain unassigned while DNS is configured. Open **Manage** for DNS instructions, checks, assignment review, or removal; the landing page also offers available domains and explicit subdomain creation. See the [domain operator guide](docs/domains.md) for the complete workflow and recovery steps.

### System subdomains

Configure wildcard DNS once for the installation, for example:

```text
*.landings.example.com  A  203.0.113.10
```

Replace `203.0.113.10` with your server's public IPv4. Use AAAA for an IPv6 origin or CNAME for a hostname target. System addresses are enabled locally and labeled **Infrastructure managed**; the app does not verify their DNS or HTTPS. Only registered, assigned addresses serve content, and administrative labels are reserved.

### Custom domain scope

For both **Manual DNS** and **Cloudflare**, choose the scope:

| Scope | Result |
| --- | --- |
| Exact hostname | Connect one address, such as `example.com` or `offer.example.com`. |
| One subdomain | Choose the base/zone and enter a label such as `offer`; connect the resulting exact hostname. |
| Domain + wildcard | Configure the base and `*.base` together, then create named child addresses from a landing's **Use a subdomain** form. |

A wildcard base such as `customer.example` requires both records:

```text
customer.example        A  203.0.113.10
*.customer.example      A  203.0.113.10
```

Assigning this base to a landing serves only `customer.example`. Each child, such as `offer.customer.example`, must be explicitly created and assigned. Unknown subdomains never inherit the base landing. Child DNS is checked independently because specific records can override a wildcard; the base cannot be removed while children depend on it.

### Manual DNS and Cloudflare

**Manual DNS** shows the required type, names, target, and TTL for your provider. The origin determines the type: A for IPv4, AAAA for IPv6, and CNAME for a hostname. An apex CNAME target requires provider-supported ALIAS/ANAME or flattening. Keep proxying disabled for public DNS verification.

Administrators manage **Cloudflare connections** at `/admin/cloudflare`, linked from Domains and the profile menu. Each named connection stores one encrypted token; its actual Cloudflare accounts and all discovered zones are shown separately. Create a scoped [Cloudflare API token](https://developers.cloudflare.com/fundamentals/api/get-started/create-token/) with **Zone → Zone → Read** and **Zone → DNS → Edit**. Use **Refresh zones** after changing permissions or activating a zone. Renaming a connection or rotating its token preserves domain links; disconnecting is blocked while domains use it.

Cloudflare provisioning and custom DNS verification run in queue jobs. New records use DNS only; matching existing records are adopted, and conflicting records require review. Removing an address keeps DNS by default. Optional cleanup can delete only records created by Fast Landings, never adopted records. **DNS verified** confirms DNS, while HTTPS and public delivery also require a published landing, reachable server, and working TLS setup.

Existing installations must run `php artisan migrate` for domain scopes and parent/child relationships. Existing hostnames remain exact and retain their assignments.

## ZIP contract

- An exact lowercase `index.php` or `index.html` must exist at the archive root or in one top-level wrapper folder.
- Assets may use arbitrary nested directories.
- PHP files execute in the dedicated website runtime. If both root indexes exist, `index.php` takes precedence. PHP sources are never served as static bytes; an unavailable runtime returns an error.
- Default limits: 100 MB compressed, 300 MB extracted, and 5,000 files. Configure them with `FAST_LANDINGS_MAX_*` variables.
- A new valid upload becomes active atomically. Older releases remain available for one-click rollback.

## PHP websites and forms

A landing is a whole website release. Files in the same ZIP share the same domain and relative URL space. Upload `index.php` plus `success.php`, or use an HTML form page with a PHP handler:

```html
<form method="post" action="/success.php">
  <input name="name" required>
  <button type="submit">Send</button>
</form>
```

```php
<?php // success.php
$name = is_string($_POST['name'] ?? null) ? $_POST['name'] : '';
echo 'Hello, '.htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
```

PHP receives query parameters, form fields, uploaded files, cookies and raw request bodies through the usual `$_GET`, `$_POST`, `$_FILES`, `$_COOKIE` and `php://input`. Scripts can use `include`/`require`, sessions, response headers, redirects, and outbound HTTP calls. Public form requests bypass the panel's session and CSRF middleware. Implement validation and any CSRF protection needed by your website in its own PHP code.

Template page names describe build output: `index.tpl.html` becomes `index.html`, `index.tpl.php` becomes `index.php`, and `success.tpl.php` becomes `success.php`. Settings are substituted when a release is generated; request macros such as `{body.name}` are resolved for each visitor. Pages containing macros or `@validation` compile to PHP, including HTML pages; their original `.html` URLs continue to accept requests. See [request macros and validation](docs/templates.md#request-macros-and-validation) for typed rules, fallback redirects, supported contexts and autocomplete in landing settings. Template sources are never executed during import or generation.

Download the [working form template ZIP](public/examples/form-website-template.zip), import it in **Templates**, create a landing, attach a domain, and submit the form. Its editable source is in [`docs/examples/form-website`](docs/examples/form-website); it demonstrates separate **Main page** and **Success page** settings in one editor, POST fields, server-side validation and escaped output. Each page declares its own `@param` fields; `@section` groups them in the creation/editing form. All pages share the same parameter namespace and publish together. The example displays a submission without saving it or sending it to a CRM.

For local development, set `FAST_LANDINGS_PHP_UID` and `FAST_LANDINGS_PHP_GID` in `.env` to the numeric output of `id -u` and `id -g`, then run `docker compose up -d --build --wait` from the repository root. The local PHP container uses your filesystem identity to read the private release bind mount; production uses its separate projection and service identity. The panel/dev server proxies PHP requests to the runtime at `FAST_LANDINGS_PHP_ADDRESS` (default `tcp://127.0.0.1:9070`). Production installs/upgrades must rebuild and start the `landing-php` service and use the updated Caddy configuration; see [deployment instructions](deploy/README.md). No database migration is required for PHP entrypoints.

The runtime has no panel code, panel environment, database credentials or database network. Release files are read-only; temporary uploads and sessions use runtime storage. This supports ordinary landing scripts, but applications that install packages or write into the web root need a separate deployment. Upload PHP only from trusted authors: this is an installation-wide website runtime, not a hostile-code sandbox for unrelated tenants.

Library screenshots do not execute uploaded PHP. A landing whose main page is PHP has no automatic local screenshot; a template can use `previewUrl` for a public rendered demonstration. HTML main pages with PHP form handlers retain normal screenshots.

## Landing templates

Документация на русском: [руководство по шаблонам](docs/ru/templates.md), [справочник DSL v1](docs/ru/template-dsl.md), [HTTP-макросы, формы и PHP](docs/ru/template-runtime.md).

Template cards support optional screenshot previews, using the same queue and Chromium setup as landing previews. Add an `@previewData` JSON block to render demonstration settings, or `previewUrl="https://example.com/demo"` to `@template` to capture a public demo page (the URL takes priority). Demo values do not change the form defaults. Importing or replacing a package queues its preview; administrators can refresh or retry it in the library. Existing installations need `php artisan migrate` for the template preview columns. See [preview syntax](docs/templates.md#library-previews) and the [downloadable example](public/examples/preview-template.tpl).

`Wysiwyg` and `Markdown` fields provide formatted editing, links, image uploads and Markdown preview. Render their sanitized content using `{{& field}}`. Editor images use native Livewire uploads and Laravel Filesystem; set `FAST_LANDINGS_MEDIA_DISK` to `local`, `s3` or any configured disk. Run `php artisan migrate` to add the media registry. See the [rich text example](public/examples/rich-text-template.tpl) and [storage configuration](docs/templates.md#image-storage-disks).

Administrators can import a single HTML/PHP/TXT/TPL source or a ZIP containing an index or legacy `template.html`, additional pages, source fragments, and assets. On **Templates**, **Edit** changes a template's name and description and optionally replaces its source or ZIP package while keeping its identity and existing landing releases. Templates use `@param`, `@type`, `@block`, `@each`, `@render`, and `@layout` directives. Active panel users choose a template, configure its generated form, and create a landing with an active website release. Settings can be grouped into sections and nested blocks, including repeatable comments with their own image uploads. Template deletion leaves existing generated landings available.

On an existing landing, **Edit template data** opens the saved settings and retains uploaded images. **Change template** starts a new form with the selected template's defaults. **Replace with ZIP archive** switches a generated landing to uploaded website content; **Use a template** converts a ZIP landing to a generated one. **Save and activate** creates a new release while preserving the landing's name, slug, publication status, and domains. Each new release stores its template settings, so activating a previous release also restores the matching editor data. Existing installations must run `php artisan migrate` to add these snapshots; only the active release can be backfilled with previously saved settings.

See the [template format and authoring guide](docs/templates.md), [single-file example](public/examples/article-template.html), and [ZIP example](public/examples/article-template.zip).

## File editor

Open **Files** on a template (administrators only), **Edit files** on a landing, or **Files** next to any release. The editor lists the package files and supports creating text files, uploading assets, renaming/moving files with nested paths, deleting files, viewing sources/images, and downloading individual files or the complete draft ZIP. Text files up to 2 MB can be edited in Monaco, with HTML, CSS, JavaScript and JSON highlighting. TPL directives, parameter/type/block suggestions and hover help use the vendored browser analyzer from [`TrafficOps-io/tops-templates`](https://github.com/TrafficOps-io/tops-templates), including source fragments within the package.

Changes remain in a private draft until **Save template** or **Save and activate**. Switching files automatically stages the current text; Ctrl/Cmd+S stages it explicitly. Update references when moving or deleting a file, then publish all related changes together. Templates are parsed and validated before replacement; a landing must retain root `index.php` or `index.html`. Invalid packages and stale editors leave the current published version untouched. Downloads include staged draft changes. Cancel discards the draft; abandoned drafts expire with staging cleanup.

Landing file edits always create a new active website release, preserving domains and older releases for rollback. Editing a generated landing's files converts the new release to file-managed content; its previous release retains the original template settings. Template file edits affect future generation and leave existing landing releases unchanged. Source previews are served as sandboxed plain text; raster images can be previewed inline.

## Local development

Requirements: PHP 8.4 with `intl`, `zip`, `pdo_pgsql`, `pcntl`, and `posix`, Composer, Node 22.12+, and Docker Compose v2. PHP and Vite run on the host; the local `compose.yaml` runs PostgreSQL 17 on `127.0.0.1:54331` and the separate landing PHP runtime, apart from the other apps. Queue, cache, and sessions use PostgreSQL; email is written to the Laravel log, so Redis and Mailpit are not required.

From the repository root, for a fresh checkout:

```sh
cp .env.example .env
printf '\nFAST_LANDINGS_PHP_UID=%s\nFAST_LANDINGS_PHP_GID=%s\n' "$(id -u)" "$(id -g)" >> .env
docker compose up -d --build --wait
composer setup
FAST_LANDINGS_ADMIN_PASSWORD='choose-a-long-password' \
  php artisan fast-landings:install \
  --system-domain=landings.localhost \
  --origin=origin.localhost \
  --admin-email=admin@example.test
composer dev
```

Open `http://landings.localhost:8090/admin/login`. The installation command above creates `admin@example.test` with password `choose-a-long-password`; change these example credentials if desired. Run the installer once. It does not reset an existing administrator unless explicitly passed `--force`.

`composer dev` starts the PHP server (`SERVER_HOST` / `SERVER_PORT`, default `127.0.0.1:8090` in `.env.example`), queue listener, scheduler, log viewer, and Vite (`5175`). PostgreSQL must already be running. On subsequent starts, run `docker compose up -d --wait` and `composer dev`. Stop the app processes with Ctrl+C and stop PostgreSQL with `docker compose stop`; its named volume retains the data.

The dev server uses `fast-landings:serve` to apply the configured upload limit to PHP (100 MB per file by default, plus multipart overhead). A plain `php artisan serve` uses your system PHP limits, which may reject ZIP imports above 2 MB. The HTTP server reloads `.env` changes automatically; restart `composer dev` to refresh the queue, scheduler, and Vite too. Keep `--no-reload` out of the normal dev command: it retains the environment from startup, including an empty `APP_KEY` if the key was generated later. For scripted runs that explicitly inject environment variables, use `php artisan fast-landings:serve --no-reload` and restart it after changing those values.

If `.env` already exists, preserve its `APP_KEY` and existing settings rather than overwriting the file or rerunning `composer setup`. Compare it with `.env.example`: the local Compose setup needs `DB_CONNECTION=pgsql`, `DB_HOST=127.0.0.1`, `DB_PORT=54331`, `DB_DATABASE=fast_landings`, `DB_USERNAME=fast_landings`, and `DB_PASSWORD=local-development-only`. Also set `APP_URL=http://landings.localhost:8090`, `SERVER_PORT=8090`, and both `FAST_LANDINGS_PANEL_DOMAIN` and `FAST_LANDINGS_SYSTEM_DOMAIN` to `landings.localhost`. Then run `php artisan config:clear` and `php artisan migrate`. Changing the database configuration does not transfer data from a previous SQLite database.

If `APP_KEY` is empty (for example, after copying `.env.example` without running `composer setup`), run `php artisan key:generate` once, then `php artisan config:clear` before starting `composer dev`. Do not regenerate an existing key to fix a running server: restart the dev processes so they read the existing key.

Modern browsers resolve `localhost` and `*.localhost` to the loopback interface. In local mode, **System subdomain** accepts either a short label (`demo`, producing `demo.landings.localhost`) or an explicit local hostname (`localhost` or `demo.localhost`, used as-is). If your environment does not resolve those names, add the exact hostname to `/etc/hosts`, for example `127.0.0.1 demo.localhost`. The production stack in `deploy/compose.yaml` is separate and is not needed for this workflow.

Use **System subdomain** for local landing previews; these domains become active immediately, and **Open landing** preserves the local HTTP scheme and port. Custom domains are checked against public DNS, so an `/etc/hosts` entry alone does not verify them.

Useful checks:

```sh
composer validate --strict
vendor/bin/pint --test
php artisan test
npm run build
php artisan fast-landings:check-domains
```

The scheduler and queue worker must both run continuously in production (the supplied Compose stack runs both). Every minute, the scheduler queues due DNS checks. Pending domains are rechecked about once a minute, active domains every ten minutes, and failures after five minutes; the intervals are configurable under `fast-landings.domain_checks`. The scheduler also prunes abandoned staging directories hourly. **Check now** queues an immediate check without waiting for DNS or Cloudflare inside the web request.

## Security boundary

The control panel and Livewire update/upload endpoints are bound to `FAST_LANDINGS_PANEL_DOMAIN`. Keep `SESSION_DOMAIN` unset so the admin cookie remains host-only and is never sent to user-uploaded content on system subdomains. Caddy must route its ask endpoint only over the private Compose network and must not log the secret-bearing query string.

The production topology and operational commands are documented in [`deploy/README.md`](deploy/README.md).

## Dependency boundary

The application installs the shared infrastructure and template engine from Packagist: [`trafficops/tops-infra`](https://packagist.org/packages/trafficops/tops-infra) and [`trafficops/template-dsl`](https://packagist.org/packages/trafficops/template-dsl). The design system is copied into `packages/ui`, and the browser-only TPL analyzer is vendored under `resources/js/vendor`; neither requires the private source monorepo.

## License

Fast Landings is released under the [MIT License](LICENSE).
