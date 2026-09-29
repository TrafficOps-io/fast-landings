<?php

namespace Tests\Feature;

use App\Enums\DomainKind;
use App\Enums\DomainProvider;
use App\Enums\DomainStatus;
use App\Models\Domain;
use App\Models\Installation;
use App\Models\Landing;
use App\Models\LandingRelease;
use App\Services\DomainManager;
use Closure;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use TrafficOps\Cloudflare\Contracts\CloudflareManagerContract;
use TrafficOps\Cloudflare\Contracts\PublicDnsResolverContract;
use TrafficOps\Cloudflare\Dns\PublicDnsRecordChecker;
use TrafficOps\Cloudflare\DTO\CheckResult;
use TrafficOps\Cloudflare\DTO\DnsLookupResult;
use TrafficOps\Cloudflare\DTO\DomainData;
use TrafficOps\Cloudflare\DTO\DomainDefinition;
use TrafficOps\Cloudflare\DTO\ProvisionResult;
use TrafficOps\Cloudflare\DTO\ZoneData;
use TrafficOps\Cloudflare\Enums\DomainStatus as CloudflareDomainStatus;
use TrafficOps\Cloudflare\Enums\IntegrationStatus;
use TrafficOps\Cloudflare\Exceptions\CloudflareTransportException;
use TrafficOps\Cloudflare\Models\CloudflareDomain;
use TrafficOps\Cloudflare\Models\CloudflareIntegration;
use TrafficOps\Cloudflare\Models\CloudflareZone;
use TrafficOps\Cloudflare\Support\Hostname;

class DomainManagerTest extends TestCase
{
    use RefreshDatabase;

    private CloudflareManagerContract&MockInterface $cloudflare;

    private object $resolver;

    private DomainManager $manager;

