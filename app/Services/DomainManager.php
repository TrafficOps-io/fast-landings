<?php

namespace App\Services;

use App\Enums\DomainKind;
use App\Enums\DomainProvider;
use App\Enums\DomainStatus;
use App\Models\Domain;
use App\Models\Installation;
use App\Models\Landing;
use App\Support\DnsTarget;
use App\Support\DomainError;
use Closure;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;
use Throwable;
use TrafficOps\Cloudflare\Contracts\CloudflareManagerContract;
use TrafficOps\Cloudflare\Dns\PublicDnsRecordChecker;
use TrafficOps\Cloudflare\DTO\CheckResult;
use TrafficOps\Cloudflare\DTO\DnsRecordExpectation;
use TrafficOps\Cloudflare\DTO\DomainData;
use TrafficOps\Cloudflare\DTO\DomainDefinition;
use TrafficOps\Cloudflare\DTO\IntegrationData;
use TrafficOps\Cloudflare\DTO\ProvisionResult;
use TrafficOps\Cloudflare\DTO\ZoneData;
use TrafficOps\Cloudflare\Enums\DomainStatus as CloudflareDomainStatus;
use TrafficOps\Cloudflare\Enums\IntegrationStatus;
use TrafficOps\Cloudflare\Exceptions\CloudflareNotFoundException;
use TrafficOps\Cloudflare\Exceptions\CloudflareRateLimitException;
use TrafficOps\Cloudflare\Exceptions\CloudflareTransportException;
use TrafficOps\Cloudflare\Exceptions\CloudflareValidationException;
use TrafficOps\Cloudflare\Support\Hostname;

class DomainManager
{
    private const CLOUDFLARE_LOCK_SECONDS = 120;

    public function __construct(
        private readonly CloudflareManagerContract $cloudflare,
        private readonly PublicDnsRecordChecker $dns,
        private readonly CacheFactory $cache,
    ) {}

    public function createSystem(?Landing $landing, string $subdomain, bool $primary = false): Domain
    {
        $installation = Installation::singleton();
        [$label, $hostname] = $this->systemHostname($installation, $subdomain);
        $this->assertNotControlHostname($hostname, $installation, 'subdomain');

        return $this->createLocalDomain($landing, [
            'hostname' => $hostname,
            'system_subdomain' => $label,
            'kind' => DomainKind::System,
            'provider' => DomainProvider::System,
            'status' => DomainStatus::Active,
            // System subdomains are trusted unconditionally: Active, hence verified, at creation.
            'verified_at' => now(),
            'dns_target' => $this->normalizeTarget($installation->origin_target),
            'last_checked_at' => now(),
            'last_error' => null,
        ], $primary);
    }

    /**
     * Create a custom domain whose DNS is managed by the administrator. An A,
     * AAAA, or CNAME pointing to the origin target proves control of the domain.
     */
    public function createManual(
        ?Landing $landing,
        string $hostname,
        ?string $dnsTarget = null,
        bool $primary = false,
        bool $wildcard = false,
    ): Domain {
        $installation = Installation::singleton();
        $hostname = $this->normalizeDnsHostname($hostname, $wildcard);
        $target = $this->normalizeTarget($dnsTarget ?? $installation->origin_target);
        $this->assertNotControlHostname($hostname, $installation);
        $this->assertNoCnameLoop($hostname, $target);

        return $this->createLocalDomain($landing, [
            'hostname' => $hostname,
            'dns_scope' => $wildcard ? 'wildcard' : 'exact',
            'system_subdomain' => null,
            'kind' => DomainKind::Custom,
            'provider' => DomainProvider::Dns,
            'status' => DomainStatus::Pending,
            'dns_target' => $target,
            'cloudflare_domain_id' => null,
            'last_checked_at' => null,
            'last_error' => null,
        ], $primary);
    }

    /** Alias matching the provider name used by the UI and database. */
    public function createDns(
        ?Landing $landing,
        string $hostname,
        ?string $dnsTarget = null,
        bool $primary = false,
        bool $wildcard = false,
    ): Domain {
        return $this->createManual($landing, $hostname, $dnsTarget, $primary, $wildcard);
    }

