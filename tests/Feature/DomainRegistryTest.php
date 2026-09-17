<?php

namespace Tests\Feature;

use App\Enums\DomainStatus;
use App\Enums\UserRole;
use App\Jobs\CheckDomain;
use App\Livewire\Domains\Index;
use App\Models\Domain;
use App\Models\Installation;
use App\Models\Landing;
use App\Models\User;
use App\Services\DomainManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class DomainRegistryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->actingAs(User::factory()->create(['role' => UserRole::Administrator]));
        Installation::query()->create(['name' => 'Registry', 'domain' => 'fast-landings.test', 'origin_target' => '203.0.113.10']);
    }

    public function test_domain_can_be_connected_before_any_landing_exists(): void
    {
        Livewire::test(Index::class)->set('mode', 'dns')->set('hostname', 'example.com')
            ->call('create')->assertHasNoErrors()->assertSee('DNS setup instructions')->assertSee('Unassigned');
        $domain = Domain::query()->sole();
        $this->assertNull($domain->landing_id);
        $this->assertFalse($domain->is_primary);
        Queue::assertPushed(CheckDomain::class, 1);
    }

    public function test_wildcard_scope_creates_base_with_both_dns_instructions(): void
    {
        Livewire::test(Index::class)->set('mode', 'dns')->set('dnsScope', 'wildcard')->set('hostname', 'example.com')
            ->call('create')->assertHasNoErrors()->assertSee('*.example.com')->assertSee('example.com');
        $this->assertSame('wildcard', Domain::query()->sole()->dns_scope);
        $this->assertSame(['example.com', '*.example.com'], Domain::query()->sole()->dnsRecordNames());
    }

    public function test_subdomain_scope_builds_exact_hostname_and_rejects_more_than_one_label(): void
    {
        $component = Livewire::test(Index::class)->set('mode', 'dns')->set('dnsScope', 'subdomain')
            ->set('baseHostname', 'example.com')->set('hostnameLabel', 'bad.label')
            ->call('create')->assertHasErrors('hostnameLabel');
        $this->assertDatabaseCount('domains', 0);
        $component->set('hostnameLabel', 'offer')->call('create')->assertHasNoErrors();
        $this->assertDatabaseHas('domains', ['hostname' => 'offer.example.com', 'dns_scope' => 'exact']);
    }

    public function test_transfer_is_reviewed_before_applying_and_stale_review_is_rejected(): void
    {
        $from = Landing::query()->create(['name' => 'Original', 'slug' => 'original']);
        $to = Landing::query()->create(['name' => 'Destination', 'slug' => 'destination']);
        $other = Landing::query()->create(['name' => 'Concurrent', 'slug' => 'concurrent']);
        $domain = app(DomainManager::class)->createDns($from, 'example.com');
        $component = Livewire::test(Index::class)->call('beginAssignment', $domain->id)
            ->set('assignmentLandingId', $to->id)->call('reviewAssignment')
            ->assertSee('Review assignment')->assertSee('Original')->assertSee('Destination');
        $this->assertSame($from->id, $domain->refresh()->landing_id);
        app(DomainManager::class)->reassign($domain, $other);
        $component->call('confirmAssignment')->assertHasErrors('assignmentId');
        $this->assertSame($other->id, $domain->refresh()->landing_id);
    }

    public function test_assignment_review_uses_locked_target_and_unassignment_keeps_domain(): void
    {
        $landing = Landing::query()->create(['name' => 'Target', 'slug' => 'target']);
        $other = Landing::query()->create(['name' => 'Other', 'slug' => 'other']);
        $domain = app(DomainManager::class)->createDns(null, 'example.com');
        $component = Livewire::test(Index::class)->call('beginAssignment', $domain->id)
            ->set('assignmentLandingId', $landing->id)->call('reviewAssignment')
            ->set('assignmentLandingId', $other->id)->call('confirmAssignment')->assertHasNoErrors();
        $this->assertSame($landing->id, $domain->refresh()->landing_id);
        $component->call('beginAssignment', $domain->id)->set('assignmentLandingId', '')
            ->call('reviewAssignment')->call('confirmAssignment')->assertHasNoErrors();
        $this->assertNull($domain->refresh()->landing_id);
        $this->assertFalse($domain->is_primary);
    }

    public function test_back_from_review_keeps_selection_without_mutation(): void
    {
        $landing = Landing::query()->create(['name' => 'Target', 'slug' => 'target']);
        $domain = app(DomainManager::class)->createDns(null, 'example.com');
        Livewire::test(Index::class)->call('beginAssignment', $domain->id)->set('assignmentLandingId', $landing->id)
            ->call('reviewAssignment')->call('backToAssignment')->assertSet('assignmentReview', null)
            ->assertSet('assignmentLandingId', $landing->id)->assertHasNoErrors();
        $this->assertNull($domain->refresh()->landing_id);
    }

    public function test_search_status_and_assignment_filter_registry(): void
    {
        $landing = Landing::query()->create(['name' => 'Summer campaign', 'slug' => 'summer']);
        $first = app(DomainManager::class)->createDns($landing, 'summer.example.com');
        $first->update(['status' => DomainStatus::Drifted]);
        app(DomainManager::class)->createDns(null, 'spare.example.com');
        Livewire::test(Index::class)->set('search', 'SUMMER')->assertSee('summer.example.com')->assertDontSee('spare.example.com')
            ->set('search', '')->set('assignment', 'unassigned')->assertSee('spare.example.com')->assertDontSee('summer.example.com')
            ->call('clearFilters')->set('status', 'attention')->assertSee('summer.example.com')->assertDontSee('spare.example.com');
    }

    public function test_domain_deep_link_opens_connection_details_and_invalid_link_is_recoverable(): void
    {
        $domain = app(DomainManager::class)->createDns(null, 'example.com');
        Livewire::withQueryParams(['domain' => $domain->id])->test(Index::class)->assertSee('DNS setup instructions');
        Livewire::withQueryParams(['domain' => 'missing'])->test(Index::class)
            ->assertSee('This domain is no longer in the registry.')->call('closeDetails')->assertSet('selectedDomainId', '');
    }

    public function test_removing_a_wildcard_base_is_blocked_while_subdomains_depend_on_it(): void
    {
        $base = app(DomainManager::class)->createDns(null, 'example.com', wildcard: true);
        app(DomainManager::class)->createSubdomain($base, null, 'offer');
        Livewire::test(Index::class)->call('beginRemove', $base->id)->assertSet('cleanupDns', false)
            ->call('remove')->assertHasErrors('domain');
        $this->assertDatabaseCount('domains', 2);
    }

    public function test_connection_credentials_are_managed_only_on_separate_admin_page(): void
    {
        Livewire::test(Index::class)->set('showCreate', true)->set('mode', 'cloudflare')
            ->assertSee('Manage Cloudflare connections')->assertDontSee('Cloudflare API token');
        $this->actingAs(User::factory()->create(['role' => UserRole::Editor]));
        Livewire::test(Index::class)->set('showCreate', true)->set('mode', 'cloudflare')
            ->assertDontSee('Manage Cloudflare connections')->assertSee('Ask an administrator');
    }
}
