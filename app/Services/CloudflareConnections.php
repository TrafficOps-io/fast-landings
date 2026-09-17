<?php

namespace App\Services;

use App\Models\Installation;
use Closure;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;
use TrafficOps\Cloudflare\Contracts\CloudflareClientContract;
use TrafficOps\Cloudflare\Contracts\CloudflareManagerContract;
use TrafficOps\Cloudflare\DTO\IntegrationData;
use TrafficOps\Cloudflare\Exceptions\CloudflareAuthenticationException;
use TrafficOps\Cloudflare\Exceptions\CloudflarePermissionException;
use TrafficOps\Cloudflare\Support\ModelResolver;

class CloudflareConnections
{
    public function __construct(
        private readonly CloudflareManagerContract $cloudflare,
        private readonly CloudflareClientContract $client,
        private readonly CacheFactory $cache,
    ) {}

    public function connect(string $label, #[SensitiveParameter] string $token): IntegrationData
    {
        $installation = Installation::singleton();

        return $this->cache->store()->lock('fast-landings:cloudflare-connections:'.$installation->id, 120)
            ->block(5, function () use ($installation, $label, $token): IntegrationData {
                $this->assertUniqueToken($installation, $token);

                return $this->cloudflare->connect($installation, $token, $label);
            });
    }

    public function update(string $integrationId, string $label, #[SensitiveParameter] string $token = ''): void
    {
        $this->withLock($integrationId, function () use ($integrationId, $label, $token): void {
            $installation = Installation::singleton();
            $integration = $installation->cloudflareIntegrations()->findOrFail($integrationId);

            if ($token === '') {
                $integration->update(['label' => $label]);

                return;
            }

            $this->assertUniqueToken($installation, $token, $integrationId);
            $verification = $this->client->verifyToken($token);
            if (($verification['status'] ?? null) !== 'active') {
                throw new CloudflareAuthenticationException('Cloudflare API token is not active.');
            }

            $zones = collect($this->client->listZones($token));
            if ($zones->isEmpty()) {
                throw new CloudflarePermissionException('Cloudflare API token does not expose any zones.');
            }

            $zoneClass = ModelResolver::class('zone');
            $linkedZones = $zoneClass::query()->with('account')->whereHas('domains')
                ->whereHas('account', fn ($query) => $query->where('integration_id', $integrationId))->get();

            // Keep the existing integration, accounts, and zones so domain links remain stable.
            foreach ($linkedZones as $zone) {
                if (! $zones->contains(fn (array $remote): bool => (string) ($remote['id'] ?? '') === $zone->cloudflare_id
                    && (string) ($remote['account']['id'] ?? '') === $zone->account->cloudflare_id)) {
                    throw ValidationException::withMessages([
                        'apiToken' => 'The replacement token must include every zone used by this connection. Missing access to '.$zone->name.'. The saved token has not changed.',
                    ]);
                }
            }

            $dnsZoneIds = $linkedZones->pluck('cloudflare_id')->push((string) $zones->first()['id'])->unique();
            foreach ($dnsZoneIds as $zoneId) {
                $this->client->listDnsRecords($token, $zoneId);
            }

            DB::transaction(function () use ($installation, $integration, $integrationId, $label, $token, $linkedZones): void {
                $integration->update([
                    'label' => $label,
                    'api_token' => $token,
                    'token_fingerprint' => hash('sha256', $token),
                ]);
                $this->cloudflare->sync($installation, $integrationId);

                // A changing upstream response must not commit a token that loses linked zones.
                foreach ($linkedZones as $zone) {
                    if ($zone->fresh()->status === 'inaccessible') {
                        throw ValidationException::withMessages([
                            'apiToken' => 'Zone access changed during verification. The saved token has not changed. Try again.',
                        ]);
                    }
                }
            });
        });
    }

    public function sync(string $integrationId): void
    {
        $this->withLock($integrationId, function () use ($integrationId): void {
            $installation = Installation::singleton();
            $installation->cloudflareIntegrations()->findOrFail($integrationId);
            $this->cloudflare->sync($installation, $integrationId);
        });
    }

    public function disconnect(string $integrationId): void
    {
        $this->withLock($integrationId, function () use ($integrationId): void {
            $installation = Installation::singleton();
            $installation->cloudflareIntegrations()->findOrFail($integrationId);
            $domainClass = ModelResolver::class('domain');

            if ($domainClass::query()->whereHas('zone.account', fn ($query) => $query->where('integration_id', $integrationId))->exists()) {
                throw ValidationException::withMessages([
                    'connection.'.$integrationId => 'Remove this connection’s domains from Domains before disconnecting. To replace credentials while keeping domains, edit the connection and rotate its token.',
                ]);
            }

            $this->cloudflare->disconnect($installation, $integrationId, cleanupManagedRecords: false);
        });
    }

    private function assertUniqueToken(Installation $installation, #[SensitiveParameter] string $token, ?string $exceptId = null): void
    {
        if ($installation->cloudflareIntegrations()->where('token_fingerprint', hash('sha256', $token))
            ->when($exceptId, fn ($query) => $query->whereKeyNot($exceptId))->exists()) {
            throw ValidationException::withMessages([
                'apiToken' => 'This token is already saved in another connection. Use that connection or provide a different token.',
            ]);
        }
    }

    private function withLock(string $integrationId, Closure $callback): mixed
    {
        // Shared with domain creation, provisioning, checks, and removal.
        return $this->cache->store()->lock('fast-landings:cloudflare:'.$integrationId, 120)->block(5, $callback);
    }
}