    /**
     * Claim a custom hostname in the Cloudflare package.
     *
     * This records the desired state but intentionally does not write remote DNS.
     * Call reconcileCloudflare() explicitly so a controller/job can report and retry
     * the external write independently from the local database transaction.
     */
    public function createCloudflare(
        ?Landing $landing,
        string $hostname,
        string $integrationId,
        string $zoneId,
        ?string $dnsTarget = null,
        bool $proxied = false,
        bool $primary = false,
        bool $wildcard = false,
    ): Domain {
        $installation = Installation::singleton();
        $target = $this->normalizeTarget($dnsTarget ?? $installation->origin_target);

        return $this->withCloudflareLock($integrationId, function () use (
            $installation,
            $landing,
            $hostname,
            $target,
            $integrationId,
            $zoneId,
            $proxied,
            $primary,
            $wildcard,
        ): Domain {
            $integration = $installation->cloudflareIntegrations()->find($integrationId);
            if ($integration?->status === IntegrationStatus::Invalid
                || in_array(strtolower((string) $integration?->token_status), ['invalid', 'expired', 'revoked'], true)
                || $integration?->token_expires_at?->isPast()) {
                throw ValidationException::withMessages([
                    'integrationId' => __('This Cloudflare connection has an invalid or expired token. Replace or refresh its token in Cloudflare connections before adding a domain.'),
                ]);
            }

            $zone = $this->zones($integrationId)->firstWhere('id', $zoneId);
            if ($zone === null) {
                throw ValidationException::withMessages([
                    'zoneId' => __('Select an active Cloudflare zone from the selected account.'),
                ]);
            }

            $hostname = trim($hostname) === '' ? $zone->name : $hostname;
            $hostname = $this->normalizeDnsHostname($hostname, $wildcard);
            if (! Hostname::belongsToZone($hostname, $zone->name)) {
                throw ValidationException::withMessages([
                    'hostname' => __('The hostname must belong to the selected Cloudflare zone.'),
                ]);
            }
            $this->assertNotControlHostname($hostname, $installation);
            $this->assertNoCnameLoop($hostname, $target);
            $expectations = array_map(
                fn (string $name): DnsRecordExpectation => new DnsRecordExpectation(DnsTarget::recordType($target), $name, $target, proxied: $proxied),
                $wildcard ? [$hostname, '*.'.$hostname] : [$hostname],
            );

            return DB::transaction(function () use (
                $installation,
                $landing,
                $hostname,
                $target,
                $integrationId,
                $zoneId,
                $expectations,
                $primary,
                $wildcard,
            ): Domain {
                $domain = $this->createLocalDomain($landing, [
                    'hostname' => $hostname,
                    'dns_scope' => $wildcard ? 'wildcard' : 'exact',
                    'system_subdomain' => null,
                    'kind' => DomainKind::Custom,
                    'provider' => DomainProvider::Cloudflare,
                    'status' => DomainStatus::Pending,
                    'dns_target' => $target,
                    'cloudflare_domain_id' => null,
                    'last_checked_at' => null,
                    'last_error' => null,
                ], $primary, wrapInTransaction: false);

                $remote = $this->cloudflare->attachDomain(
                    $installation,
                    $integrationId,
                    $zoneId,
                    new DomainDefinition($hostname, [$expectations[0]]),
                );

                $wildcardClaim = $wildcard ? $this->cloudflare->attachDomain(
                    $installation,
                    $integrationId,
                    $zoneId,
                    new DomainDefinition('*.'.$hostname, [$expectations[1]]),
                ) : null;
                $domain->update([
                    'cloudflare_domain_id' => $remote->id,
                    'cloudflare_wildcard_domain_id' => $wildcardClaim?->id,
                ]);

                return $domain->refresh();
            });
        });
    }

