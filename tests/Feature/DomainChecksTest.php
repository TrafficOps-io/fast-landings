<?php

namespace Tests\Feature;

use App\Enums\DomainKind;
use App\Enums\DomainProvider;
use App\Enums\DomainStatus;
use App\Jobs\CheckDomain;
use App\Jobs\ProvisionCloudflareDomain;
use App\Models\Domain;
use App\Models\Installation;
use App\Models\Landing;
use App\Services\DomainChecks;
use App\Services\DomainManager;
use App\Support\DomainError;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;
use TrafficOps\Cloudflare\Contracts\CloudflareManagerContract;
use TrafficOps\Cloudflare\Contracts\PublicDnsResolverContract;
use TrafficOps\Cloudflare\Dns\PublicDnsRecordChecker;
use TrafficOps\Cloudflare\DTO\CheckResult;
use TrafficOps\Cloudflare\DTO\DnsLookupResult;
use TrafficOps\Cloudflare\DTO\DnsRecordExpectation;
use TrafficOps\Cloudflare\DTO\DomainData;
use TrafficOps\Cloudflare\DTO\ProvisionResult;
use TrafficOps\Cloudflare\Enums\DomainStatus as CloudflareDomainStatus;
use TrafficOps\Cloudflare\Enums\IntegrationStatus;
use TrafficOps\Cloudflare\Exceptions\CloudflarePermissionException;
use TrafficOps\Cloudflare\Exceptions\CloudflareRateLimitException;
use TrafficOps\Cloudflare\Models\CloudflareDomain;
use TrafficOps\Cloudflare\Support\Hostname;

class DomainChecksTest extends TestCase
{
    use DatabaseMigrations;

    private CloudflareManagerContract&MockInterface $cloudflare;

    private object $resolver;

    private DomainManager $manager;

    private DomainChecks $checks;

