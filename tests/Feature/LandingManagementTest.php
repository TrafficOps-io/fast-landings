<?php

namespace Tests\Feature;

use App\Enums\DomainKind;
use App\Enums\DomainProvider;
use App\Enums\DomainStatus;
use App\Jobs\CheckDomain;
use App\Jobs\ProvisionCloudflareDomain;
use App\Livewire\Landings\Show;
use App\Models\Domain;
use App\Models\Installation;
use App\Models\Landing;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;
use TrafficOps\Cloudflare\Contracts\CloudflareManagerContract;
use TrafficOps\Cloudflare\Models\CloudflareDomain;

class LandingManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Installation::query()->create([
            'name' => 'Test installation',
            'domain' => 'fast-landings.test',
            'origin_target' => 'origin.fast-landings.test',
        ]);
    }

    public function test_populated_landing_lists_and_detail_render(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $landing = $this->landingWithPrimaryDomain();

        $this->actingAs($user)
            ->get('http://fast-landings.test/admin')
            ->assertOk()
            ->assertSee($landing->name);

        $this->get('http://fast-landings.test/admin/landings')
            ->assertOk()
            ->assertSee($landing->name);

        $this->get("http://fast-landings.test/admin/landings/{$landing->id}")
            ->assertOk()
            ->assertSee('render.example.test');
    }

    public function test_deleting_landing_clears_primary_flag_from_detached_domains(): void
    {
        Storage::fake('landings');
        $user = User::factory()->create(['is_active' => true]);
        $landing = $this->landingWithPrimaryDomain();
        $domain = $landing->domains()->sole();

        Livewire::actingAs($user)
            ->test(Show::class, ['landing' => $landing])
            ->call('deleteLanding')
            ->assertHasNoErrors()
            ->assertRedirect(route('landings.index'));

        $this->assertDatabaseMissing('landings', ['id' => $landing->id]);
        $this->assertNull($domain->fresh()->landing_id);
        $this->assertFalse($domain->fresh()->is_primary);
    }

    public function test_landing_separates_available_domains_from_domains_used_by_other_landings(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $source = $this->landingWithPrimaryDomain();
        $target = Landing::query()->create(['name' => 'New campaign', 'slug' => 'new-campaign', 'is_active' => true]);
        $available = $this->unassignedDomain();

        Livewire::actingAs($user)
            ->test(Show::class, ['landing' => $target])
            ->assertSee('Available domains')
            ->assertSee('Move a domain from another landing')
            ->assertSee('render.example.test — Rendered landing')
            ->assertViewHas('availableDomains', fn ($domains) => $domains->modelKeys() === [$available->id])
            ->assertViewHas('assignedDomains', fn ($domains) => $domains->modelKeys() === [$source->domains()->sole()->id]);
    }

    public function test_unassigned_domain_can_be_assigned_without_changing_dns(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $landing = Landing::query()->create(['name' => 'New campaign', 'slug' => 'new-campaign', 'is_active' => true]);
        $domain = $this->unassignedDomain();

        Livewire::actingAs($user)
            ->test(Show::class, ['landing' => $landing])
            ->set('domainId', $domain->id)
            ->call('assignDomain')
            ->assertHasNoErrors()
            ->assertSet('domainId', '')
            ->assertSet('domainTransfer', []);

        $this->assertSame($landing->id, $domain->fresh()->landing_id);
        $this->assertTrue($domain->fresh()->is_primary);
        $this->assertSame('origin.fast-landings.test', $domain->fresh()->dns_target);
        $this->assertSame(DomainStatus::Pending, $domain->fresh()->status);
    }

    public function test_landing_can_create_its_own_subdomain_under_a_connected_wildcard_domain(): void
    {
        Queue::fake();
        $user = User::factory()->create(['is_active' => true]);
        $source = $this->landingWithPrimaryDomain();
        $base = $source->domains()->sole();
        $base->update(['dns_scope' => 'wildcard']);
        $exact = $this->unassignedDomain();
        $target = Landing::query()->create(['name' => 'New campaign', 'slug' => 'new-campaign', 'is_active' => true]);

        Livewire::actingAs($user)
            ->test(Show::class, ['landing' => $target])
            ->assertSee('Use a subdomain')
            ->assertViewHas('wildcardDomains', fn ($domains) => $domains->modelKeys() === [$base->id])
            ->set('baseDomainId', $base->id)
            ->set('subdomain', 'Offer')
            ->assertSee('offer.render.example.test')
            ->call('assignSubdomain')
            ->assertHasNoErrors()
            ->assertSet('subdomain', '')
            ->assertSee('offer.render.example.test');

        $child = Domain::query()->where('hostname', 'offer.render.example.test')->sole();
        $this->assertSame($target->id, $child->landing_id);
        $this->assertSame($base->id, $child->parent_domain_id);
        $this->assertSame('exact', $child->dns_scope);
        $this->assertSame($base->dns_target, $child->dns_target);
        $this->assertSame(DomainStatus::Pending, $child->status);
        $this->assertTrue($child->is_primary);
        $this->assertSame($source->id, $base->fresh()->landing_id);
        $this->assertNull($exact->fresh()->landing_id);
        Queue::assertPushed(CheckDomain::class, fn ($job) => $job->domainId === $child->id);
        Queue::assertNotPushed(ProvisionCloudflareDomain::class);
    }

    public function test_subdomain_assignment_rejects_exact_only_domains_and_invalid_labels(): void
    {
        Queue::fake();
        $user = User::factory()->create(['is_active' => true]);
        $landing = $this->landingWithPrimaryDomain();
        $base = $landing->domains()->sole();

        $screen = Livewire::actingAs($user)
            ->test(Show::class, ['landing' => $landing])
            ->set('baseDomainId', $base->id)
            ->set('subdomain', 'offer')
            ->call('assignSubdomain')
            ->assertHasErrors('baseDomainId');

        $base->update(['dns_scope' => 'wildcard']);

        $screen->set('subdomain', 'nested.offer')
            ->call('assignSubdomain')
            ->assertHasErrors('subdomain');

        $this->assertDatabaseCount('domains', 1);
        Queue::assertNothingPushed();
    }

    public function test_cloudflare_subdomain_uses_the_selected_connection_without_provisioning_dns(): void
    {
        Queue::fake();
        $this->app->instance(CloudflareManagerContract::class, Mockery::mock(CloudflareManagerContract::class));
        $user = User::factory()->create(['is_active' => true]);
        $landing = Landing::query()->create(['name' => 'New campaign', 'slug' => 'new-campaign', 'is_active' => true]);
        $integration = Installation::singleton()->cloudflareIntegrations()->create([
            'label' => 'Marketing token', 'api_token' => 'test-api-token', 'token_fingerprint' => hash('sha256', 'test-api-token'),
            'token_status' => 'active', 'status' => 'active',
        ]);
        $account = $integration->accounts()->create(['name' => 'Campaign account', 'cloudflare_id' => str_repeat('a', 32), 'status' => 'active']);
        $zone = $account->zones()->create(['cloudflare_id' => str_repeat('b', 32), 'name' => 'campaigns.test', 'status' => 'active']);
        $remote = CloudflareDomain::query()->create(['zone_id' => $zone->id, 'hostname' => 'campaigns.test', 'kind' => 'exact', 'status' => 'active']);
        $base = $this->unassignedDomain();
        $base->update([
            'hostname' => 'campaigns.test', 'provider' => DomainProvider::Cloudflare, 'dns_scope' => 'wildcard',
            'cloudflare_domain_id' => $remote->id, 'status' => DomainStatus::Active,
        ]);

        Livewire::actingAs($user)
            ->test(Show::class, ['landing' => $landing])
            ->set('baseDomainId', $base->id)
            ->assertSee('Marketing token')
            ->assertSee('Campaign account')
            ->set('subdomain', 'offer')
            ->call('assignSubdomain')
            ->assertHasNoErrors();

        $child = Domain::query()->where('hostname', 'offer.campaigns.test')->sole();
        $this->assertSame(DomainProvider::Cloudflare, $child->provider);
        $this->assertSame($base->id, $child->parent_domain_id);
        $this->assertNull($child->cloudflare_domain_id);
        $this->assertDatabaseCount('cloudflare_domains', 1);
        Queue::assertPushed(CheckDomain::class, fn ($job) => $job->domainId === $child->id);
        Queue::assertNotPushed(ProvisionCloudflareDomain::class);
    }

    public function test_existing_subdomain_cannot_be_taken_over_by_creating_it_again(): void
    {
        Queue::fake();
        $user = User::factory()->create(['is_active' => true]);
        $source = $this->landingWithPrimaryDomain();
        $base = $source->domains()->sole();
        $base->update(['dns_scope' => 'wildcard']);
        $existing = $this->unassignedDomain();
        $existing->update(['hostname' => 'offer.'.$base->hostname, 'parent_domain_id' => $base->id, 'landing_id' => $source->id]);
        $target = Landing::query()->create(['name' => 'New campaign', 'slug' => 'new-campaign', 'is_active' => true]);

        Livewire::actingAs($user)
            ->test(Show::class, ['landing' => $target])
            ->set('baseDomainId', $base->id)
            ->set('subdomain', 'offer')
            ->call('assignSubdomain')
            ->assertHasErrors('subdomain');

        $this->assertSame($source->id, $existing->fresh()->landing_id);
        $this->assertDatabaseCount('domains', 2);
        Queue::assertNothingPushed();
    }

    public function test_moving_an_assigned_domain_requires_review_before_changing_traffic(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $source = $this->landingWithPrimaryDomain();
        $target = Landing::query()->create(['name' => 'New campaign', 'slug' => 'new-campaign', 'is_active' => true]);
        $domain = $source->domains()->sole();

        $screen = Livewire::actingAs($user)
            ->test(Show::class, ['landing' => $target])
            ->set('transferDomainId', $domain->id)
            ->call('reviewDomainTransfer')
            ->assertHasNoErrors()
            ->assertSee('Move render.example.test?')
            ->assertSee('Current landing:')
            ->assertSee('Rendered landing')
            ->assertSee('New campaign')
            ->assertSee('DNS records stay unchanged.');

        $this->assertSame($source->id, $domain->fresh()->landing_id);

        $screen->call('confirmDomainTransfer')
            ->assertHasNoErrors()
            ->assertSet('domainTransfer', []);

        $this->assertSame($target->id, $domain->fresh()->landing_id);
        $this->assertSame('origin.fast-landings.test', $domain->fresh()->dns_target);
    }

    public function test_domain_claimed_since_the_available_list_was_loaded_requires_review(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $source = $this->landingWithPrimaryDomain();
        $target = Landing::query()->create(['name' => 'New campaign', 'slug' => 'new-campaign', 'is_active' => true]);
        $domain = $this->unassignedDomain();
        $screen = Livewire::actingAs($user)->test(Show::class, ['landing' => $target])->set('domainId', $domain->id);

        $domain->update(['landing_id' => $source->id]);

        $screen->call('assignDomain')
            ->assertHasNoErrors()
            ->assertSet('domainTransfer.from_id', $source->id)
            ->assertSee('Confirm move');

        $this->assertSame($source->id, $domain->fresh()->landing_id);
    }

    public function test_stale_transfer_confirmation_cannot_move_domain_from_a_different_landing(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $source = $this->landingWithPrimaryDomain();
        $target = Landing::query()->create(['name' => 'New campaign', 'slug' => 'new-campaign', 'is_active' => true]);
        $other = Landing::query()->create(['name' => 'Another campaign', 'slug' => 'another-campaign', 'is_active' => true]);
        $domain = $source->domains()->sole();
        $screen = Livewire::actingAs($user)
            ->test(Show::class, ['landing' => $target])
            ->set('transferDomainId', $domain->id)
            ->call('reviewDomainTransfer')
            ->assertHasNoErrors();

        $domain->update(['landing_id' => $other->id]);

        $screen->call('confirmDomainTransfer')
            ->assertHasErrors('assignmentId')
            ->assertSet('domainTransfer', []);

        $this->assertSame($other->id, $domain->fresh()->landing_id);
    }

    public function test_cancelled_transfer_cannot_be_confirmed_and_unassignment_keeps_the_domain(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $source = $this->landingWithPrimaryDomain();
        $target = Landing::query()->create(['name' => 'New campaign', 'slug' => 'new-campaign', 'is_active' => true]);
        $domain = $source->domains()->sole();

        Livewire::actingAs($user)
            ->test(Show::class, ['landing' => $target])
            ->set('transferDomainId', $domain->id)
            ->call('reviewDomainTransfer')
            ->call('cancelDomainTransfer')
            ->assertSet('domainTransfer', [])
            ->call('confirmDomainTransfer')
            ->assertHasErrors('assignmentId');

        $this->assertSame($source->id, $domain->fresh()->landing_id);

        Livewire::actingAs($user)
            ->test(Show::class, ['landing' => $source])
            ->assertSee(route('domains.index', ['domain' => $domain->id]), escape: false)
            ->assertSee('no redirects are created.')
            ->call('detachDomain', $domain->id)
            ->assertHasNoErrors();

        $this->assertNull($domain->fresh()->landing_id);
        $this->assertSame('origin.fast-landings.test', $domain->fresh()->dns_target);
        $this->assertSame(DomainStatus::Active, $domain->fresh()->status);
    }

    public function test_local_landing_link_preserves_the_development_scheme_and_port(): void
    {
        $this->app->instance('env', 'local');
        config(['app.url' => 'http://fast-landings.test:8090']);
        $user = User::factory()->create(['is_active' => true]);
        $landing = $this->landingWithPrimaryDomain();

        $this->actingAs($user)
            ->get("http://fast-landings.test:8090/admin/landings/{$landing->id}")
            ->assertOk()
            ->assertSee('href="http://render.example.test:8090"', escape: false);
    }

    public function test_production_landing_links_use_https_without_the_panel_port(): void
    {
        $this->app->instance('env', 'production');
        config(['app.url' => 'http://fast-landings.test:8090']);
        $domain = new Domain(['hostname' => 'render.example.test']);

        $this->assertSame('https://render.example.test', $domain->publicUrl());
    }

    public function test_local_tls_proxy_can_use_https_without_an_explicit_port(): void
    {
        $this->app->instance('env', 'local');
        config(['app.url' => 'https://fast-landings.test']);
        $domain = new Domain(['hostname' => 'render.example.test']);

        $this->assertSame('https://render.example.test', $domain->publicUrl());
    }

    public function test_shared_list_row_accepts_attribute_and_named_slot_titles(): void
    {
        $attribute = Blade::render('<x-ui::list-row title="Attribute title" />');
        $slot = Blade::render('<x-ui::list-row><x-slot:title>Slot title</x-slot:title></x-ui::list-row>');

        $this->assertStringContainsString('Attribute title', $attribute);
        $this->assertStringContainsString('Slot title', $slot);
    }

    private function landingWithPrimaryDomain(): Landing
    {
        $landing = Landing::query()->create([
            'name' => 'Rendered landing',
            'slug' => 'rendered-landing',
            'is_active' => true,
        ]);

        Domain::query()->create([
            'landing_id' => $landing->id,
            'hostname' => 'render.example.test',
            'kind' => DomainKind::Custom,
            'provider' => DomainProvider::Dns,
            'status' => DomainStatus::Active,
            'is_primary' => true,
            'dns_target' => 'origin.fast-landings.test',
        ]);

        return $landing;
    }

    private function unassignedDomain(): Domain
    {
        return Domain::query()->create([
            'hostname' => 'available.example.test',
            'kind' => DomainKind::Custom,
            'provider' => DomainProvider::Dns,
            'status' => DomainStatus::Pending,
            'is_primary' => false,
            'dns_target' => 'origin.fast-landings.test',
        ]);
    }
}