    /** Create one explicitly routable hostname using an existing wildcard's DNS. */
    public function createSubdomain(Domain $base, ?Landing $landing, string $label, bool $primary = false): Domain
    {
        return DB::transaction(function () use ($base, $landing, $label, $primary): Domain {
            if ($landing !== null) {
                $this->lockLanding($landing);
            }
            $base = Domain::query()->lockForUpdate()->findOrFail($base->getKey());
            if ($base->dns_scope !== 'wildcard' || $base->parent_domain_id !== null || $base->provider === DomainProvider::System) {
                throw ValidationException::withMessages([
                    'baseDomainId' => __('Choose a connected domain with wildcard DNS coverage.'),
                ]);
            }

            $label = trim($label);
            if ($label === '' || str_contains($label, '.') || str_contains($label, '*')) {
                throw ValidationException::withMessages([
                    'subdomain' => __('Enter one subdomain label, such as offer, without dots or wildcards.'),
                ]);
            }
            $hostname = $this->normalizeHostname($label.'.'.$base->hostname, 'subdomain');
            $this->assertNotControlHostname($hostname, Installation::singleton(), 'subdomain');
            $this->assertNoCnameLoop($hostname, (string) $base->dns_target, 'subdomain');

            return $this->createLocalDomain($landing, [
                'hostname' => $hostname,
                'dns_scope' => 'exact',
                'parent_domain_id' => $base->getKey(),
                'system_subdomain' => null,
                'kind' => DomainKind::Custom,
                'provider' => $base->provider,
                'status' => DomainStatus::Pending,
                'dns_target' => $base->dns_target,
                'cloudflare_domain_id' => null,
                'last_checked_at' => null,
                'last_error' => null,
            ], $primary, wrapInTransaction: false, validationField: 'subdomain');
        });
    }

    /**
     * Move a hostname to another landing, or pass null to leave it unassigned.
     * A landing always retains at most one primary domain and its first domain is
     * promoted automatically. Pass the assignment shown during confirmation as
     * expectedLandingId (null for unassigned); false disables that explicit guard.
     */
    public function reassign(
        Domain $domain,
        ?Landing $landing,
        bool $makePrimary = false,
        string|false|null $expectedLandingId = false,
    ): Domain {
        return DB::transaction(function () use ($domain, $landing, $makePrimary, $expectedLandingId): Domain {
            $current = Domain::query()->findOrFail($domain->getKey());
            $newLandingId = $landing?->getKey();
            if ($landing !== null && $newLandingId === null) {
                throw (new ModelNotFoundException)->setModel(Landing::class);
            }

            // Every writer locks landings before domains. Stable ordering also
            // allows two different domains to move in opposite directions safely.
            $landingIds = array_values(array_unique(array_filter([$current->landing_id, $newLandingId])));
            sort($landingIds);
            foreach ($landingIds as $landingId) {
                Landing::query()->lockForUpdate()->findOrFail($landingId);
            }

            $locked = Domain::query()->lockForUpdate()->findOrFail($domain->getKey());
            if ($locked->landing_id !== $current->landing_id
                || ($expectedLandingId !== false && $locked->landing_id !== $expectedLandingId)) {
                throw ValidationException::withMessages([
                    'assignmentId' => __('This domain’s assignment changed. Review its current landing and try again.'),
                ]);
            }

            $oldLandingId = $locked->landing_id;
            $wasPrimary = $locked->is_primary;

            $newPrimary = false;
            if ($newLandingId !== null) {
                $hasPrimary = Domain::query()
                    ->where('landing_id', $newLandingId)
                    ->whereKeyNot($locked->getKey())
                    ->where('is_primary', true)
                    ->exists();

                $newPrimary = $makePrimary
                    || ($oldLandingId === $newLandingId && $wasPrimary)
                    || ! $hasPrimary;

                if ($newPrimary) {
                    Domain::query()
                        ->where('landing_id', $newLandingId)
                        ->whereKeyNot($locked->getKey())
                        ->update(['is_primary' => false]);
                }
            }

            $locked->update([
                'landing_id' => $newLandingId,
                'is_primary' => $newPrimary,
            ]);

            if ($oldLandingId !== null && $oldLandingId !== $newLandingId && $wasPrimary) {
                $this->promotePrimary($oldLandingId);
            }

            return $locked->refresh();
        });
    }

    public function checkManual(Domain $domain): Domain
    {
        $domain = Domain::query()->findOrFail($domain->getKey());
        $this->assertProvider($domain, DomainProvider::Dns);
        if ($domain->parent_domain_id !== null) {
            return $this->checkInherited($domain);
        }

        try {
            $results = array_map(
                fn (DnsRecordExpectation $expectation): array => $this->dns->check($expectation, (string) $domain->getKey(), allowFlattening: true),
                $this->manualExpectations($domain),
            );
        } catch (Throwable $exception) {
            $this->markFailure($domain, DomainStatus::Unreachable, $exception);
            throw $exception;
        }

        $checked = $this->applyDnsResults($domain, $results);
        $this->refreshSubdomains($checked);

        return $checked;
    }