    private Installation $installation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->startOfSecond());
        $this->cloudflare = Mockery::mock(CloudflareManagerContract::class);
        $this->resolver = new class implements PublicDnsResolverContract
        {
            public array $answers = [];

            public bool $fails = false;

            public int $calls = 0;

            public array $queries = [];

            public function resolve(string $name, string $type): DnsLookupResult
            {
                $this->calls++;
                $this->queries[] = [$name, $type];
                if ($this->fails) {
                    throw new RuntimeException('DNS resolver unavailable.');
                }

                return new DnsLookupResult($name, $type, $this->answers[$name.':'.$type] ?? []);
            }
        };
        $this->manager = new DomainManager($this->cloudflare, new PublicDnsRecordChecker($this->resolver), app(CacheFactory::class));
        $this->app->instance(DomainManager::class, $this->manager);
        $this->checks = app(DomainChecks::class);
        $this->installation = Installation::query()->create([
            'name' => 'Fast Landings',
            'domain' => 'landings.test',
            'origin_target' => 'origin.landings.test',
        ]);
    }

    public function test_requests_queue_without_resolving_dns_and_deduplicate_repeated_clicks(): void
    {
        Queue::fake();
        $domain = $this->manual();

        $this->checks->requestCheck($domain);
        $this->checks->requestCheck($domain);

        Queue::assertPushed(CheckDomain::class, 1);
        Queue::assertPushed(CheckDomain::class, fn (CheckDomain $job): bool => $job->domainId === $domain->id
            && $job->connection === 'database' && $job->afterCommit === true);
        $this->assertSame(0, $this->resolver->calls);
        $this->assertNotNull($domain->refresh()->verification_requested_at);
        $this->assertTrue($domain->next_check_at->equalTo(now()->addMinutes(10)));
    }

    public function test_database_job_is_not_visible_until_transaction_commit(): void
    {
        $domain = $this->manual();
        DB::beginTransaction();
        $this->checks->requestCheck($domain);
        $this->assertDatabaseCount('jobs', 0);
        DB::commit();

        $this->assertDatabaseCount('jobs', 1);
        $this->assertSame(0, $this->resolver->calls);
    }

    public function test_rollback_does_not_enqueue_a_check_or_leave_a_request_marker(): void
    {
        $domain = $this->manual();
        DB::beginTransaction();
        $this->checks->requestCheck($domain);
        DB::rollBack();

        $this->assertDatabaseCount('jobs', 0);
        $this->assertNull($domain->refresh()->verification_requested_at);
    }

    public function test_database_worker_executes_the_dns_check(): void
    {
        $domain = $this->manual();
        $this->resolver->answers[$domain->hostname.':CNAME'] = ['origin.landings.test'];
        $this->checks->requestCheck($domain);
        $this->assertSame(DomainStatus::Pending, $domain->refresh()->status);

        $this->artisan('queue:work', ['connection' => 'database', '--once' => true])->assertSuccessful();

        $this->assertSame(DomainStatus::Active, $domain->refresh()->status);
        $this->assertNull($domain->verification_requested_at);
        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_pending_dns_is_rechecked_then_becomes_active_and_detects_drift(): void
    {
        Queue::fake();
        $domain = $this->manual();
        $this->runCheck($domain);
        $this->assertSame(DomainStatus::PendingPropagation, $domain->refresh()->status);
        $this->assertTrue($domain->next_check_at->equalTo(now()->addMinute()));

        $this->resolver->answers[$domain->hostname.':CNAME'] = ['origin.landings.test'];
        $this->travel(1)->minutes();
        $this->runCheck($domain);
        $this->assertSame(DomainStatus::Active, $domain->refresh()->status);
        $this->assertTrue($domain->next_check_at->equalTo(now()->addMinutes(10)));
        $this->assertNull($domain->last_error);

        $this->resolver->answers[$domain->hostname.':CNAME'] = ['other.example.com'];
        $this->travel(10)->minutes();
        $this->runCheck($domain);
        $this->assertSame(DomainStatus::Drifted, $domain->refresh()->status);
        $this->assertTrue($domain->next_check_at->equalTo(now()->addMinute()));
    }

    #[DataProvider('addressTargets')]
    public function test_database_worker_polls_address_records_until_they_match(string $target, string $type, string $canonical, string $answer): void
    {
        $domain = $this->manager->createManual($this->landing(), 'address.example.net', dnsTarget: $target);
        $this->assertSame($canonical, $domain->dns_target);
        $this->checks->requestCheck($domain);
        $this->assertSame(0, $this->resolver->calls);
        $this->assertSame(DomainStatus::Pending, $domain->refresh()->status);

        $this->artisan('queue:work', ['connection' => 'database', '--once' => true])->assertSuccessful();

        $this->assertSame(DomainStatus::PendingPropagation, $domain->refresh()->status);
        $this->assertSame([[$domain->hostname, $type]], $this->resolver->queries);
        $this->assertTrue($domain->next_check_at->equalTo(now()->addMinute()));

        $this->resolver->answers[$domain->hostname.':'.$type] = [$answer];
        $this->travel(1)->minutes();
        $this->artisan('fast-landings:check-domains')->assertSuccessful();
        $this->artisan('queue:work', ['connection' => 'database', '--once' => true])->assertSuccessful();

        $this->assertSame(DomainStatus::Active, $domain->refresh()->status);
        $this->assertSame([[$domain->hostname, $type], [$domain->hostname, $type]], $this->resolver->queries);
        $this->assertNull($domain->last_error);
        $this->assertNull($domain->verification_requested_at);
        $this->assertTrue($domain->next_check_at->equalTo(now()->addMinutes(10)));
        $this->assertDatabaseCount('jobs', 0);
    }

    public static function addressTargets(): array
    {
        return [
            'IPv4 A' => ['1.1.1.1', 'A', '1.1.1.1', '1.1.1.1'],
            'equivalent IPv6 AAAA' => [
                '2606:4700:4700:0000:0000:0000:0000:1111',
                'AAAA',
                '2606:4700:4700::1111',
                '2606:4700:4700:0:0:0:0:1111',
            ],
        ];
    }

    public function test_dns_errors_are_retried_with_a_bound_then_clear_the_request(): void
    {
        Queue::fake();
        $domain = $this->manual();
        $this->resolver->fails = true;
        $job = $this->requestedCheck($domain);
        $this->assertSame(3, $job->tries);
        $this->assertSame([15, 60, 120], $job->backoff());
        $this->assertLessThan(config('queue.connections.database.retry_after'), $job->timeout);

        try {
            $job->handle($this->manager, $this->checks);
            $this->fail('Resolver exceptions must reach the worker for retry.');
        } catch (RuntimeException $exception) {
            $this->assertSame(DomainStatus::Unreachable, $domain->refresh()->status);
            $this->assertNotNull($domain->verification_requested_at);
            $job->failed($exception);
        }

        $this->assertSame(DomainStatus::Error, $domain->refresh()->status);
        $this->assertNull($domain->verification_requested_at);
        $this->assertTrue($domain->next_check_at->equalTo(now()->addMinutes(5)));
    }

    public function test_provisioning_queues_verification_only_after_the_remote_write(): void
    {
        Queue::fake();
        [$domain] = $this->cloudflareDomains();
        $manager = Mockery::mock(DomainManager::class);
        $manager->shouldReceive('reconcileCloudflare')->once()->withArgs(fn (Domain $actual) => $actual->is($domain))
            ->andReturn(new ProvisionResult($domain->cloudflare_domain_id, 1, 0, 0, 0));
        $manager->shouldNotReceive('checkCloudflare');

        $this->checks->requestProvision($domain);
        Queue::assertNotPushed(CheckDomain::class);
        $job = Queue::pushed(ProvisionCloudflareDomain::class)->sole();
        $job->handle($manager, $this->checks);

        Queue::assertPushed(CheckDomain::class, 1);
        $this->assertNotSame($job->requestId, $domain->refresh()->verification_request_id);
    }

    public function test_cloudflare_checks_update_siblings_and_their_next_poll_time(): void
    {
        Queue::fake();
        [$first, $second, $integrationId] = $this->cloudflareDomains();
        $this->cloudflare->shouldReceive('checkIntegration')->once()->withArgs(fn ($owner, $id) => $id === $integrationId)
            ->andReturn(new CheckResult($integrationId, IntegrationStatus::Active, [
                new DomainData($first->cloudflare_domain_id, $first->hostname, 'exact', CloudflareDomainStatus::Active, now()),
                new DomainData($second->cloudflare_domain_id, $second->hostname, 'exact', CloudflareDomainStatus::PendingPropagation, now()),
            ]));

        $this->runCheck($first);

        $this->assertSame(DomainStatus::Active, $first->refresh()->status);
        $this->assertTrue($first->next_check_at->equalTo(now()->addMinutes(10)));
        $this->assertSame(DomainStatus::PendingPropagation, $second->refresh()->status);
        $this->assertTrue($second->next_check_at->equalTo(now()->addMinute()));
    }

    public function test_scheduler_preserves_provisioning_when_a_worker_misses_its_lease(): void
    {
        Queue::fake();
        [$domain] = $this->cloudflareDomains();
        $this->checks->requestProvision($domain);
        $original = Queue::pushed(ProvisionCloudflareDomain::class)->sole();
        $this->travel(11)->minutes();

        $this->artisan('fast-landings:check-domains')->assertSuccessful();

        Queue::assertPushed(ProvisionCloudflareDomain::class, 2);
        Queue::assertNotPushed(CheckDomain::class);
        $replacement = Queue::pushed(ProvisionCloudflareDomain::class)->last();
        $manager = Mockery::mock(DomainManager::class);
        $manager->shouldReceive('reconcileCloudflare')->once()
            ->andReturn(new ProvisionResult($domain->cloudflare_domain_id, 1, 0, 0, 0));
        $original->handle($manager, $this->checks);
        $replacement->handle($manager, $this->checks);

        Queue::assertPushed(CheckDomain::class, 1);
        $this->assertSame('check', $domain->refresh()->verification_operation);
    }

    public function test_deleted_and_superseded_jobs_do_not_call_providers_or_clear_new_requests(): void
    {
        Queue::fake();
        $domain = $this->manual();
        $stale = $this->requestedCheck($domain);
        $this->travel(11)->minutes();
        $current = $this->requestedCheck($domain);
        $stale->handle($this->manager, $this->checks);
        $stale->failed(new RuntimeException('Old worker failure.'));
        $this->assertSame($current->requestId, $domain->refresh()->verification_request_id);
        $this->assertSame(0, $this->resolver->calls);

        $domain->delete();
        $current->handle($this->manager, $this->checks);
        $current->failed(new RuntimeException('Deleted domain.'));
        $this->assertSame(0, $this->resolver->calls);
    }

    public function test_sibling_checks_cannot_starve_failed_cloudflare_provisioning(): void
    {
        Queue::fake();
        [$first, $second, $integrationId] = $this->cloudflareDomains();
        $this->checks->requestProvision($second);
        Queue::pushed(ProvisionCloudflareDomain::class)->sole()->failed(new RuntimeException('Transient provider failure.'));
        $provisionDue = $second->refresh()->next_check_at;
        $this->travel(5)->minutes();
        $this->cloudflare->shouldReceive('checkIntegration')->twice()->withArgs(fn ($owner, $id) => $id === $integrationId)
            ->andReturn(new CheckResult($integrationId, IntegrationStatus::Active, [
                new DomainData($first->cloudflare_domain_id, $first->hostname, 'exact', CloudflareDomainStatus::PendingPropagation, now()),
                new DomainData($second->cloudflare_domain_id, $second->hostname, 'exact', CloudflareDomainStatus::PendingPropagation, now()),
            ]));

        $this->runCheck($first);
        $this->assertTrue($second->refresh()->next_check_at->equalTo($provisionDue));
        $this->assertSame('provision', $second->verification_operation);
        $this->assertNull($second->verification_request_id);

        Queue::fake();
        $this->travel(1)->minutes();
        $this->artisan('fast-landings:check-domains')->assertSuccessful();
        Queue::assertPushed(CheckDomain::class, 1);
        Queue::assertPushed(ProvisionCloudflareDomain::class, fn ($job) => $job->domainId === $second->id);
        $requestId = $second->refresh()->verification_request_id;
        $queuedUntil = $second->next_check_at;

        Queue::pushed(CheckDomain::class)->sole()->handle($this->manager, $this->checks);
        $this->assertSame($requestId, $second->refresh()->verification_request_id);
        $this->assertTrue($second->next_check_at->equalTo($queuedUntil));
        $this->assertSame('provision', $second->verification_operation);
    }

    public function test_scheduler_queues_only_due_domains_and_deduplicates_cloudflare_integrations(): void
    {
        Queue::fake();
        $due = $this->manual('due.example.net');
        $future = $this->manual('future.example.net');
        $future->update(['next_check_at' => now()->addMinute()]);
        $activeRecent = $this->manual('recent.example.net');
        $activeRecent->update(['status' => DomainStatus::Active, 'last_checked_at' => now()->subMinutes(9)]);
        $activeDue = $this->manual('old.example.net');
        $activeDue->update(['status' => DomainStatus::Active, 'last_checked_at' => now()->subMinutes(10)]);
        $system = $this->manager->createSystem($due->landing, 'system');
        [$first, $second] = $this->cloudflareDomains();

        $this->artisan('fast-landings:check-domains')->assertSuccessful();
        $this->artisan('fast-landings:check-domains')->assertSuccessful();

        Queue::assertPushed(CheckDomain::class, 3);
        Queue::assertPushed(CheckDomain::class, fn ($job) => $job->domainId === $due->id);
        Queue::assertPushed(CheckDomain::class, fn ($job) => $job->domainId === $activeDue->id);
        Queue::assertPushed(CheckDomain::class, fn ($job) => in_array($job->domainId, [$first->id, $second->id], true));
        Queue::assertNotPushed(CheckDomain::class, fn ($job) => in_array($job->domainId, [$future->id, $activeRecent->id, $system->id], true));
        $this->assertSame(0, $this->resolver->calls);
    }

    public function test_system_domains_never_enqueue_verification(): void
    {
        Queue::fake();
        $domain = $this->manager->createSystem($this->landing(), 'system');
        $this->checks->requestCheck($domain);
        Queue::assertNothingPushed();
    }

    public function test_wildcard_parent_success_queues_individual_child_dns_checks(): void
    {
        Queue::fake();
        $base = $this->manager->createManual(null, 'example.com', wildcard: true);
        $child = $this->manager->createSubdomain($base, $this->landing(), 'offer');
        $probe = Hostname::wildcardProbe('*.example.com', $base->id);
        $this->resolver->answers['example.com:CNAME'] = ['origin.landings.test'];
        $this->resolver->answers[$probe.':CNAME'] = ['origin.landings.test'];
        $this->resolver->answers['offer.example.com:CNAME'] = ['origin.landings.test'];

        $this->runCheck($base);
        $this->assertSame(DomainStatus::Active, $base->refresh()->status);
        $this->assertSame(DomainStatus::Pending, $child->refresh()->status);
        $this->assertTrue($child->next_check_at->lessThanOrEqualTo(now()));

        Queue::fake();
        $this->artisan('fast-landings:check-domains')->assertSuccessful();
        Queue::assertPushed(CheckDomain::class, 1);
        $job = Queue::pushed(CheckDomain::class)->sole();
        $this->assertSame($child->id, $job->domainId);
        $job->handle($this->manager, $this->checks);
        $this->assertSame(DomainStatus::Active, $child->refresh()->status);
        $this->assertNull($child->verification_request_id);
        $this->assertContains(['offer.example.com', 'CNAME'], $this->resolver->queries);
    }

    public function test_cloudflare_child_checks_do_not_queue_remote_writes_and_provisioning_targets_the_parent(): void
    {
        Queue::fake();
        [$base] = $this->cloudflareDomains();
        $base->update(['dns_scope' => 'wildcard', 'status' => DomainStatus::Active]);
        $expectation = new DnsRecordExpectation('CNAME', '*.'.$base->hostname, $base->dns_target, proxied: false);
        $base->cloudflareDomain->records()->create([
            ...$expectation->toArray(), 'signature' => $expectation->signature(),
        ]);
        $child = $this->manager->createSubdomain($base, $this->landing(), 'offer');
        $this->resolver->answers[$child->hostname.':CNAME'] = [$base->dns_target];
        $this->cloudflare->shouldNotReceive('checkIntegration', 'reconcileDomain', 'attachDomain');

        $this->runCheck($child);
        $this->assertSame(DomainStatus::Active, $child->refresh()->status);
        Queue::assertNotPushed(ProvisionCloudflareDomain::class);

        Queue::fake();
        $this->checks->requestProvision($child);
        Queue::assertPushed(ProvisionCloudflareDomain::class, 1);
        Queue::assertPushed(ProvisionCloudflareDomain::class, fn ($job) => $job->domainId === $base->id);
        $this->assertNull($child->refresh()->verification_request_id);
    }

    public function test_failed_wildcard_worker_invalidates_children_but_stale_failure_does_not(): void
    {
        Queue::fake();
        $base = $this->manager->createManual(null, 'example.com', wildcard: true);
        $child = $this->manager->createSubdomain($base, null, 'offer');
        $child->update(['status' => DomainStatus::Active]);
        $job = $this->requestedCheck($base);

        $this->checks->failed($base->id, 'stale-request');
        $this->assertSame(DomainStatus::Active, $child->refresh()->status);

        $job->failed(new RuntimeException('Private worker failure.'));
        $this->assertSame(DomainStatus::Error, $base->refresh()->status);
        $this->assertSame(DomainStatus::Error, $child->refresh()->status);
        $this->assertStringContainsString('Verify wildcard DNS', $child->last_error);
        $this->assertStringNotContainsString('Private', $child->last_error);
    }

    public function test_final_job_failure_preserves_sanitized_provider_guidance_from_the_current_request(): void
    {
        Queue::fake();
        [$domain] = $this->cloudflareDomains();
        $exception = new CloudflarePermissionException('Private token secret-value and provider payload.');
        $this->cloudflare->shouldReceive('checkIntegration')->once()->andThrow($exception);
        $job = $this->requestedCheck($domain);

        try {
            $job->handle($this->manager, $this->checks);
            $this->fail('A provider permission failure was swallowed.');
        } catch (CloudflarePermissionException) {
            $this->assertSame(DomainError::message($exception), $domain->refresh()->last_error);
        }
        $job->failed(new RuntimeException('Generic final queue failure.'));

        $this->assertSame(DomainError::message($exception), $domain->refresh()->last_error);
        $this->assertStringContainsString('Zone → DNS → Edit', $domain->last_error);
        $this->assertStringNotContainsString('secret-value', $domain->last_error);
        $this->assertNull($domain->verification_request_id);
    }

    public function test_final_provision_failure_uses_current_exception_instead_of_historical_diagnostics(): void
    {
        Queue::fake();
        [$domain] = $this->cloudflareDomains();
        $domain->update([
            'last_error' => DomainError::message(new CloudflarePermissionException('Old token permissions.')),
            'last_checked_at' => now()->subHour(),
        ]);
        $this->checks->requestProvision($domain);
        $exception = new CloudflareRateLimitException('Private retry payload.', retryAfter: 45);

        Queue::pushed(ProvisionCloudflareDomain::class)->sole()->failed($exception);

        $this->assertSame(DomainError::message($exception), $domain->refresh()->last_error);
        $this->assertStringContainsString('45 seconds', $domain->last_error);
        $this->assertStringNotContainsString('Private', $domain->last_error);
    }

    private function runCheck(Domain $domain): void
    {
        $this->requestedCheck($domain)->handle($this->manager, $this->checks);
    }

    private function requestedCheck(Domain $domain): CheckDomain
    {
        $this->checks->requestCheck($domain);

        return new CheckDomain($domain->id, $domain->refresh()->verification_request_id);
    }

    private function manual(string $hostname = 'offer.example.net'): Domain
    {
        return $this->manager->createManual($this->landing(), $hostname);
    }

    private function landing(): Landing
    {
        return Landing::query()->create(['name' => 'Landing', 'slug' => (string) Str::ulid()]);
    }

    private function cloudflareDomains(): array
    {
        $integration = $this->installation->cloudflareIntegrations()->create([
            'label' => 'Cloudflare', 'api_token' => 'secret', 'token_fingerprint' => hash('sha256', 'secret'),
            'token_status' => 'active', 'status' => IntegrationStatus::Active,
        ]);
        $account = $integration->accounts()->create(['cloudflare_id' => str_repeat('a', 32), 'name' => 'Account', 'status' => 'active']);
        $zone = $account->zones()->create(['cloudflare_id' => str_repeat('b', 32), 'name' => 'example.com', 'status' => 'active']);
        $domains = [];
        foreach (['first', 'second'] as $name) {
            $remote = CloudflareDomain::query()->create([
                'zone_id' => $zone->id, 'hostname' => $name.'.example.com', 'kind' => 'exact', 'status' => CloudflareDomainStatus::Pending,
            ]);
            $domains[] = Domain::query()->create([
                'landing_id' => $this->landing()->id, 'hostname' => $remote->hostname,
                'kind' => DomainKind::Custom, 'provider' => DomainProvider::Cloudflare, 'status' => DomainStatus::Pending,
                'dns_target' => 'origin.landings.test', 'cloudflare_domain_id' => $remote->id,
            ]);
        }

        return [...$domains, $integration->id];
    }
}
