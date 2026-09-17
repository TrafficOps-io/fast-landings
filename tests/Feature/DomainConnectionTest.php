<?php

namespace Tests\Feature;

use App\Enums\DomainStatus;
use App\Enums\UserRole;
use App\Jobs\CheckDomain;
use App\Jobs\ProvisionCloudflareDomain;
use App\Livewire\Domains\Index;
use App\Models\Domain;
use App\Models\Installation;
use App\Models\Landing;
use App\Models\User;
use App\Services\DomainManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use TrafficOps\Cloudflare\Contracts\CloudflareManagerContract;
use TrafficOps\Cloudflare\Contracts\PublicDnsResolverContract;
use TrafficOps\Cloudflare\DTO\DomainData;
use TrafficOps\Cloudflare\DTO\DomainDefinition;
use TrafficOps\Cloudflare\DTO\IntegrationData;
use TrafficOps\Cloudflare\DTO\ZoneData;
use TrafficOps\Cloudflare\Enums\DomainStatus as CloudflareDomainStatus;
use TrafficOps\Cloudflare\Enums\IntegrationStatus;
use TrafficOps\Cloudflare\Models\CloudflareDomain;
use TrafficOps\Cloudflare\Models\CloudflareIntegration;
use TrafficOps\Cloudflare\Models\CloudflareZone;

class DomainConnectionTest extends TestCase
{
    use RefreshDatabase;

    private CloudflareManagerContract&MockInterface $cloudflare;

    private Installation $installation;

    private Landing $landing;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->actingAs(User::factory()->create(['role' => UserRole::Administrator]));
        $this->installation = Installation::query()->create([
            'name' => 'Test installation',
            'domain' => 'fast-landings.test',
            'origin_target' => 'origin.fast-landings.test',
        ]);
        $this->landing = $this->landing('First landing');
        $this->cloudflare = Mockery::mock(CloudflareManagerContract::class);
        $this->app->instance(CloudflareManagerContract::class, $this->cloudflare);
        $this->cloudflare->shouldReceive('zones')
            ->withArgs(fn (Installation $owner, string $integrationId): bool => $owner->is($this->installation)
                && $owner->cloudflareIntegrations()->whereKey($integrationId)->exists())
            ->andReturnUsing(fn (Installation $owner, string $integrationId): array => CloudflareZone::query()
                ->whereHas('account', fn ($query) => $query->where('integration_id', $integrationId))
                ->orderBy('name')->get()->map(ZoneData::fromModel(...))->all());
        $this->cloudflare->shouldNotReceive('reconcileDomain', 'checkIntegration');