    /** Check the exact child hostname: explicit DNS records can override a wildcard. */
    public function checkInherited(Domain $domain): Domain
    {
        $domain = Domain::query()->with(['parentDomain.cloudflareDomain.records', 'parentDomain.cloudflareWildcardDomain.records'])->findOrFail($domain->getKey());
        $base = $domain->parentDomain;
        if ($base === null || $base->dns_scope !== 'wildcard') {
            throw new LogicException('The subdomain has no connected wildcard DNS source.');
        }
        if ($base->status !== DomainStatus::Active) {
            return $domain->transitionTo($base->status, [
                'last_checked_at' => now(),
                'last_error' => 'Verify wildcard DNS for ['.$base->hostname.'] first.',
            ]);
        }

        try {
            $proxied = false;
            if ($base->provider === DomainProvider::Cloudflare) {
                $wildcard = ($base->cloudflareWildcardDomain ?? $base->cloudflareDomain)?->records
                    ->where('desired', true)->firstWhere('name', '*.'.$base->hostname);
                if ($wildcard === null) {
                    throw new LogicException('The parent Cloudflare domain has no wildcard DNS expectation.');
                }
                $proxied = $wildcard->proxied;
            }
            $expectation = new DnsRecordExpectation(
                DnsTarget::recordType((string) $base->dns_target),
                $domain->hostname,
                (string) $base->dns_target,
                proxied: $proxied,
            );
            $result = $this->dns->check($expectation, (string) $domain->getKey(), allowFlattening: true);
        } catch (Throwable $exception) {
            $this->markFailure($domain, DomainStatus::Unreachable, $exception);
            throw $exception;
        }

        return DB::transaction(function () use ($domain, $base, $result): Domain {
            // A parent failure that arrives during the DNS lookup must win over
            // the earlier active snapshot used to start this child check.
            $base = Domain::query()->lockForUpdate()->findOrFail($base->getKey());
            if ($base->status !== DomainStatus::Active) {
                return $domain->transitionTo($base->status, [
                    'last_checked_at' => now(),
                    'last_error' => 'Verify wildcard DNS for ['.$base->hostname.'] first.',
                ]);
            }

            return $this->applyDnsResults($domain, [$result]);
        });
    }

    /** @param list<array{status: string, name: string, answers: array, evidence: string}> $results */
    private function applyDnsResults(Domain $domain, array $results): Domain
    {
        $result = collect($results)->first(fn (array $result): bool => $result['status'] !== 'matched') ?? $results[0];
        // DNS that no longer points at the origin target is Drifted only for a
        // verified domain; a never-verified domain is still propagating. Deriving
        // this from verification (not from the previous status) means a transient
        // Unreachable/Error between two checks cannot hide a real drift (ADR-0003).
        $status = match ($result['status']) {
            'matched' => DomainStatus::Active,
            'missing', 'mismatched' => $domain->verified_at !== null
                ? DomainStatus::Drifted
                : DomainStatus::PendingPropagation,
            default => DomainStatus::PendingPropagation,
        };

        return $domain->transitionTo($status, [
            'last_checked_at' => now(),
            'last_error' => $status === DomainStatus::Active
                ? null
                : "Public DNS is {$result['status']} for [{$result['name']}].",
        ]);
    }

    /** Alias matching the provider name used by the UI and database. */
    public function checkDns(Domain $domain): Domain
    {
        return $this->checkManual($domain);
    }

    public function manualExpectation(Domain $domain): DnsRecordExpectation
    {
        $this->assertProvider($domain, DomainProvider::Dns);

        if (! is_string($domain->dns_target) || trim($domain->dns_target) === '') {
            throw new LogicException('The DNS domain has no configured target.');
        }

        $target = $this->normalizeTarget($domain->dns_target);

        return new DnsRecordExpectation(DnsTarget::recordType($target), $domain->hostname, $target);
    }

    /** @return list<DnsRecordExpectation> */
    public function manualExpectations(Domain $domain): array
    {
        $apex = $this->manualExpectation($domain);

        return $domain->dns_scope === 'wildcard'
            ? [$apex, new DnsRecordExpectation($apex->type, '*.'.$domain->hostname, $apex->content)]
            : [$apex];
    }