    private Installation $installation;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.key' => 'base64:'.base64_encode(str_repeat('f', 32)),
            'cache.default' => 'array',
        ]);

        $this->cloudflare = Mockery::mock(CloudflareManagerContract::class);
        $this->resolver = new class implements PublicDnsResolverContract
        {
            /** @var array<string, list<string>> */
            public array $answers = [];

            public ?Closure $onResolve = null;

            public function resolve(string $name, string $type): DnsLookupResult
            {
                ($this->onResolve)?->__invoke($name, $type);

                return new DnsLookupResult($name, $type, $this->answers[$name.':'.$type] ?? []);
            }
        };
        $this->manager = new DomainManager(
            $this->cloudflare,
            new PublicDnsRecordChecker($this->resolver),
            app(CacheFactory::class),
        );
        $this->installation = Installation::query()->create([
            'name' => 'Fast Landings',
            'domain' => 'landings.test',
            'origin_target' => 'origin.landings.test',
        ]);
    }

    public function test_system_domains_are_normalized_and_keep_one_primary_per_landing(): void
    {
        $landing = $this->landing('First');

        $first = $this->manager->createSystem($landing, 'Offer');
        $second = $this->manager->createSystem($landing, 'Backup', primary: true);

        $this->assertSame('offer.landings.test', $first->hostname);
        $this->assertSame('offer', $first->system_subdomain);
        $this->assertSame(DomainKind::System, $first->kind);
        $this->assertSame(DomainProvider::System, $first->provider);
        $this->assertSame(DomainStatus::Active, $first->status);
        $this->assertFalse($first->refresh()->is_primary);
        $this->assertTrue($second->is_primary);
    }

    public function test_system_domains_reject_reserved_subdomains(): void
    {
        config(['fast-landings.reserved_subdomains' => ['www']]);

        $this->expectException(ValidationException::class);
        $this->manager->createSystem($this->landing('Reserved'), 'WWW');
    }

    public function test_local_system_domains_may_use_an_explicit_localhost_hostname(): void
    {
        $this->app->instance('env', 'local');

        $domain = $this->manager->createSystem($this->landing('Local'), 'Demo.Localhost');

        $this->assertSame('demo.localhost', $domain->hostname);
        $this->assertSame('demo', $domain->system_subdomain);
        $this->assertSame('http://demo.localhost', $domain->publicUrl());
    }

    public function test_localhost_itself_is_not_appended_to_the_system_domain_locally(): void
    {
        $this->app->instance('env', 'local');
        config(['app.url' => 'http://landings.localhost:8090']);

        $domain = $this->manager->createSystem($this->landing('Localhost'), 'localhost');

        $this->assertSame('localhost', $domain->hostname);
        $this->assertSame('localhost', $domain->system_subdomain);
        $this->assertSame('http://localhost:8090', $domain->publicUrl());
    }

    public function test_nested_localhost_hostname_is_not_accepted_as_a_system_subdomain(): void
    {
        $this->app->instance('env', 'local');

        $this->expectException(ValidationException::class);

        $this->manager->createSystem($this->landing('Nested localhost'), 'foo.bar.localhost');
    }

    public function test_system_domains_cannot_claim_the_control_panel_hostname(): void
    {
        config(['fast-landings.panel_domain' => 'panel.landings.test']);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('reserved for the control panel');

        $this->manager->createSystem($this->landing('Panel collision'), 'panel');
    }

    public function test_custom_domains_cannot_claim_the_control_panel_hostname(): void
    {
        config(['fast-landings.panel_domain' => 'landings.test']);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('reserved for the control panel');

        $this->manager->createManual($this->landing('Control collision'), 'landings.test');
    }

    public function test_reassigning_a_primary_domain_promotes_a_replacement(): void
    {
        $source = $this->landing('Source');
        $destination = $this->landing('Destination');
        $primary = $this->manager->createSystem($source, 'primary');
        $replacement = $this->manager->createSystem($source, 'replacement');

        $moved = $this->manager->reassign($primary, $destination);

        $this->assertSame($destination->getKey(), $moved->landing_id);
        $this->assertTrue($moved->is_primary);
        $this->assertTrue($replacement->refresh()->is_primary);
    }

    public function test_inventory_domains_can_be_created_without_a_landing_and_never_become_primary(): void
    {
        $system = $this->manager->createSystem(null, 'inventory', primary: true);
        $manual = $this->manager->createManual(null, 'inventory.example.com', primary: true);
        $dns = $this->manager->createDns(null, 'alias.example.com', primary: true);

        foreach ([$system, $manual, $dns] as $domain) {
            $this->assertNull($domain->landing_id);
            $this->assertFalse($domain->is_primary);
        }

        $landing = $this->landing('Assign from inventory');
        $assigned = $this->manager->reassign($manual, $landing, expectedLandingId: null);
        $this->assertSame($landing->id, $assigned->landing_id);
        $this->assertTrue($assigned->is_primary);
        $this->assertFalse($system->refresh()->is_primary);
    }

    public function test_unassigning_the_primary_promotes_a_replacement_and_keeps_dns_configuration(): void
    {
        $landing = $this->landing('Unassign');
        $primary = $this->manager->createManual($landing, 'primary.example.com');
        $replacement = $this->manager->createSystem($landing, 'replacement');

        $unassigned = $this->manager->reassign($primary, null, expectedLandingId: $landing->id);

        $this->assertNull($unassigned->landing_id);
        $this->assertFalse($unassigned->is_primary);
        $this->assertSame('origin.landings.test', $unassigned->dns_target);
        $this->assertSame(DomainStatus::Pending, $unassigned->status);
        $this->assertTrue($replacement->refresh()->is_primary);
    }

    public function test_assignment_preserves_destination_primary_unless_explicitly_replaced(): void
    {
        $source = $this->landing('Source');
        $destination = $this->landing('Destination');
        $moved = $this->manager->createSystem($source, 'moved');
        $replacement = $this->manager->createSystem($source, 'replacement');
        $existingPrimary = $this->manager->createSystem($destination, 'existing');

        $moved = $this->manager->reassign($moved, $destination, expectedLandingId: $source->id);
        $this->assertFalse($moved->is_primary);
        $this->assertTrue($existingPrimary->refresh()->is_primary);
        $this->assertTrue($replacement->refresh()->is_primary);

        $moved = $this->manager->reassign($moved, $destination, makePrimary: true, expectedLandingId: $destination->id);
        $this->assertTrue($moved->is_primary);
        $this->assertFalse($existingPrimary->refresh()->is_primary);
    }

    public function test_assignment_confirmation_cannot_overwrite_a_newer_assignment(): void
    {
        $source = $this->landing('Original');
        $current = $this->landing('New assignment');
        $staleDestination = $this->landing('Stale destination');
        $domain = $this->manager->createSystem($source, 'moved');
        $this->manager->reassign($domain, $current, expectedLandingId: $source->id);

        try {
            $this->manager->reassign($domain, $staleDestination, expectedLandingId: $source->id);
            $this->fail('A stale confirmation overwrote a newer assignment.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('assignmentId', $exception->errors());
            $this->assertSame($current->id, $domain->refresh()->landing_id);
            $this->assertTrue($domain->is_primary);
            $this->assertSame(0, $staleDestination->domains()->count());
        }
    }

    public function test_expected_unassigned_is_checked_even_when_the_expected_id_is_null(): void
    {
        $domain = $this->manager->createSystem(null, 'claimed');
        $current = $this->landing('Current');
        $this->manager->reassign($domain, $current, expectedLandingId: null);

        try {
            $this->manager->reassign($domain, $this->landing('Other'), expectedLandingId: null);
            $this->fail('An already assigned domain was claimed using a stale inventory view.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('assignmentId', $exception->errors());
            $this->assertSame($current->id, $domain->refresh()->landing_id);
        }
    }

    #[DataProvider('invalidDomainInputs')]
    public function test_invalid_domain_inputs_have_actionable_field_errors(string $provider, string $input, string $field): void
    {
        config(['fast-landings.reserved_subdomains' => ['www']]);

        try {
            $provider === 'system'
                ? $this->manager->createSystem(null, $input)
                : $this->manager->createManual(null, $input);
            $this->fail('An invalid domain was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
            $this->assertDatabaseCount('domains', 0);
        }
    }

    /** @return array<string, array{string, string, string}> */
    public static function invalidDomainInputs(): array
    {
        return [
            'URL instead of hostname' => ['dns', 'https://offer.example.com/path', 'hostname'],
            'wildcard' => ['dns', '*.example.com', 'hostname'],
            'control panel' => ['dns', 'landings.test', 'hostname'],
            'self-referencing target' => ['dns', 'origin.landings.test', 'hostname'],
            'nested system label' => ['system', 'offer.example', 'subdomain'],
            'invalid system label' => ['system', '-offer', 'subdomain'],
            'reserved system label' => ['system', 'www', 'subdomain'],
        ];
    }

    public function test_duplicate_normalized_domains_report_a_field_error_without_changing_primary(): void
    {
        $landing = $this->landing('Duplicates');
        $original = $this->manager->createManual($landing, 'offer.example.com');

        try {
            $this->manager->createManual(null, ' OFFER.EXAMPLE.COM. ', primary: true);
            $this->fail('A duplicate hostname was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('hostname', $exception->errors());
            $this->assertStringContainsString('already in the inventory', $exception->errors()['hostname'][0]);
            $this->assertTrue($original->refresh()->is_primary);
            $this->assertDatabaseCount('domains', 1);
        }
    }

    public function test_duplicate_system_domains_report_the_subdomain_field(): void
    {
        $this->manager->createSystem(null, 'offer');

        try {
            $this->manager->createSystem(null, 'OFFER');
            $this->fail('A duplicate system domain was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('subdomain', $exception->errors());
            $this->assertDatabaseCount('domains', 1);
        }
    }

    public function test_status_copy_distinguishes_dns_verification_from_self_hosted_infrastructure(): void
    {
        $system = $this->manager->createSystem(null, 'system');
        $manual = $this->manager->createManual(null, 'offer.example.com');
        $manual->update(['status' => DomainStatus::Active]);

        $this->assertSame('Infrastructure managed', $system->statusLabel());
        $this->assertSame('neutral', $system->statusTone());
        $this->assertStringContainsString('not verified here', $system->statusDescription());
        $this->assertSame('DNS verified', $manual->statusLabel());
        $this->assertSame('success', $manual->statusTone());
        $this->assertStringContainsString('HTTPS and landing availability still depend', $manual->statusDescription());
    }

    public function test_wildcard_dns_requires_both_root_and_wildcard_and_children_are_checked_individually(): void
    {
        $base = $this->manager->createManual(null, 'example.com', wildcard: true);
        $child = $this->manager->createSubdomain($base, $this->landing('Offer'), 'Offer');
        $probe = Hostname::wildcardProbe('*.example.com', $base->id);

        $this->assertSame('wildcard', $base->dns_scope);
        $this->assertSame(['example.com', '*.example.com'], $base->dnsRecordNames());
        $this->assertSame(['example.com', '*.example.com'], array_column($this->manager->manualExpectations($base), 'name'));
        $this->assertSame('offer.example.com', $child->hostname);
        $this->assertSame($base->id, $child->parent_domain_id);
        $this->assertSame('exact', $child->dns_scope);
        $this->assertTrue($child->is_primary);

        $this->resolver->answers['example.com:CNAME'] = ['origin.landings.test'];
        $this->assertSame(DomainStatus::PendingPropagation, $this->manager->checkManual($base)->status);
        $this->assertSame(DomainStatus::PendingPropagation, $child->refresh()->status);
        $this->assertStringContainsString('wildcard DNS', $child->last_error);

        $this->resolver->answers[$probe.':CNAME'] = ['origin.landings.test'];
        $this->assertSame(DomainStatus::Active, $this->manager->checkManual($base)->status);
        $this->assertNotNull($child->refresh()->next_check_at);
        $this->assertNotSame(DomainStatus::Active, $child->status);

        // An explicit external DNS record can override the verified wildcard.
        $this->resolver->answers['offer.example.com:CNAME'] = ['different-origin.example.com'];
        $this->assertSame(DomainStatus::PendingPropagation, $this->manager->checkDns($child)->status);
        $this->resolver->answers['offer.example.com:CNAME'] = ['origin.landings.test'];
        $this->assertSame(DomainStatus::Active, $this->manager->checkDns($child)->status);

        $this->resolver->answers[$probe.':CNAME'] = [];
        $this->assertSame(DomainStatus::Drifted, $this->manager->checkManual($base)->status);
        $this->assertSame(DomainStatus::Drifted, $child->refresh()->status);

        $this->resolver->answers[$probe.':CNAME'] = ['origin.landings.test'];
        $this->manager->checkManual($base);
        $this->assertSame(DomainStatus::Drifted, $child->refresh()->status);
        $this->assertSame(DomainStatus::Active, $this->manager->checkDns($child)->status);
    }

    public function test_wildcard_base_hostname_is_explicit_and_cannot_contain_a_star(): void
    {
        try {
            $this->manager->createManual(null, '*.example.com', wildcard: true);
            $this->fail('A wildcard DNS scope accepted a wildcard route hostname.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('hostname', $exception->errors());
            $this->assertStringContainsString('base hostname', $exception->errors()['hostname'][0]);
            $this->assertDatabaseCount('domains', 0);
        }
    }

    public function test_parent_failure_during_child_dns_lookup_cannot_be_overwritten_by_stale_success(): void
    {
        $base = $this->manager->createManual(null, 'example.com', wildcard: true);
        $base->update(['status' => DomainStatus::Active]);
        $child = $this->manager->createSubdomain($base, null, 'offer');
        $this->resolver->answers['offer.example.com:CNAME'] = ['origin.landings.test'];
        $this->resolver->onResolve = function () use ($base): void {
            $base->update(['status' => DomainStatus::Drifted]);
            $this->manager->refreshSubdomains($base);
        };

        $checked = $this->manager->checkDns($child);

        $this->assertSame(DomainStatus::Drifted, $checked->status);
        $this->assertStringContainsString('Verify wildcard DNS', $checked->last_error);
    }

    public function test_subdomains_require_a_wildcard_base_and_do_not_create_a_catchall_route(): void
    {
        $exact = $this->manager->createManual(null, 'exact.example.com');
        try {
            $this->manager->createSubdomain($exact, null, 'offer');
            $this->fail('An exact-only domain was accepted as wildcard coverage.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('baseDomainId', $exception->errors());
        }

        $base = $this->manager->createManual(null, 'example.com', wildcard: true);
        $child = $this->manager->createSubdomain($base, null, 'offer', primary: true);
        $this->assertNull($child->landing_id);
        $this->assertFalse($child->is_primary);
        $this->assertDatabaseMissing('domains', ['hostname' => '*.example.com']);
        $this->assertDatabaseMissing('domains', ['hostname' => 'unknown.example.com']);
    }

    #[DataProvider('invalidSubdomainLabels')]
    public function test_child_labels_are_validated_against_the_selected_base(string $label): void
    {
        $base = $this->manager->createManual(null, 'example.com', wildcard: true);

        try {
            $this->manager->createSubdomain($base, null, $label);
            $this->fail('An invalid child subdomain label was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('subdomain', $exception->errors());
            $this->assertDatabaseCount('domains', 1);
        }
    }

    /** @return array<string, array{string}> */
    public static function invalidSubdomainLabels(): array
    {
        return [
            'empty' => [''],
            'full hostname' => ['offer.example.com'],
            'wildcard' => ['*'],
            'nested' => ['nested.offer'],
            'URL' => ['https://offer'],
            'invalid DNS label' => ['-offer'],
        ];
    }

    public function test_subdomain_creation_reuses_hostname_uniqueness_across_independent_dns_entries(): void
    {
        $base = $this->manager->createManual(null, 'example.com', wildcard: true);
        $existing = $this->manager->createManual(null, 'offer.example.com');

        try {
            $this->manager->createSubdomain($base, $this->landing('Duplicate child'), 'OFFER');
            $this->fail('A duplicate child hostname was created.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('subdomain', $exception->errors());
            $this->assertNull($existing->refresh()->parent_domain_id);
            $this->assertNull($existing->landing_id);
            $this->assertDatabaseCount('domains', 2);
        }
    }

    #[DataProvider('cloudflareProxyModes')]
    public function test_cloudflare_wildcard_has_two_records_and_child_checks_use_shared_dns_without_remote_writes(bool $proxied): void
    {
        [$integrationId, $zoneId] = $this->cloudflareTree();
        $this->expectZones($integrationId, [ZoneData::fromModel(CloudflareZone::findOrFail($zoneId))]);
        $this->cloudflare->shouldReceive('attachDomain')->twice()
            ->withArgs(fn ($owner, $integration, $zone, DomainDefinition $definition): bool => $integration === $integrationId
                && $zone === $zoneId && in_array($definition->hostname, ['example.com', '*.example.com'], true)
                && array_column($definition->records, 'name') === [$definition->hostname]
                && $definition->records[0]->proxied === $proxied)
            ->andReturnUsing(function ($owner, $integration, $zone, DomainDefinition $definition): DomainData {
                $remote = CloudflareDomain::query()->create([
                    'zone_id' => $zone, 'hostname' => $definition->hostname,
                    'kind' => $definition->isWildcard() ? 'wildcard' : 'exact', 'status' => CloudflareDomainStatus::Pending,
                ]);
                foreach ($definition->records as $record) {
                    $remote->records()->create([...$record->toArray(), 'signature' => $record->signature()]);
                }

                return new DomainData($remote->id, $definition->hostname, 'exact', CloudflareDomainStatus::Pending, null);
            });
        $base = $this->manager->createCloudflare(null, 'example.com', $integrationId, $zoneId, proxied: $proxied, wildcard: true);
        $child = $this->manager->createSubdomain($base, null, 'offer');
        $this->assertNull($child->cloudflare_domain_id);
        $this->assertSame($base->id, $child->dnsSource()->id);
        $this->assertDatabaseCount('cloudflare_domains', 2);
        $this->assertDatabaseCount('cloudflare_domain_records', 2);

        $this->cloudflare->shouldReceive('checkIntegration')->once()
            ->andReturn(new CheckResult($integrationId, IntegrationStatus::Active, [
                new DomainData($base->cloudflare_domain_id, 'example.com', 'exact', CloudflareDomainStatus::Active, now()),
                new DomainData($base->cloudflare_wildcard_domain_id, '*.example.com', 'wildcard', CloudflareDomainStatus::Active, now()),
            ]));
        $this->manager->checkCloudflare($base);
        $type = $proxied ? 'A' : 'CNAME';
        $this->resolver->answers['offer.example.com:'.$type] = $proxied ? ['192.0.2.12'] : ['origin.landings.test'];
        $this->assertSame(DomainStatus::Active, $this->manager->checkCloudflare($child)->status);

        // Removing a child only removes its exact routing entry.
        $this->cloudflare->shouldNotReceive('removeDomain');
        $this->manager->remove($child);
        $this->assertDatabaseHas('domains', ['id' => $base->id]);
        $this->assertDatabaseCount('cloudflare_domain_records', 2);
    }

    /** @return array<string, array{bool}> */
    public static function cloudflareProxyModes(): array
    {
        return ['DNS only' => [false], 'proxied' => [true]];
    }

    public function test_shared_wildcard_cannot_be_removed_while_child_addresses_use_it(): void
    {
        $base = $this->manager->createManual(null, 'example.com', wildcard: true);
        $child = $this->manager->createSubdomain($base, null, 'offer');

        try {
            $this->manager->remove($base);
            $this->fail('A shared wildcard was deleted while a child still used its DNS.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('domain', $exception->errors());
            $this->assertDatabaseCount('domains', 2);
        }

        $this->manager->remove($child);
        $this->manager->remove($base);
        $this->assertDatabaseCount('domains', 0);
    }

    public function test_manual_dns_check_activates_a_matching_cname_and_detects_later_drift(): void
    {
        $domain = $this->manager->createManual($this->landing('Manual'), 'Promo.Example.COM');
        $this->resolver->answers['promo.example.com:CNAME'] = ['ORIGIN.LANDINGS.TEST.'];

        $active = $this->manager->checkManual($domain);

        $this->assertSame(DomainProvider::Dns, $active->provider);
        $this->assertSame(DomainStatus::Active, $active->status);
        $this->assertNull($active->last_error);

        $this->resolver->answers['promo.example.com:CNAME'] = ['other.example.com'];
        $drifted = $this->manager->checkManual($active);

        $this->assertSame(DomainStatus::Drifted, $drifted->status);
        $this->assertNotNull($drifted->last_checked_at);
        $this->assertStringContainsString('mismatched', $drifted->last_error);
    }

    public function test_domain_becomes_verified_the_first_time_a_check_finds_it_active(): void
    {
        $domain = $this->manager->createManual($this->landing('Manual'), 'promo.example.com');
        $this->assertNull($domain->verified_at);

        $this->manager->checkManual($domain);
        $this->assertNull($domain->refresh()->verified_at);
        $this->assertSame(DomainStatus::PendingPropagation, $domain->status);

        $this->resolver->answers['promo.example.com:CNAME'] = ['origin.landings.test'];
        $verifiedAt = $this->manager->checkManual($domain)->verified_at;
        $this->assertNotNull($verifiedAt);

        $this->resolver->answers['promo.example.com:CNAME'] = ['other.example.com'];
        $drifted = $this->manager->checkManual($domain);
        $this->assertSame(DomainStatus::Drifted, $drifted->status);
        $this->assertTrue($verifiedAt->equalTo($drifted->verified_at), 'Verification is recorded once and never cleared.');
    }

    public function test_verified_domain_that_loses_dns_after_a_transient_failure_is_drifted_and_not_served(): void
    {
        $domain = $this->manager->createManual($this->publishedLanding('Steady'), 'promo.example.com');
        $this->resolver->answers['promo.example.com:CNAME'] = ['origin.landings.test'];
        $this->manager->checkManual($domain);
        $this->assertTrue($this->isServed($domain));

        $this->resolver->onResolve = fn () => throw new \RuntimeException('resolver timeout');
        try {
            $this->manager->checkManual($domain);
        } catch (\RuntimeException) {
        }
        $this->assertSame(DomainStatus::Unreachable, $domain->refresh()->status);
        $this->assertTrue($this->isServed($domain), 'A transient failure does not stop serving.');

        $this->resolver->onResolve = null;
        $this->resolver->answers['promo.example.com:CNAME'] = [];
        $this->assertSame(DomainStatus::Drifted, $this->manager->checkManual($domain)->status, 'Drift is derived from verification, not from the previous status.');
        $this->assertFalse($this->isServed($domain));
    }

    public function test_verified_child_domain_that_loses_dns_after_the_base_failed_transiently_is_drifted(): void
    {
        $base = $this->manager->createManual(null, 'example.com', wildcard: true);
        $child = $this->manager->createSubdomain($base, $this->publishedLanding('Offer'), 'offer');
        $probe = Hostname::wildcardProbe('*.example.com', $base->id);
        $this->resolver->answers['example.com:CNAME'] = ['origin.landings.test'];
        $this->resolver->answers[$probe.':CNAME'] = ['origin.landings.test'];
        $this->resolver->answers['offer.example.com:CNAME'] = ['origin.landings.test'];
        $this->manager->checkManual($base);
        $this->assertSame(DomainStatus::Active, $this->manager->checkDns($child)->status);
        $this->assertNotNull($child->refresh()->verified_at, 'A child verified by its own check records verified_at.');
        $this->assertTrue($this->isServed($child));

        $this->resolver->onResolve = fn () => throw new \RuntimeException('resolver timeout');
        try {
            $this->manager->checkManual($base);
        } catch (\RuntimeException) {
        }
        $this->assertSame(DomainStatus::Unreachable, $child->refresh()->status, 'The base failure is copied onto its children.');
        $this->assertTrue($this->isServed($child));

        $this->resolver->onResolve = null;
        $this->manager->checkManual($base);
        $this->resolver->answers['offer.example.com:CNAME'] = ['elsewhere.example.net'];
        $this->assertSame(DomainStatus::Drifted, $this->manager->checkDns($child)->status);
        $this->assertFalse($this->isServed($child));
    }

    public function test_verified_domain_stays_verified_through_an_unreachable_check(): void
    {
        $domain = $this->manager->createManual($this->landing('Manual'), 'promo.example.com');
        $this->resolver->answers['promo.example.com:CNAME'] = ['origin.landings.test'];
        $this->manager->checkManual($domain);
        $this->resolver->onResolve = fn () => throw new \RuntimeException('resolver timeout');

        try {
            $this->manager->checkManual($domain);
        } catch (\RuntimeException) {
        }

        $this->assertSame(DomainStatus::Unreachable, $domain->refresh()->status);
        $this->assertNotNull($domain->verified_at);
    }

    public function test_system_domains_are_verified_on_creation(): void
    {
        $domain = $this->manager->createSystem($this->landing('System'), 'offer');

        $this->assertNotNull($domain->refresh()->verified_at);
    }

    public function test_cloudflare_check_marks_the_domain_verified_when_active(): void
    {
        [$integrationId, $zoneId] = $this->cloudflareTree();
        $remote = CloudflareDomain::query()->create([
            'zone_id' => $zoneId, 'hostname' => 'promo.example.com', 'kind' => 'exact', 'status' => CloudflareDomainStatus::Pending,
        ]);
        $domain = Domain::query()->create([
            'landing_id' => $this->landing('Cloudflare')->getKey(),
            'hostname' => 'promo.example.com',
            'kind' => DomainKind::Custom,
            'provider' => DomainProvider::Cloudflare,
            'status' => DomainStatus::Pending,
            'is_primary' => true,
            'dns_target' => 'origin.landings.test',
            'cloudflare_domain_id' => $remote->id,
        ]);
        $this->assertNull($domain->verified_at);
        $this->cloudflare->shouldReceive('checkIntegration')->once()->andReturn(new CheckResult($integrationId, IntegrationStatus::Active, [
            new DomainData($remote->id, 'promo.example.com', 'exact', CloudflareDomainStatus::Active, now()),
        ]));

        $checked = $this->manager->checkCloudflare($domain);

        $this->assertSame(DomainStatus::Active, $checked->status);
        $this->assertNotNull($checked->verified_at);
    }

    #[DataProvider('addressTargets')]
    public function test_manual_domains_use_address_records_and_check_equivalent_address_formats(
        string $input,
        string $target,
        string $type,
        string $matchingAnswer,
        string $wrongAnswer,
    ): void {
        $this->installation->update(['origin_target' => $input]);
        $domain = $this->manager->createManual($this->landing('Address origin'), 'example.com');
        $expectation = $this->manager->manualExpectation($domain);

        $this->assertSame($target, $domain->dns_target);
        $this->assertSame($type, $domain->dnsRecordType());
        $this->assertSame($type, $expectation->type);
        $this->assertSame($target, $expectation->content);

        $this->resolver->answers['example.com:'.$type] = [$matchingAnswer];
        $this->assertSame(DomainStatus::Active, $this->manager->checkManual($domain)->status);

        $this->resolver->answers['example.com:'.$type] = [$wrongAnswer];
        $this->assertSame(DomainStatus::Drifted, $this->manager->checkManual($domain)->status);
    }

    #[DataProvider('addressTargets')]
    public function test_cloudflare_domains_expect_a_or_aaaa_records_for_address_origins(
        string $input,
        string $target,
        string $type,
    ): void {
        $this->installation->update(['origin_target' => $input]);
        [$integrationId, $zoneId] = $this->cloudflareTree();
        $this->expectZones($integrationId, [ZoneData::fromModel(CloudflareZone::findOrFail($zoneId))]);
        $this->cloudflare->shouldReceive('attachDomain')->once()
            ->withArgs(fn (Installation $owner, string $integration, string $zone, DomainDefinition $definition): bool => $owner->is($this->installation)
                && $integration === $integrationId && $zone === $zoneId
                && $definition->hostname === 'example.com'
                && $definition->records[0]->type === $type
                && $definition->records[0]->content === $target
                && $definition->records[0]->proxied === false)
            ->andReturnUsing(function ($owner, $integration, $zone, DomainDefinition $definition): DomainData {
                $remote = CloudflareDomain::query()->create([
                    'zone_id' => $zone, 'hostname' => $definition->hostname,
                    'kind' => 'exact', 'status' => CloudflareDomainStatus::Pending,
                ]);

                return new DomainData((string) $remote->getKey(), $definition->hostname, 'exact', CloudflareDomainStatus::Pending, null);
            });

        $domain = $this->manager->createCloudflare($this->landing('Cloudflare address origin'), '', $integrationId, $zoneId);

        $this->assertSame($target, $domain->dns_target);
        $this->assertSame($type, $domain->dnsRecordType());
        $this->cloudflare->shouldNotHaveReceived('reconcileDomain');
    }

    /** @return array<string, array{string, string, string, string, string}> */
    public static function addressTargets(): array
    {
        return [
            'IPv4' => [' 203.0.113.42 ', '203.0.113.42', 'A', '203.0.113.42', '203.0.113.43'],
            'IPv6' => [' 2001:0DB8:0000:0000:0000:0000:0000:0042 ', '2001:db8::42', 'AAAA', '2001:db8:0:0:0:0:0:42', '2001:db8::43'],
        ];
    }

    public function test_cloudflare_domain_can_be_created_reconciled_and_checked(): void
    {
        [$integrationId, $zoneId] = $this->cloudflareTree();
        $this->expectZones($integrationId, [ZoneData::fromModel(CloudflareZone::findOrFail($zoneId))]);
        $remoteId = (string) Str::ulid();

        $this->cloudflare->shouldReceive('attachDomain')
            ->once()
            ->withArgs(function (Installation $owner, string $actualIntegrationId, string $actualZoneId, DomainDefinition $definition) use ($integrationId, $zoneId, $remoteId): bool {
                if (! $owner->is($this->installation)
                    || $actualIntegrationId !== $integrationId
                    || $actualZoneId !== $zoneId
                    || $definition->hostname !== 'promo.example.com'
                    || $definition->records[0]->content !== 'origin.landings.test'
                    || $definition->records[0]->proxied !== false) {
                    return false;
                }

                CloudflareDomain::query()->create([
                    'id' => $remoteId,
                    'zone_id' => $zoneId,
                    'hostname' => $definition->hostname,
                    'kind' => 'exact',
                    'status' => CloudflareDomainStatus::Pending,
                ]);

                return true;
            })
            ->andReturn(new DomainData($remoteId, 'promo.example.com', 'exact', CloudflareDomainStatus::Pending, null));

        $domain = $this->manager->createCloudflare(
            $this->landing('Cloudflare'),
            'promo.example.com',
            $integrationId,
            $zoneId,
        );

        $this->assertSame($remoteId, $domain->cloudflare_domain_id);
        $this->assertSame(DomainProvider::Cloudflare, $domain->provider);

        $this->cloudflare->shouldReceive('reconcileDomain')
            ->once()
            ->withArgs(fn (Installation $owner, string $id): bool => $owner->is($this->installation) && $id === $remoteId)
            ->andReturn(new ProvisionResult($remoteId, 1, 0, 0, 0));

        $provision = $this->manager->reconcileCloudflare($domain);
        $this->assertSame(1, $provision->created);
        $this->assertSame(DomainStatus::Pending, $domain->refresh()->status);

        $missingRemoteId = (string) Str::ulid();
        CloudflareDomain::query()->create([
            'id' => $missingRemoteId,
            'zone_id' => $zoneId,
            'hostname' => 'missing.example.com',
            'kind' => 'exact',
            'status' => CloudflareDomainStatus::Active,
        ]);
        $missing = Domain::query()->create([
            'landing_id' => $this->landing('Missing Cloudflare binding')->getKey(),
            'hostname' => 'missing.example.com',
            'kind' => DomainKind::Custom,
            'provider' => DomainProvider::Cloudflare,
            'status' => DomainStatus::Active,
            'is_primary' => true,
            'dns_target' => 'origin.landings.test',
            'cloudflare_domain_id' => $missingRemoteId,
        ]);

        $checkedAt = now();
        $this->cloudflare->shouldReceive('checkIntegration')
            ->once()
            ->withArgs(fn (Installation $owner, string $id): bool => $owner->is($this->installation) && $id === $integrationId)
            ->andReturn(new CheckResult($integrationId, IntegrationStatus::Active, [
                new DomainData($remoteId, 'promo.example.com', 'exact', CloudflareDomainStatus::Active, $checkedAt),
            ]));

        $checked = $this->manager->checkCloudflare($domain);
        $this->assertSame(DomainStatus::Active, $checked->status);
        $this->assertNotNull($checked->last_checked_at);
        $this->assertSame(DomainStatus::Error, $missing->refresh()->status);
        $this->assertStringContainsString('missing', (string) $missing->last_error);
    }

    public function test_cloudflare_zones_are_scoped_to_the_selected_account_and_only_return_active_unpaused_zones(): void
    {
        [$integrationId, $zoneId] = $this->cloudflareTree();
        $available = ZoneData::fromModel(CloudflareZone::findOrFail($zoneId));
        $this->expectZones($integrationId, [
            $available,
            new ZoneData('pending', 'remote-pending', 'pending.example.com', 'pending', false),
            new ZoneData('inaccessible', 'remote-inaccessible', 'inaccessible.example.com', 'inaccessible', false),
            new ZoneData('paused', 'remote-paused', 'paused.example.com', 'active', true),
        ]);

        $zones = $this->manager->zones($integrationId);

        $this->assertSame([$available], $zones->all());
        $this->cloudflare->shouldNotHaveReceived('sync');
    }

    #[DataProvider('usableConnectionStatuses')]
    public function test_cloudflare_registration_uses_the_selected_zone_apex_when_hostname_is_empty(string $status): void
    {
        [$integrationId, $zoneId] = $this->cloudflareTree();
        CloudflareIntegration::findOrFail($integrationId)->update(['status' => $status]);
        $this->expectZones($integrationId, [ZoneData::fromModel(CloudflareZone::findOrFail($zoneId))]);
        $this->cloudflare->shouldReceive('attachDomain')->once()
            ->withArgs(fn (Installation $owner, string $integration, string $zone, DomainDefinition $definition): bool => $owner->is($this->installation)
                && $integration === $integrationId && $zone === $zoneId
                && $definition->hostname === 'example.com'
                && $definition->records[0]->proxied === false)
            ->andReturnUsing(function ($owner, $integration, $zone, DomainDefinition $definition): DomainData {
                $remote = CloudflareDomain::query()->create([
                    'zone_id' => $zone,
                    'hostname' => $definition->hostname,
                    'kind' => 'exact',
                    'status' => CloudflareDomainStatus::Pending,
                ]);

                return new DomainData((string) $remote->getKey(), $definition->hostname, 'exact', CloudflareDomainStatus::Pending, null);
            });
        $this->cloudflare->shouldNotReceive('reconcileDomain', 'sync');

        $domain = $this->manager->createCloudflare(null, ' ', $integrationId, $zoneId, primary: true);

        $this->assertSame('example.com', $domain->hostname);
        $this->assertSame(DomainStatus::Pending, $domain->status);
        $this->assertNull($domain->landing_id);
        $this->assertFalse($domain->is_primary);
    }

    /** @return array<string, array{string}> */
    public static function usableConnectionStatuses(): array
    {
        return ['active' => ['active'], 'DNS drift' => ['degraded'], 'temporarily unreachable' => ['unreachable']];
    }

    #[DataProvider('unusableCloudflareTokens')]
    public function test_cloudflare_registration_rejects_known_invalid_tokens_before_mutating_domains(array $attributes): void
    {
        [$integrationId, $zoneId] = $this->cloudflareTree();
        CloudflareIntegration::findOrFail($integrationId)->update($attributes);
        $this->cloudflare->shouldNotReceive('zones', 'attachDomain');

        try {
            $this->manager->createCloudflare(null, '', $integrationId, $zoneId);
            $this->fail('A known invalid connection was accepted for domain creation.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('integrationId', $exception->errors());
            $this->assertDatabaseCount('domains', 0);
            $this->assertDatabaseCount('cloudflare_domains', 0);
        }
    }

    /** @return array<string, array{array<string, string>}> */
    public static function unusableCloudflareTokens(): array
    {
        return [
            'known invalid connection' => [['status' => 'invalid']],
            'invalid token' => [['token_status' => 'invalid']],
            'expired token' => [['token_status' => 'expired']],
            'revoked token' => [['token_status' => 'revoked']],
            'expiration date in the past' => [['token_expires_at' => '2000-01-01 00:00:00']],
        ];
    }

    public function test_duplicate_cloudflare_hostname_is_rejected_before_attaching_remote_state(): void
    {
        $existing = $this->manager->createManual(null, 'example.com');
        [$integrationId, $zoneId] = $this->cloudflareTree();
        $this->expectZones($integrationId, [ZoneData::fromModel(CloudflareZone::findOrFail($zoneId))]);
        $this->cloudflare->shouldNotReceive('attachDomain');

        try {
            $this->manager->createCloudflare(null, ' ', $integrationId, $zoneId);
            $this->fail('A duplicate Cloudflare hostname was attached.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('hostname', $exception->errors());
            $this->assertDatabaseCount('domains', 1);
            $this->assertSame(DomainProvider::Dns, $existing->refresh()->provider);
        }
    }

    public function test_cloudflare_registration_rejects_another_accounts_zone(): void
    {
        [$integrationId, $zoneId] = $this->cloudflareTree();
        [, $otherZoneId] = $this->cloudflareTree();
        $this->expectZones($integrationId, [ZoneData::fromModel(CloudflareZone::findOrFail($zoneId))]);
        $this->cloudflare->shouldNotReceive('attachDomain');

        try {
            $this->manager->createCloudflare($this->landing('Wrong account'), '', $integrationId, $otherZoneId);
            $this->fail('A zone belonging to another account was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('zoneId', $exception->errors());
            $this->assertDatabaseCount('domains', 0);
        }
    }

    public function test_cloudflare_registration_rejects_integrations_outside_this_installation(): void
    {
        [$integrationId, $zoneId] = $this->cloudflareTree();
        CloudflareIntegration::findOrFail($integrationId)->update(['owner_id' => (string) Str::ulid()]);
        $this->cloudflare->shouldNotReceive('zones', 'attachDomain');

        try {
            $this->manager->createCloudflare($this->landing('Wrong owner'), '', $integrationId, $zoneId);
            $this->fail('An integration belonging to another owner was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('integrationId', $exception->errors());
            $this->assertDatabaseCount('domains', 0);
        }
    }

    #[DataProvider('unselectableCloudflareZones')]
    public function test_cloudflare_registration_rejects_inactive_inaccessible_and_paused_zones(string $status, bool $paused): void
    {
        [$integrationId, $zoneId] = $this->cloudflareTree();
        $zone = CloudflareZone::findOrFail($zoneId);
        $zone->update(['status' => $status, 'paused' => $paused]);
        $this->expectZones($integrationId, [ZoneData::fromModel($zone)]);
        $this->cloudflare->shouldNotReceive('attachDomain');

        try {
            $this->manager->createCloudflare($this->landing('Unavailable zone'), '', $integrationId, $zoneId);
            $this->fail('An unavailable zone was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('zoneId', $exception->errors());
            $this->assertDatabaseCount('domains', 0);
        }
    }

    /** @return array<string, array{string, bool}> */
    public static function unselectableCloudflareZones(): array
    {
        return [
            'pending activation' => ['pending', false],
            'inaccessible' => ['inaccessible', false],
            'paused' => ['active', true],
        ];
    }

    #[DataProvider('hostnamesOutsideZone')]
    public function test_cloudflare_registration_rejects_hostnames_outside_the_selected_zone(string $hostname): void
    {
        [$integrationId, $zoneId] = $this->cloudflareTree();
        $this->expectZones($integrationId, [ZoneData::fromModel(CloudflareZone::findOrFail($zoneId))]);
        $this->cloudflare->shouldNotReceive('attachDomain');

        try {
            $this->manager->createCloudflare($this->landing('Outside zone'), $hostname, $integrationId, $zoneId);
            $this->fail('A hostname outside the selected zone was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('hostname', $exception->errors());
            $this->assertDatabaseCount('domains', 0);
        }
    }

    /** @return array<string, array{string}> */
    public static function hostnamesOutsideZone(): array
    {
        return [
            'different zone' => ['offer.other.com'],
            'misleading suffix' => ['notexample.com'],
        ];
    }

    public function test_remove_promotes_another_domain_without_touching_dns_provider(): void
    {
        $landing = $this->landing('Removal');
        $primary = $this->manager->createManual($landing, 'one.example.com');
        $replacement = $this->manager->createManual($landing, 'two.example.com');

        $this->manager->remove($primary);

        $this->assertDatabaseMissing('domains', ['id' => $primary->getKey()]);
        $this->assertTrue($replacement->refresh()->is_primary);
        $this->cloudflare->shouldNotHaveReceived('removeDomain');
    }

    public function test_remove_keeps_cloudflare_managed_records_unless_cleanup_is_requested(): void
    {
        $domain = $this->cloudflareDomain('keep.example.com');

        $this->cloudflare->shouldReceive('removeDomain')
            ->once()
            ->withArgs(fn (Installation $owner, string $id, bool $cleanup): bool => $owner->is($this->installation)
                && $id === $domain->cloudflare_domain_id
                && ! $cleanup);

        $this->manager->remove($domain);

        $this->assertDatabaseMissing('domains', ['id' => $domain->getKey()]);
    }

    public function test_remove_delegates_safe_managed_record_cleanup_for_cloudflare_when_explicitly_requested(): void
    {
        $domain = $this->cloudflareDomain('remove.example.com');

        $this->cloudflare->shouldReceive('removeDomain')
            ->once()
            ->withArgs(fn (Installation $owner, string $id, bool $cleanup): bool => $owner->is($this->installation)
                && $id === $domain->cloudflare_domain_id
                && $cleanup);

        $this->manager->remove($domain, cleanupManagedRecords: true);

        $this->assertDatabaseMissing('domains', ['id' => $domain->getKey()]);
    }

    private function cloudflareDomain(string $hostname): Domain
    {
        [, $zoneId] = $this->cloudflareTree();
        $remote = CloudflareDomain::query()->create([
            'zone_id' => $zoneId,
            'hostname' => $hostname,
            'kind' => 'exact',
            'status' => CloudflareDomainStatus::Active,
        ]);

        return Domain::query()->create([
            'landing_id' => $this->landing('Cloudflare removal')->getKey(),
            'hostname' => $hostname,
            'kind' => DomainKind::Custom,
            'provider' => DomainProvider::Cloudflare,
            'status' => DomainStatus::Active,
            'is_primary' => true,
            'dns_target' => 'origin.landings.test',
            'cloudflare_domain_id' => $remote->getKey(),
        ]);
    }

    public function test_partial_cloudflare_cleanup_failure_keeps_the_binding_retryable_without_claiming_active_dns(): void
    {
        [, $zoneId] = $this->cloudflareTree();
        $remote = CloudflareDomain::query()->create([
            'zone_id' => $zoneId, 'hostname' => 'example.com', 'kind' => 'exact', 'status' => CloudflareDomainStatus::Active,
        ]);
        $landing = $this->publishedLanding('Cleanup retry');
        $domain = Domain::query()->create([
            'landing_id' => $landing->id, 'hostname' => 'example.com', 'dns_scope' => 'wildcard',
            'kind' => DomainKind::Custom, 'provider' => DomainProvider::Cloudflare,
            'status' => DomainStatus::Active, 'verified_at' => now(), 'is_primary' => true, 'dns_target' => 'origin.landings.test',
            'cloudflare_domain_id' => $remote->id,
        ]);
        $replacement = $this->manager->createManual($landing, 'backup.other.test');
        $attempts = 0;
        $this->cloudflare->shouldReceive('removeDomain')->twice()->andReturnUsing(function () use (&$attempts, $remote): void {
            if (++$attempts === 1) {
                throw new CloudflareTransportException('Private transport details after one DNS deletion.');
            }
            $remote->delete();
        });

        try {
            $this->manager->remove($domain, cleanupManagedRecords: true);
            $this->fail('A partial remote cleanup failure was swallowed.');
        } catch (CloudflareTransportException) {
            $this->assertSame(DomainStatus::Unreachable, $domain->refresh()->status);
            $this->assertFalse($this->isServed($domain));
            $this->assertSame($remote->id, $domain->cloudflare_domain_id);
            $this->assertTrue($domain->is_primary);
            $this->assertFalse($replacement->refresh()->is_primary);
            $this->assertStringNotContainsString('Private', $domain->last_error);
            $this->assertDatabaseHas('cloudflare_domains', ['id' => $remote->id]);
        }

        $this->manager->remove($domain, cleanupManagedRecords: true);
        $this->assertDatabaseMissing('domains', ['id' => $domain->id]);
        $this->assertTrue($replacement->refresh()->is_primary);
    }

    private function landing(string $name): Landing
    {
        return Landing::query()->create([
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'description' => null,
            'is_active' => true,
        ]);
    }

    /** A published landing with an active release, i.e. one that can be served. */
    private function publishedLanding(string $name): Landing
    {
        $landing = $this->landing($name);
        LandingRelease::query()->create([
            'landing_id' => $landing->id,
            'original_name' => 'landing.zip',
            'storage_path' => $landing->id.'/releases/'.Str::ulid(),
            'entrypoint' => 'index.html',
            'size_bytes' => 1,
            'file_count' => 1,
            'checksum' => str_repeat('a', 64),
            'is_active' => true,
            'activated_at' => now(),
        ]);

        return $landing;
    }

    private function isServed(Domain $domain): bool
    {
        return Domain::query()->servable()->whereKey($domain->getKey())->exists();
    }

    public function test_a_transient_failure_cannot_reopen_a_domain_after_proven_drift(): void
    {
        $domain = $this->manager->createManual($this->publishedLanding('Drift history'), 'offer.example.com');
        $domain->transitionTo(DomainStatus::Active);
        $this->assertTrue($this->isServed($domain));
        $domain->transitionTo(DomainStatus::Drifted);
        $this->assertFalse($this->isServed($domain));
        foreach ([DomainStatus::Unreachable, DomainStatus::Error, DomainStatus::PendingPropagation] as $status) {
            $domain->transitionTo($status);
            $this->assertFalse($this->isServed($domain));
        }
        $domain->transitionTo(DomainStatus::Active);
        $this->assertTrue($this->isServed($domain));
    }

    /** @return array{string, string} */
    private function cloudflareTree(): array
    {
        $token = Str::random(32);
        $integration = $this->installation->cloudflareIntegrations()->create([
            'label' => 'Installation DNS',
            'api_token' => $token,
            'token_fingerprint' => hash('sha256', $token),
            'token_status' => 'active',
            'status' => IntegrationStatus::Active,
        ]);
        $account = $integration->accounts()->create([
            'cloudflare_id' => str_repeat('a', 32),
            'name' => 'Account',
            'status' => 'active',
        ]);
        $zone = $account->zones()->create([
            'cloudflare_id' => str_repeat('z', 32),
            'name' => 'example.com',
            'status' => 'active',
        ]);

        return [(string) $integration->getKey(), (string) $zone->getKey()];
    }

    /** @param list<ZoneData> $zones */
    private function expectZones(string $integrationId, array $zones): void
    {
        $this->cloudflare->shouldReceive('zones')->once()
            ->withArgs(fn (Installation $owner, string $id): bool => $owner->is($this->installation) && $id === $integrationId)
            ->andReturn($zones);
    }
}