        $resolver = Mockery::mock(PublicDnsResolverContract::class);
        $resolver->shouldNotReceive('resolve');
        $this->app->instance(PublicDnsResolverContract::class, $resolver);
    }

    public function test_selecting_a_connection_uses_cached_zones_and_selecting_a_zone_fills_the_hostname(): void
    {
        [$integration, $zone] = $this->cloudflareAccount('First account', 'example.com');
        [, $otherZone] = $this->cloudflareAccount('Second account', 'other.com');
        $zone->account->zones()->create([
            'cloudflare_id' => Str::random(32), 'name' => 'paused.com', 'status' => 'active', 'paused' => true,
        ]);
        $zone->account->zones()->create([
            'cloudflare_id' => Str::random(32), 'name' => 'pending.com', 'status' => 'pending',
        ]);

        Livewire::test(Index::class)
            ->set('showCreate', true)
            ->set('mode', 'cloudflare')
            ->set('integrationId', $integration->id)
            ->assertViewHas('zones', fn (Collection $zones): bool => $zones->pluck('id')->all() === [$zone->id])
            ->assertDontSee($otherZone->name)
            ->set('zoneId', $zone->id)
            ->assertSet('hostname', 'example.com')
            ->assertHasNoErrors();
    }

    public function test_changing_account_discards_the_previous_zone_and_hostname(): void
    {
        [$first, $firstZone] = $this->cloudflareAccount('First account', 'example.com');
        [$second, $secondZone] = $this->cloudflareAccount('Second account', 'other.com');

        Livewire::test(Index::class)
            ->set('mode', 'cloudflare')
            ->set('integrationId', $first->id)
            ->set('zoneId', $firstZone->id)
            ->set('hostname', 'offer.example.com')
            ->set('integrationId', $second->id)
            ->assertSet('zoneId', '')
            ->assertSet('hostname', '')
            ->assertViewHas('zones', fn (Collection $zones): bool => $zones->pluck('id')->all() === [$secondZone->id]);
    }

    public function test_refetch_discards_a_selected_zone_that_is_no_longer_accessible(): void
    {
        [$integration, $zone] = $this->cloudflareAccount('Account', 'example.com');
        $component = Livewire::test(Index::class)
            ->set('mode', 'cloudflare')
            ->set('integrationId', $integration->id)
            ->set('zoneId', $zone->id);

        $this->cloudflare->shouldReceive('sync')->once()
            ->withArgs(fn (Installation $owner, string $id): bool => $owner->is($this->installation) && $id === $integration->id)
            ->andReturnUsing(function () use ($integration, $zone): IntegrationData {
                $zone->update(['status' => 'inaccessible']);

                return IntegrationData::fromModel($integration);
            });

        $component->call('refetchCloudflareZones')
            ->assertSet('zoneId', '')
            ->assertSet('hostname', '')
            ->assertViewHas('zones', fn (Collection $zones): bool => $zones->isEmpty())
            ->assertHasNoErrors();
    }

    #[DataProvider('cloudflareHostnames')]
    public function test_connecting_a_selected_zone_queues_dns_setup_for_the_apex_or_custom_subdomain(string $input, string $expected, string $target, string $type): void
    {
        $this->installation->update(['origin_target' => $target]);
        [$integration, $zone] = $this->cloudflareAccount('Account', 'example.com');
        $this->cloudflare->shouldReceive('attachDomain')->once()
            ->withArgs(fn (Installation $owner, string $integrationId, string $zoneId, DomainDefinition $definition): bool => $owner->is($this->installation)
                && $integrationId === $integration->id && $zoneId === $zone->id
                && $definition->hostname === $expected && $definition->records[0]->proxied === false
                && $definition->records[0]->type === $type && $definition->records[0]->content === $target)
            ->andReturnUsing(function ($owner, $integrationId, $zoneId, DomainDefinition $definition): DomainData {
                $remote = CloudflareDomain::query()->create([
                    'zone_id' => $zoneId, 'hostname' => $definition->hostname,
                    'kind' => 'exact', 'status' => CloudflareDomainStatus::Pending,
                ]);

                return new DomainData($remote->id, $remote->hostname, 'exact', CloudflareDomainStatus::Pending, null);
            });

        Livewire::test(Index::class)
            ->set('showCreate', true)
            ->set('mode', 'cloudflare')
            ->set('landingId', $this->landing->id)
            ->set('integrationId', $integration->id)
            ->set('zoneId', $zone->id)
            ->set('hostname', $input)
            ->call('create')
            ->assertHasNoErrors()
            ->assertSet('showCreate', false)
            ->assertSee('Cloudflare DNS setup will continue in the background.');

        $domain = Domain::query()->sole();
        $this->assertSame($expected, $domain->hostname);
        $this->assertSame($this->landing->id, $domain->landing_id);
        $this->assertSame(DomainStatus::Pending, $domain->status);
        $this->assertNotNull($domain->verification_requested_at);
        Queue::assertPushed(ProvisionCloudflareDomain::class, fn ($job): bool => $job->domainId === $domain->id
            && $job->requestId === $domain->verification_request_id && $job->connection === 'database');
        Queue::assertPushed(ProvisionCloudflareDomain::class, 1);
        Queue::assertNotPushed(CheckDomain::class);
    }

    /** @return array<string, array{string, string, string, string}> */
    public static function cloudflareHostnames(): array
    {
        return [
            'CNAME selected apex' => ['', 'example.com', 'origin.fast-landings.test', 'CNAME'],
            'CNAME custom subdomain' => ['offer.example.com', 'offer.example.com', 'origin.fast-landings.test', 'CNAME'],
            'IPv4 selected apex' => ['', 'example.com', '203.0.113.10', 'A'],
            'IPv4 custom subdomain' => ['offer.example.com', 'offer.example.com', '203.0.113.10', 'A'],
            'IPv6 selected apex' => ['', 'example.com', '2001:db8::10', 'AAAA'],
            'IPv6 custom subdomain' => ['offer.example.com', 'offer.example.com', '2001:db8::10', 'AAAA'],
        ];
    }

    public function test_tampering_with_the_account_id_is_rejected_without_creating_or_queuing_a_domain(): void
    {
        [$integration, $zone] = $this->cloudflareAccount('Foreign account', 'example.com');
        $integration->update(['owner_id' => (string) Str::ulid()]);
        $this->cloudflare->shouldNotReceive('sync', 'attachDomain');

        Livewire::test(Index::class)
            ->set('mode', 'cloudflare')
            ->set('landingId', $this->landing->id)
            ->set('integrationId', $integration->id)
            ->set('zoneId', $zone->id)
            ->call('create')
            ->assertHasErrors('integrationId');

        $this->assertDatabaseCount('domains', 0);
        Queue::assertNothingPushed();
    }

    public function test_selecting_another_accounts_zone_is_rejected_without_creating_or_queuing_a_domain(): void
    {
        [$integration] = $this->cloudflareAccount('Selected account', 'example.com');
        [, $otherZone] = $this->cloudflareAccount('Other account', 'other.com');
        $this->cloudflare->shouldNotReceive('attachDomain');

        Livewire::test(Index::class)
            ->set('mode', 'cloudflare')
            ->set('landingId', $this->landing->id)
            ->set('integrationId', $integration->id)
            ->set('zoneId', $otherZone->id)
            ->call('create')
            ->assertHasErrors('zoneId');

        $this->assertDatabaseCount('domains', 0);
        Queue::assertNothingPushed();
    }

    public function test_manual_domain_creation_shows_exact_dns_instructions_and_queues_verification(): void
    {
        Livewire::test(Index::class)
            ->set('showCreate', true)
            ->set('mode', 'dns')
            ->set('landingId', $this->landing->id)
            ->set('hostname', 'Offer.Example.COM')
            ->call('create')
            ->assertHasNoErrors()
            ->assertSee('DNS setup instructions')
            ->assertSee(['Type', 'CNAME', 'TTL', 'Auto / default', 'Name / host', 'offer.example.com', 'Target / value', 'origin.fast-landings.test'])
            ->assertSee('ALIAS, ANAME, or CNAME flattening')
            ->assertSee('Checks continue automatically, even after you close this page.');

        $domain = Domain::query()->sole();
        $this->assertSame(DomainStatus::Pending, $domain->status);
        $this->assertNull($domain->last_checked_at);
        Queue::assertPushed(CheckDomain::class, fn ($job): bool => $job->domainId === $domain->id
            && $job->requestId === $domain->verification_request_id && $job->connection === 'database');
        Queue::assertPushed(CheckDomain::class, 1);
        Queue::assertNotPushed(ProvisionCloudflareDomain::class);
    }

    #[DataProvider('ipTargets')]
    public function test_manual_ip_targets_show_address_records_without_cname_flattening_instructions(string $target, string $type, string $version): void
    {
        $this->installation->update(['origin_target' => $target]);

        Livewire::test(Index::class)
            ->set('showCreate', true)
            ->set('mode', 'dns')
            ->set('landingId', $this->landing->id)
            ->set('hostname', 'example.com')
            ->call('create')
            ->assertHasNoErrors()
            ->assertSee('DNS setup instructions')
            ->assertSee(['Type', $type, 'Name / host', 'example.com', 'Target / value', $target, $version])
            ->assertSee('works for both root domains and subdomains.')
            ->assertDontSee('ALIAS, ANAME, or CNAME flattening');

        $domain = Domain::query()->sole();
        $this->assertSame($target, $domain->dns_target);
        $this->assertSame($type, $domain->dnsRecordType());
        $this->assertSame(DomainStatus::Pending, $domain->status);
        Queue::assertPushed(CheckDomain::class, fn ($job): bool => $job->domainId === $domain->id);
    }

    /** @return array<string, array{string, string, string}> */
    public static function ipTargets(): array
    {
        return [
            'IPv4 server' => ['203.0.113.10', 'A', 'IPv4'],
            'IPv6 server' => ['2001:db8::10', 'AAAA', 'IPv6'],
        ];
    }

    public function test_switching_to_manual_dns_discards_an_invalid_system_subdomain(): void
    {
        Livewire::test(Index::class)
            ->set('subdomain', 'invalid.system.label')
            ->set('mode', 'dns')
            ->set('landingId', $this->landing->id)
            ->set('hostname', 'offer.example.com')
            ->call('create')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('domains', ['hostname' => 'offer.example.com', 'provider' => 'dns']);
        Queue::assertPushed(CheckDomain::class, 1);
    }

    public function test_local_system_domain_accepts_an_explicit_localhost_hostname(): void
    {
        $this->app->instance('env', 'local');

        Livewire::test(Index::class)
            ->set('showCreate', true)
            ->set('mode', 'system')
            ->set('landingId', $this->landing->id)
            ->set('subdomain', 'Demo.Localhost')
            ->call('create')
            ->assertHasNoErrors()
            ->assertSet('showCreate', false);

        $this->assertDatabaseHas('domains', [
            'hostname' => 'demo.localhost',
            'system_subdomain' => 'demo',
            'provider' => 'system',
            'status' => 'active',
        ]);
        Queue::assertNothingPushed();
    }

    public function test_non_local_system_domain_still_rejects_a_full_hostname(): void
    {
        $this->app->instance('env', 'production');

        Livewire::test(Index::class)
            ->set('mode', 'system')
            ->set('landingId', $this->landing->id)
            ->set('subdomain', 'demo.localhost')
            ->call('create')
            ->assertHasErrors('subdomain');

        $this->assertDatabaseCount('domains', 0);
        Queue::assertNothingPushed();
    }

    public function test_check_now_only_queues_work_and_page_polling_does_not_run_dns_checks(): void
    {
        $domain = app(DomainManager::class)->createDns($this->landing, 'offer.example.com');

        Livewire::test(Index::class)
            ->call('verify', $domain->id)
            ->assertHasNoErrors()
            ->assertSee('DNS check queued. The status will update automatically.')
            ->assertSee('Connection check in progress…')
            ->call('$refresh')
            ->assertHasNoErrors();

        Queue::assertPushed(CheckDomain::class, 1);
        $this->assertNull($domain->refresh()->last_checked_at);
        $this->assertSame(DomainStatus::Pending, $domain->status);
    }

    public function test_polling_renders_the_latest_status_without_resetting_an_unsaved_landing_selection(): void
    {
        $domain = app(DomainManager::class)->createDns($this->landing, 'offer.example.com');
        $newLanding = $this->landing('Second landing');
        $component = Livewire::test(Index::class)
            ->call('beginAssignment', $domain->id)
            ->set('assignmentLandingId', $newLanding->id)
            ->assertSee('Awaiting DNS check');

        $domain->update(['status' => DomainStatus::Active, 'last_checked_at' => now()]);

        $component->call('$refresh')
            ->assertSee('DNS verified')
            ->assertDontSee('Awaiting DNS check')
            ->assertSet('assignmentLandingId', $newLanding->id);

        $this->assertSame($this->landing->id, $domain->refresh()->landing_id);
        Queue::assertNothingPushed();
    }

    private function landing(string $name): Landing
    {
        return Landing::query()->create(['name' => $name, 'slug' => Str::slug($name), 'is_active' => true]);
    }

    /** @return array{CloudflareIntegration, CloudflareZone} */
    private function cloudflareAccount(string $label, string $hostname): array
    {
        $token = Str::random(32);
        $integration = $this->installation->cloudflareIntegrations()->create([
            'label' => $label, 'api_token' => $token, 'token_fingerprint' => hash('sha256', $token),
            'token_status' => 'active', 'status' => IntegrationStatus::Active,
        ]);
        $account = $integration->accounts()->create(['name' => $label, 'cloudflare_id' => Str::random(32), 'status' => 'active']);
        $zone = $account->zones()->create(['cloudflare_id' => Str::random(32), 'name' => $hostname, 'status' => 'active']);

        return [$integration, $zone];
    }
}