    /** Propagate shared DNS failures and schedule exact checks after wildcard verification. */
    public function refreshSubdomains(Domain $base): void
    {
        if ($base->dns_scope !== 'wildcard') {
            return;
        }
        if ($base->status === DomainStatus::Active) {
            $base->subdomains()->whereNull('verification_request_id')->update(['next_check_at' => now()]);

            return;
        }

        Domain::transition($base->subdomains(), $base->status, [
            'last_checked_at' => $base->last_checked_at,
            'last_error' => 'Verify wildcard DNS for ['.$base->hostname.'] first.',
        ]);
    }

    public function reconcileCloudflare(Domain $domain): ProvisionResult
    {
        [$domain, $integrationId] = $this->cloudflareContext($domain);
        $installation = Installation::singleton();

        try {
            $result = $this->withCloudflareLock(
                $integrationId,
                function () use ($domain, $installation): ProvisionResult {
                    $results = array_map(
                        fn (string $id): ProvisionResult => $this->cloudflare->reconcileDomain($installation, $id),
                        $domain->cloudflareClaimIds(),
                    );

                    return new ProvisionResult(
                        (string) $domain->cloudflare_domain_id,
                        array_sum(array_column($results, 'created')),
                        array_sum(array_column($results, 'updated')),
                        array_sum(array_column($results, 'adopted')),
                        array_sum(array_column($results, 'deleted')),
                    );
                },
            );

            $this->refreshSubdomains($domain->transitionTo(DomainStatus::Pending, ['last_error' => null]));

            return $result;
        } catch (Throwable $exception) {
            $this->markFailure($domain, $this->failureStatus($exception), $exception);
            throw $exception;
        }
    }

    public function checkCloudflare(Domain $domain): Domain
    {
        if ($domain->parent_domain_id !== null) {
            return $this->checkInherited($domain);
        }
        [$domain, $integrationId] = $this->cloudflareContext($domain);
        $installation = Installation::singleton();

        try {
            $result = $this->withCloudflareLock(
                $integrationId,
                fn (): CheckResult => $this->cloudflare->checkIntegration($installation, $integrationId),
            );
        } catch (Throwable $exception) {
            $this->markFailure($domain, $this->failureStatus($exception), $exception);
            throw $exception;
        }

        $this->applyCloudflareStatuses($result, $integrationId);

        $checked = Domain::query()->findOrFail($domain->getKey());
        if (array_diff($checked->cloudflareClaimIds(), array_column($result->domains, 'id')) !== []) {
            $exception = new LogicException('The Cloudflare domain is missing from its integration check.');
            $this->markFailure($checked, DomainStatus::Error, $exception);
            throw $exception;
        }

        return $checked->refresh();
    }

    public function syncCloudflare(string $integrationId): IntegrationData
    {
        return $this->withCloudflareLock(
            $integrationId,
            fn (): IntegrationData => $this->cloudflare->sync(Installation::singleton(), $integrationId),
        );
    }

    /**
     * Selectable zones from the last account sync. Reading the list does not make
     * a remote request; syncCloudflare() refreshes the available zones explicitly.
     *
     * @return Collection<int, ZoneData>
     */
    public function zones(string $integrationId): Collection
    {
        $installation = Installation::singleton();
        if (! $installation->cloudflareIntegrations()->whereKey($integrationId)->exists()) {
            throw ValidationException::withMessages([
                'integrationId' => __('Select a Cloudflare account connected to this installation.'),
            ]);
        }

        return collect($this->cloudflare->zones($installation, $integrationId))
            ->filter(fn (ZoneData $zone): bool => $zone->status === 'active' && ! $zone->paused)
            ->values();
    }

