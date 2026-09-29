# Domain operator guide

Fast Landings manages three separate things: an address's DNS connection, the landing assigned to that address, and Cloudflare credentials used for DNS automation. Connections and domains belong to the installation and are shared by its panel users.

## Add an address

Open **Domains → Add domain**. Select a connection method, enter the address, and optionally select a landing. **Leave unassigned — choose later** adds the address to the registry without serving content. Search and filters separate pending connections, errors, providers, and unassigned inventory.

| Connection method | Operator responsibility |
| --- | --- |
| System subdomain | Configure wildcard DNS for the installation once. The app enables registered addresses without checking public DNS; their status is **Infrastructure managed**. |
| Manual DNS | Copy the displayed record names, type, target, and TTL into your DNS provider. Keep proxying disabled. |
| Cloudflare | Select a saved connection and an active, unpaused zone. A background job configures the DNS records. |

For manual and Cloudflare DNS, **Exact hostname** connects one complete address. **One subdomain** combines a base/zone with a label such as `offer`; it still creates a single exact hostname and its own DNS record. **Domain + wildcard** configures a reusable base and its wildcard DNS together.

The server target determines the record type: IPv4 uses A, IPv6 uses AAAA, and a hostname uses CNAME. Use an origin hostname that already resolves to your server. For a zone apex, a hostname target requires ALIAS/ANAME or CNAME flattening at the DNS provider. The domain details show the installation's actual target; example addresses below are placeholders.

## Use one base for several landings

1. Add `customer.example` with **Domain + wildcard**, entering the base without `*.`. Leave it unassigned, or assign its root address to a landing.
2. Configure or provision both DNS records and wait for verification:

   ```text
   customer.example    A    203.0.113.10
   *.customer.example  A    203.0.113.10
   ```

3. Open a landing's **Domains → Use a subdomain** form. Select the base, enter `offer`, and choose **Create and assign subdomain**.
4. Repeat for another landing with a different label, such as `signup`.

This creates explicit assignments for `offer.customer.example` and `signup.customer.example`. No extra DNS record is created for these children. The root address can serve a third landing or remain unassigned. Unknown names such as `anything.customer.example` do not serve a landing automatically, even when wildcard DNS resolves them to the server.

Each child gets its own DNS check because an existing record for that exact hostname can override the wildcard. Confirmed DNS drift on the base blocks its dependent children until a successful check; transient timeouts and worker errors preserve previously verified delivery. Restoring the base schedules checks for those children rather than assuming they are valid. Enter one label per child. The base cannot be removed until its child addresses are removed.

## Assign, move, or release an address

On a landing, **Available domains** lists unassigned registry addresses. Assigning one keeps its DNS. **Move a domain from another landing** shows the current and destination landings for review before confirmation. The same workflow is available under **Domains → Manage → Change landing**. If another user changes the assignment while a review is open, refresh the review before confirming.

**Unassign** keeps the address and its DNS available for reuse while stopping delivery of the previous landing. Each hostname serves at most one landing; a landing can have multiple hostnames. **Make primary** chooses the link used by **Open landing**. Other assigned addresses continue to serve the same content, with no redirect created.

An assignment serves content only when the landing is published, has an active release, and the custom address has verified DNS with no unresolved drift. A later timeout or worker error keeps serving; confirmed drift remains blocked until a successful check. Production host projections update shortly after an assignment or publication change.

## Manage Cloudflare accounts and tokens

Administrators open **Cloudflare connections** from Domains or the profile menu. Each connection is one named API token; a token may expose multiple actual Cloudflare accounts. The page groups zones under those account names and shows pending, paused, and inaccessible zones with recovery guidance. Connecting and refreshing read Cloudflare configuration; provisioning DNS happens separately when a domain is added.

Create a custom token using the official [Cloudflare token guide](https://developers.cloudflare.com/fundamentals/api/get-started/create-token/). Grant **Zone → Zone → Read** and **Zone → DNS → Edit**, and include the required zones under Zone Resources. Add a separate connection for another token, using a distinct name. Save each token once; duplicate tokens are rejected. **Refresh zones** reloads accounts and zone access. **Add domain** beside an available zone carries that selection into the domain form.

To rotate credentials, select **Edit**, paste a replacement API token, and save. The replacement is verified and must retain access to every zone used by linked domains. Existing connection, zone, domain, and landing links stay in place. A rejected replacement leaves the saved token unchanged. Leaving the token blank only changes the connection name. Tokens are encrypted with `APP_KEY`, never displayed, and managed only by active administrators.

**Disconnect** is available only after linked domains have been removed. It deletes the saved connection from this installation without changing Cloudflare zones or DNS. Rotating a token is the way to replace credentials while retaining domain links.

| Symptom | Recovery |
| --- | --- |
| Token invalid | Edit the connection and replace the expired or revoked token. |
| Access lost or permissions error | Include the zone and required permissions in the token, then refresh. |
| Zone pending or paused | Complete nameserver activation or resume the zone in Cloudflare, then refresh. |
| DNS record conflict | Compare existing records with the required type and target, resolve the conflict in Cloudflare, then use **Retry DNS setup**. Conflicting records are not overwritten automatically. |
| Requests limited or service unreachable | Wait for the displayed retry delay, or check the server's outbound connectivity; then retry. |

## Remove an address

Under **Domains → Manage → Remove from registry**, review the address before removing it. Its landing and releases remain available. DNS stays in place by default, and manual DNS is always left to the operator.

For Cloudflare-managed base or exact addresses, the optional **Also delete DNS records created by Fast Landings** checkbox cleans up only records the application created. Matching records adopted from Cloudflare are always kept. Removing a wildcard child only removes its registration and assignment; the shared wildcard DNS remains. Remove dependent children before removing their base.

## Self-hosted operation and upgrades

Keep the Laravel queue worker and scheduler running continuously. The supplied production Compose stack runs both; `composer dev` starts them for local development. The scheduler queues due domain checks every minute. Default intervals are about one minute for pending DNS, ten minutes for verified DNS, and five minutes after failures. **Check now** queues a check; it does not perform DNS lookups in the web request. **Retry DNS setup** queues a Cloudflare provisioning attempt. Closing the page does not stop this work.

If a check remains queued, inspect the queue and scheduler before changing DNS or repeatedly submitting jobs. A saved domain remains in the registry even if queue dispatch fails. Review its details and retry after restoring the worker.

**DNS verified** confirms the expected public DNS answer, not successful HTTPS issuance or page availability. The supplied production stack uses Caddy's on-demand TLS for exact registered addresses assigned to published landings with active releases. Wildcard DNS does not require a wildcard certificate in this setup. Keep ports 80/443 reachable and the configured server target correct; unknown subdomains are denied by the certificate allowlist. For custom deployments, configure equivalent host routing and HTTPS. See [production operations](../deploy/README.md).

When upgrading an existing installation, back up the database and deployment secrets, deploy the updated application, run `php artisan migrate --force` in its application runtime, and restart long-running workers. The domain-scope migration adds `dns_scope` and `parent_domain_id`; existing hostnames remain exact and keep their assignments. Wildcard behavior is selected when adding a base and is not applied automatically to old entries. Preserve the existing `APP_KEY` so saved integration tokens remain decryptable.
