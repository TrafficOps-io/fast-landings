# Fast Landings

The TrafficOps control panel that publishes landings (uploaded ZIPs or
parameterised templates) and serves them on system subdomains or custom
hostnames. Single-tenant: one installation, one shared library, a small trusted
team of operators.

## Language

### Installation and people

**Installation**:
The single deployment of the panel. All landings, domains, templates and
integrations belong to it; nothing is owned per user.
_Avoid_: tenant, workspace, account

**Administrator**:
An operator who can also manage users, integrations and templates.

**Editor**:
An operator who works with landings and domains but not with settings. Editors
may deploy, pause, delete landings and remove domains.
_Avoid_: member, viewer

**Enabled / Disabled** (user):
Whether an operator may sign in. A disabled operator is signed out immediately.
_Avoid_: active, inactive

**Integration**:
A credential the installation connects to an external service: a Cloudflare
integration is one API token, an AI integration is one provider key.
_Avoid_: connection, account

### Landings

**Landing**:
A named website managed by the panel. Its content is always exactly one active
release.
_Avoid_: website, site, page

**Release**:
An immutable build of a landing's files, created by a deploy. A landing keeps
every release until an operator deletes it.
_Avoid_: deployment, build, version

**Active release**:
The release whose files the landing currently serves. Exactly one per landing.
_Avoid_: current, live

**Deploy**:
Create a new release from an upload, a template or edited files, and make it the
active release.

**Activate**:
Make an existing release the active release. Activating an older release is how
a landing is rolled back; it also restores that release's template snapshot.
_Avoid_: rollback, restore, switch

**Published / Paused**:
Whether a landing is served to visitors. A paused landing answers 404 on all its
domains; operators can still preview and download it.
_Avoid_: active, inactive, enabled

**Template landing**:
A landing whose active release was produced from a template, so its content is
edited through template values.

**File landing**:
A landing whose active release is managed as raw files (ZIP upload or file
editor).
_Avoid_: generated landing, custom landing

**Detach from template**:
The explicit act of turning a template landing into a file landing by editing
its files directly. Not reversible except by activating an older release.

**Slug**:
A stable, human-readable identifier of a landing used in file names and panel
links. It plays no part in serving.

**Template**:
A template package imported into the library, from which template landings are
created. Templates are not versioned: editing a template landing always uses the
template as it is now.
_Avoid_: template package, definition, theme

**Template values**:
The field values an operator fills in for a template landing.
_Avoid_: settings, parameters, content

**Template snapshot**:
The template and template values a release was built from, frozen with the
release. Activating a release restores its snapshot.

**Draft**:
One operator's private working copy of the active release's files or of a
template, kept until published or discarded. Drafts are made from the active
release only.
_Avoid_: workspace, staging

**Generation**:
One AI run that proposes template values for a landing. A generation can be
applied once, and only while the template is unchanged.
_Avoid_: prefill, AI run

**Apply** (generation):
Copy a generation's result into a landing's template values.

**Preview**:
A rendered picture of a release or a template, shown in the library.
_Avoid_: screenshot, thumbnail

**Landing PHP runtime**:
The isolated process that executes a landing's PHP files for visitors. Trusted
code only: it is not a sandbox for hostile uploads.
_Avoid_: runtime (unqualified), isolated runtime

**Media**:
An image uploaded through a rich-text editor. Media belongs to the installation,
is not tied to a template or landing, and is never deleted automatically.
_Avoid_: attachment, upload

### Domains

**Domain**:
A hostname registered in the panel. A domain may be unassigned (kept in
inventory) or assigned to one landing.
_Avoid_: address, connection, hostname (for the entity)

**Hostname**:
The string value of a domain, e.g. `promo.example.com`.

**System domain**:
The installation's base domain under which system subdomains are issued.
_Avoid_: domain (unqualified), panel domain

**System subdomain**:
A domain of the form `label.<system domain>`. Trusted unconditionally: never
DNS-checked.

**Manual DNS**:
The provider mode where the operator points DNS at the origin target themselves
and the panel only verifies it.
_Avoid_: dns, manual, external

**Cloudflare** (provider):
The provider mode where the panel writes the DNS records itself through a
Cloudflare integration.

**Wildcard base**:
A domain registered with wildcard scope, whose DNS covers every child domain
beneath it.
_Avoid_: wildcard domain, parent

**Child domain**:
A domain beneath a wildcard base. Its DNS state is inherited from the base.
_Avoid_: subdomain (unqualified)

**Active** (domain status):
The most recent DNS check confirmed the hostname points at the origin target.
_Avoid_: verified (as a status name)

**Verified domain**:
A domain that has been Active at least once. A landing is served on a domain
only while the domain is verified and not drifted, and the landing is published.
Transient check failures (Unreachable, Error) do not stop serving unless an
earlier drift is still unresolved. A successful DNS check resolves drift.

**Drifted** (domain status):
The most recent DNS check found the hostname no longer points at the origin
target. A drifted domain is not served.

**Assign / Unassign**:
Attach a domain to a landing, or return it to inventory. The first domain
assigned becomes the primary domain.
_Avoid_: attach, detach, release, free

**Move**:
Reassign a domain from one landing to another in one step.

**Remove**:
Delete a domain from the panel. For Cloudflare domains the managed DNS records
are kept unless the operator explicitly asks to clean them up.

**Inventory**:
The domains that are registered but not assigned to any landing.
_Avoid_: pool, unassigned list

**Primary domain**:
The hostname the panel uses to open a landing. It has no effect on serving and
implies no redirect. When the primary is removed or moved, the oldest remaining
domain takes over.
_Avoid_: main domain, canonical

**Origin target**:
The IP or hostname that every custom domain's DNS must point at. Pointing DNS at
it is the only proof of control the panel requires.
_Avoid_: origin, server address