    /**
     * Remove a domain from the panel. Managed DNS records are kept unless the
     * operator explicitly asks for cleanup, and even then only records that the
     * Cloudflare package itself created are deleted; adopted records never are.
     */
    public function remove(Domain $domain, bool $cleanupManagedRecords = false): void
    {
        $domain = Domain::query()
            ->with('cloudflareDomain.zone.account.integration')
            ->findOrFail($domain->getKey());

        $integrationId = $domain->provider === DomainProvider::Cloudflare
            ? (string) $domain->cloudflareDomain?->zone?->account?->integration_id
            : null;

        $cleanupAttempted = false;
        $performRemoval = function () use ($domain, $cleanupManagedRecords, &$cleanupAttempted): void {
            DB::transaction(function () use ($domain, $cleanupManagedRecords, &$cleanupAttempted): void {
                $current = Domain::query()->find($domain->getKey());
                if ($current === null) {
                    return;
                }
                if ($current->landing_id !== null) {
                    Landing::query()->lockForUpdate()->findOrFail($current->landing_id);
                }

                $locked = Domain::query()
                    ->with('cloudflareDomain.zone.account.integration')
                    ->lockForUpdate()
                    ->find($domain->getKey());

                if ($locked === null) {
                    return;
                }
                if ($locked->landing_id !== $current->landing_id) {
                    throw ValidationException::withMessages([
                        'assignmentId' => __('This domain’s assignment changed. Review its current landing and try again.'),
                    ]);
                }
                if ($locked->subdomains()->exists()) {
                    throw ValidationException::withMessages([
                        'domain' => __('Remove this domain’s subdomain addresses first. They still use its wildcard DNS configuration.'),
                    ]);
                }

                $landingId = $locked->landing_id;
                $wasPrimary = $locked->is_primary;

                if ($locked->provider === DomainProvider::Cloudflare && $locked->cloudflare_domain_id !== null) {
                    $cleanupAttempted = $cleanupManagedRecords;
                    foreach ($locked->cloudflareClaimIds() as $claimId) {
                        try {
                            $this->cloudflare->removeDomain(Installation::singleton(), $claimId, $cleanupManagedRecords);
                        } catch (CloudflareNotFoundException) {
                            // One claim may already be gone; still remove the other.
                        }
                    }
                }

                $locked->delete();

                if ($landingId !== null && $wasPrimary) {
                    $this->promotePrimary((string) $landingId);
                }
            });
        };

        $remove = function () use ($performRemoval, $domain, &$cleanupAttempted): void {
            try {
                $performRemoval();
            } catch (Throwable $exception) {
                if ($cleanupAttempted) {
                    // Remote deletes cannot roll back with our transaction. Keep
                    // the binding for retry, but require a successful DNS check
                    // before serving again: records may already have been deleted.
                    $domain->update(['drifted_at' => now()]);
                    $this->markFailure($domain, $this->failureStatus($exception), $exception);
                }

                throw $exception;
            }
        };

        if ($integrationId !== null && $integrationId !== '') {
            $this->withCloudflareLock($integrationId, $remove);

            return;
        }

        $remove();
    }

    /** @param array<string, mixed> $attributes */
    private function createLocalDomain(
        ?Landing $landing,
        array $attributes,
        bool $primary,
        bool $wrapInTransaction = true,
        ?string $validationField = null,
    ): Domain {
        $create = function () use ($landing, $attributes, $primary, $validationField): Domain {
            $field = $validationField ?? ($attributes['provider'] === DomainProvider::System ? 'subdomain' : 'hostname');
            $duplicate = Domain::query()->where('hostname', $attributes['hostname']);
            if ($attributes['system_subdomain'] !== null) {
                $duplicate->orWhere('system_subdomain', $attributes['system_subdomain']);
            }
            if ($duplicate->exists()) {
                throw $this->duplicateDomainException($field);
            }

            $landing = $landing === null ? null : $this->lockLanding($landing);
            $makePrimary = $landing !== null && ($primary || ! Domain::query()
                ->where('landing_id', $landing->getKey())
                ->where('is_primary', true)
                ->exists());

            if ($makePrimary) {
                Domain::query()->where('landing_id', $landing->getKey())->update(['is_primary' => false]);
            }

            try {
                return Domain::query()->create([
                    ...$attributes,
                    'landing_id' => $landing?->getKey(),
                    'is_primary' => $makePrimary,
                ]);
            } catch (UniqueConstraintViolationException $exception) {
                // The hostname may have been claimed after the earlier check.
                // Do not expose database constraint details in the form.
                if (str_contains($exception->getMessage(), 'hostname') || str_contains($exception->getMessage(), 'system_subdomain')) {
                    throw $this->duplicateDomainException($field);
                }

                throw $exception;
            }
        };

        return $wrapInTransaction ? DB::transaction($create) : $create();
    }

    private function lockLanding(Landing $landing): Landing
    {
        if ($landing->getKey() === null) {
            throw (new ModelNotFoundException)->setModel(Landing::class);
        }

        return Landing::query()->lockForUpdate()->findOrFail($landing->getKey());
    }

    private function promotePrimary(string $landingId): void
    {
        if (Domain::query()->where('landing_id', $landingId)->where('is_primary', true)->exists()) {
            return;
        }

        $replacement = Domain::query()
            ->where('landing_id', $landingId)
            ->orderBy('created_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->first();

        $replacement?->update(['is_primary' => true]);
    }

    /** @return array{string, string} */
    private function systemHostname(Installation $installation, string $subdomain): array
    {
        $label = mb_strtolower(trim($subdomain));
        $explicitLocalHostname = app()->environment('local') && (
            $label === 'localhost'
            || preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.localhost$/i', $label) === 1
        );
        $localHostname = $explicitLocalHostname
            ? ($label === 'localhost' ? 'localhost' : $this->normalizeHostname($label, 'subdomain'))
            : null;

        if ($label === '' || ($localHostname === null && str_contains($label, '.')) || str_contains($label, '*')) {
            throw ValidationException::withMessages([
                'subdomain' => __('Enter one subdomain label, such as offer, without dots or wildcards.'),
            ]);
        }

        $base = $this->normalizeApplicationHostname($installation->domain);
        $hostname = $localHostname ?? $this->normalizeHostname($label.'.'.$base, 'subdomain');
        $asciiLabel = $localHostname === null
            ? substr($hostname, 0, -strlen('.'.$base))
            : ($hostname === 'localhost' ? 'localhost' : substr($hostname, 0, -strlen('.localhost')));

        $reserved = array_map(
            fn (mixed $value): string => mb_strtolower(trim((string) $value)),
            (array) config('fast-landings.reserved_subdomains', []),
        );
        if (in_array($asciiLabel, $reserved, true)) {
            throw ValidationException::withMessages([
                'subdomain' => __('This subdomain is reserved by the installation. Choose a different name.'),
            ]);
        }

        return [$asciiLabel, $hostname];
    }

    private function normalizeTarget(string $target): string
    {
        return DnsTarget::normalize($target);
    }

    private function assertNoCnameLoop(string $hostname, string $target, string $field = 'hostname'): void
    {
        if (DnsTarget::recordType($target) === 'CNAME' && $hostname === $target) {
            throw ValidationException::withMessages([
                $field => __('This hostname is already the installation’s DNS target. Choose a different hostname to avoid a CNAME loop.'),
            ]);
        }
    }

    private function assertNotControlHostname(string $hostname, Installation $installation, string $field = 'hostname'): void
    {
        $reserved = array_unique([
            $this->normalizeApplicationHostname($installation->domain),
            $this->normalizeApplicationHostname((string) config('fast-landings.panel_domain')),
        ]);

        if (in_array($hostname, $reserved, true)) {
            throw ValidationException::withMessages([
                $field => __('This hostname is reserved for the control panel. Choose a different hostname.'),
            ]);
        }
    }

    private function normalizeHostname(string $hostname, string $field = 'hostname'): string
    {
        try {
            return Hostname::normalize($hostname);
        } catch (CloudflareValidationException) {
            throw ValidationException::withMessages([
                $field => $field === 'subdomain'
                    ? __('Enter a valid subdomain using letters, numbers and hyphens, without a URL or path.')
                    : __('Enter a valid hostname, such as offer.example.com, without https://, a path or a wildcard.'),
            ]);
        }
    }

    private function normalizeDnsHostname(string $hostname, bool $wildcard): string
    {
        if ($wildcard && str_starts_with(trim($hostname), '*.')) {
            throw ValidationException::withMessages([
                'hostname' => __('Enter the base hostname, such as example.com, without *. Both the root and wildcard DNS records will be configured.'),
            ]);
        }

        return $this->normalizeHostname($hostname);
    }

    private function duplicateDomainException(string $field): ValidationException
    {
        return ValidationException::withMessages([
            $field => __('This domain is already in the inventory. Use its assignment action to connect it to a landing.'),
        ]);
    }

    private function normalizeApplicationHostname(string $hostname): string
    {
        $hostname = mb_strtolower(rtrim(trim($hostname), '.'));

        return app()->environment('local') && $hostname === 'localhost'
            ? $hostname
            : Hostname::normalize($hostname);
    }

    private function assertProvider(Domain $domain, DomainProvider $provider): void
    {
        if ($domain->provider !== $provider) {
            throw new LogicException("Domain [{$domain->hostname}] is not managed by [{$provider->value}].");
        }
    }

    /** @return array{Domain, string} */
    private function cloudflareContext(Domain $domain): array
    {
        $domain = Domain::query()
            ->with('cloudflareDomain.zone.account.integration')
            ->findOrFail($domain->getKey());
        $this->assertProvider($domain, DomainProvider::Cloudflare);

        $integrationId = $domain->cloudflareDomain?->zone?->account?->integration_id;
        if ($domain->cloudflare_domain_id === null || $integrationId === null) {
            throw new LogicException('The domain has no attached Cloudflare domain and integration.');
        }

        return [$domain, (string) $integrationId];
    }

    private function applyCloudflareStatuses(CheckResult $result, string $integrationId): void
    {
        $claims = collect($result->domains)->keyBy('id');

        DB::transaction(function () use ($claims, $integrationId): void {
            Domain::query()->where('provider', DomainProvider::Cloudflare)
                ->whereHas('cloudflareDomain.zone.account', fn ($query) => $query->where('integration_id', $integrationId))
                ->each(function (Domain $domain) use ($claims): void {
                    $remote = collect($domain->cloudflareClaimIds())->map(fn (string $id) => $claims->get($id));
                    if ($remote->contains(null)) {
                        $domain->transitionTo(DomainStatus::Error, [
                            'last_checked_at' => now(),
                            'last_error' => 'The Cloudflare domain is missing from its integration check.',
                        ]);

                        return;
                    }
                    $statuses = $remote->map(function (DomainData $claim) use ($domain): DomainStatus {
                        $status = $this->mapCloudflareStatus($claim->status);

                        return $status === DomainStatus::PendingPropagation && $domain->verified_at !== null
                            ? DomainStatus::Drifted
                            : $status;
                    });
                    // A proven mismatch wins over a transient failure of the other
                    // claim. Both independent claims must match to verify the base.
                    $status = collect([
                        DomainStatus::Drifted, DomainStatus::Error, DomainStatus::Unreachable,
                        DomainStatus::Pending, DomainStatus::PendingPropagation, DomainStatus::Active,
                    ])->first(fn (DomainStatus $status) => $statuses->contains($status));
                    $domain->transitionTo($status, [
                        'last_checked_at' => now(),
                        'last_error' => null,
                    ]);
                });
        });

        Domain::query()->where('provider', DomainProvider::Cloudflare)
            ->where('dns_scope', 'wildcard')
            ->whereHas('cloudflareDomain.zone.account', fn ($query) => $query->where('integration_id', $integrationId))
            ->each(fn (Domain $base) => $this->refreshSubdomains($base));
    }

    private function mapCloudflareStatus(CloudflareDomainStatus $status): DomainStatus
    {
        return match ($status) {
            CloudflareDomainStatus::Pending => DomainStatus::Pending,
            CloudflareDomainStatus::PendingPropagation => DomainStatus::PendingPropagation,
            CloudflareDomainStatus::Active => DomainStatus::Active,
            CloudflareDomainStatus::Drifted => DomainStatus::Drifted,
            CloudflareDomainStatus::Unreachable => DomainStatus::Unreachable,
            CloudflareDomainStatus::Error => DomainStatus::Error,
        };
    }

    private function failureStatus(Throwable $exception): DomainStatus
    {
        return $exception instanceof CloudflareTransportException || $exception instanceof CloudflareRateLimitException
            ? DomainStatus::Unreachable
            : DomainStatus::Error;
    }

    private function markFailure(Domain $domain, DomainStatus $status, Throwable $exception): void
    {
        $this->refreshSubdomains($domain->transitionTo($status, [
            'last_checked_at' => now(),
            'last_error' => DomainError::message($exception),
        ]));
    }

    private function withCloudflareLock(string $integrationId, Closure $callback): mixed
    {
        return $this->cache->store()
            ->lock('fast-landings:cloudflare:'.$integrationId, self::CLOUDFLARE_LOCK_SECONDS)
            ->block(5, $callback);
    }
}
