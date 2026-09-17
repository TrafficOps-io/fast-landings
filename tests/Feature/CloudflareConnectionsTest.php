<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Livewire\Cloudflare\Index;
use App\Models\Domain;
use App\Models\Installation;
use App\Models\Landing;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use TrafficOps\Cloudflare\Enums\IntegrationStatus;
use TrafficOps\Cloudflare\Models\CloudflareIntegration;

class CloudflareConnectionsTest extends TestCase
{
    use RefreshDatabase;

    private Installation $installation;

    private User $administrator;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['cloudflare.api.retries' => 0]);
        $this->installation = Installation::query()->create([
            'name' => 'Test installation', 'domain' => 'fast-landings.test', 'origin_target' => 'origin.fast-landings.test',
        ]);
        $this->administrator = User::factory()->create(['role' => UserRole::Administrator]);
        $this->actingAs($this->administrator);
    }

    public function test_administrators_can_reach_connection_settings_from_the_profile_menu(): void
    {
        $this->get('http://fast-landings.test/admin')->assertOk()->assertSee(route('cloudflare.index'));
        $this->get(route('cloudflare.index'))->assertOk()->assertSee('No Cloudflare connections yet');
        $this->get('http://customer.example.test/admin/cloudflare')->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_multiple_tokens_expose_real_accounts_and_all_zone_statuses_without_writing_dns(): void
    {
        $this->fakeCloudflare([
            'agency-token' => [
                $this->remoteZone('primary', 'Customer One', 'active.example.com'),
                $this->remoteZone('pending', 'Customer Two', 'pending.example.com', 'pending'),
                [...$this->remoteZone('paused', 'Customer Two', 'paused.example.com'), 'paused' => true],
            ],
            'personal-token' => [$this->remoteZone('personal', 'My account', 'personal.example.com')],
        ]);

        $component = Livewire::test(Index::class)->set('label', 'Agency sites')->call('save', 'agency-token')
            ->assertHasNoErrors()->assertSee('Agency sites')->assertSee('Customer One')->assertSee('Customer Two')
            ->assertSee('pending.example.com')->assertSee('Pending activation')->assertSee('paused.example.com')->assertSee('Paused');

        $component->call('add')->set('label', 'Personal sites')->call('save', 'personal-token')
            ->assertHasNoErrors()->assertSee('Agency sites')->assertSee('Personal sites')->assertSee('My account');
        $this->assertDatabaseCount('cloudflare_integrations', 2);
        $this->assertDatabaseCount('cloudflare_accounts', 3);
        $this->assertDatabaseCount('cloudflare_zones', 4);
        Http::assertNotSent(fn (Request $request): bool => $request->method() !== 'GET');
    }

    public function test_tokens_are_encrypted_and_never_returned_in_component_state_or_html(): void
    {
        $secret = 'secret-token-not-for-client-state';
        $this->fakeCloudflare([$secret => [$this->remoteZone()]]);
        $component = Livewire::test(Index::class)->set('label', 'Private')->call('save', $secret)->assertHasNoErrors();
        $integration = CloudflareIntegration::query()->sole();
        $encrypted = DB::table('cloudflare_integrations')->value('api_token');
        $this->assertNotSame($secret, $encrypted);
        $this->assertSame($secret, Crypt::decryptString($encrypted));
        $this->assertArrayNotHasKey('api_token', $integration->toArray());
        $this->assertArrayNotHasKey('token_fingerprint', $integration->toArray());
        $component->call('edit', $integration->id)->assertDontSee($secret)->assertDontSee($encrypted);
        $this->assertArrayNotHasKey('apiToken', $component->snapshot['data']);
        $this->assertStringNotContainsString($secret, json_encode([$component->snapshot, $component->effects], JSON_THROW_ON_ERROR));

        $component->set('label', '')->call('save', $secret)->assertHasErrors('label');
        $this->assertStringNotContainsString($secret, json_encode([$component->snapshot, $component->effects], JSON_THROW_ON_ERROR));
    }

    public function test_duplicate_token_or_connection_name_is_rejected_without_relabeling_an_existing_connection(): void
    {
        $integration = $this->connection();
        Livewire::test(Index::class)->call('add')->set('label', 'Different name')->call('save', 'old-token')->assertHasErrors('apiToken');
        Livewire::test(Index::class)->call('add')->set('label', 'Production')->call('save', 'another-token')->assertHasErrors('label');
        $this->assertSame('Production', $integration->fresh()->label);
        $this->assertDatabaseCount('cloudflare_integrations', 1);
        Http::assertNothingSent();
    }

    public function test_blank_token_renames_connection_without_requiring_cloudflare_access(): void
    {
        $integration = $this->connection();
        $encrypted = $integration->getRawOriginal('api_token');
        Livewire::test(Index::class)->call('edit', $integration->id)->set('label', ' Renamed ')->call('save')->assertHasNoErrors();
        $this->assertSame('Renamed', $integration->fresh()->label);
        $this->assertSame($encrypted, $integration->fresh()->getRawOriginal('api_token'));
        Http::assertNothingSent();
    }

    public function test_rotating_token_keeps_integration_zone_and_domain_links(): void
    {
        [$integration, $zone, $domain] = $this->linkedConnection();
        $this->fakeCloudflare(['replacement-token' => [$this->remoteZone()]]);

        Livewire::test(Index::class)->call('edit', $integration->id)->set('label', 'Rotated')
            ->call('save', ' replacement-token ')->assertHasNoErrors()->assertSee('Existing domain links are preserved.');

        $this->assertSame('replacement-token', $integration->fresh()->api_token);
        $this->assertSame(hash('sha256', 'replacement-token'), $integration->fresh()->token_fingerprint);
        $this->assertSame('Rotated', $integration->fresh()->label);
        $this->assertSame($zone->id, $domain->fresh()->cloudflareDomain->zone_id);
        $this->assertSame($integration->id, $zone->fresh()->account->integration_id);
        $this->assertDatabaseCount('cloudflare_integrations', 1);
        $this->assertDatabaseCount('cloudflare_zones', 1);
        Http::assertNotSent(fn (Request $request): bool => $request->method() !== 'GET');
    }

    public function test_rotating_to_a_token_that_loses_a_linked_zone_is_rejected(): void
    {
        [$integration, $zone, $domain] = $this->linkedConnection();
        $this->fakeCloudflare(['replacement-token' => [$this->remoteZone('other', 'Another account', 'other.example.com')]]);
        Livewire::test(Index::class)->call('edit', $integration->id)->set('label', 'Changed')
            ->call('save', 'replacement-token')->assertHasErrors('apiToken')->assertSee('Missing access to example.com');
        $this->assertSame('old-token', $integration->fresh()->api_token);
        $this->assertSame('Production', $integration->fresh()->label);
        $this->assertSame($zone->id, $domain->fresh()->cloudflareDomain->zone_id);
        $this->assertDatabaseCount('cloudflare_zones', 1);
    }

    public function test_rotation_to_token_already_used_by_another_connection_is_rejected(): void
    {
        $integration = $this->connection();
        $other = $this->connection('Other', 'other-token');
        Livewire::test(Index::class)->call('edit', $integration->id)->call('save', 'other-token')->assertHasErrors('apiToken');
        $this->assertSame('old-token', $integration->fresh()->api_token);
        $this->assertSame('other-token', $other->fresh()->api_token);
        Http::assertNothingSent();
    }

    public function test_failed_rotation_keeps_saved_credentials_and_hides_upstream_error_contents(): void
    {
        $integration = $this->connection();
        Http::fake(['*' => Http::response(['success' => false, 'errors' => [['message' => 'Internal detail replacement-token']]], 401)]);
        $component = Livewire::test(Index::class)->call('edit', $integration->id)
            ->call('save', 'replacement-token')->assertHasErrors('apiToken')->assertSee('Cloudflare rejected the token')
            ->assertDontSee('Internal detail')->assertDontSee('replacement-token');
        $this->assertSame('old-token', $integration->fresh()->api_token);
        $this->assertStringNotContainsString('replacement-token', json_encode([$component->snapshot, $component->effects], JSON_THROW_ON_ERROR));
    }

    public function test_remote_access_change_during_rotation_rolls_back_token_and_zone_updates(): void
    {
        [$integration, $zone] = $this->linkedConnection();
        $zoneCalls = 0;
        Http::fake(function (Request $request) use (&$zoneCalls) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            if (str_ends_with($path, '/user/tokens/verify')) {
                return Http::response(['success' => true, 'result' => ['id' => 'new-id', 'status' => 'active']]);
            }
            if (str_ends_with($path, '/zones')) {
                $zoneCalls++;

                return Http::response(['success' => true, 'result' => $zoneCalls === 1
                    ? [$this->remoteZone()] : [$this->remoteZone('other', 'Another account', 'other.example.com')]]);
            }

            return Http::response(['success' => true, 'result' => []]);
        });
        Livewire::test(Index::class)->call('edit', $integration->id)->call('save', 'replacement-token')
            ->assertHasErrors('apiToken')->assertSee('The saved token has not changed');
        $this->assertSame('old-token', $integration->fresh()->api_token);
        $this->assertSame('active', $zone->fresh()->status);
        $this->assertDatabaseCount('cloudflare_accounts', 1);
        $this->assertDatabaseCount('cloudflare_zones', 1);
    }

    public function test_refresh_shows_zones_that_lost_access_and_does_not_touch_dns(): void
    {
        [$integration, $zone] = $this->linkedConnection();
        $this->fakeCloudflare(['old-token' => [$this->remoteZone('other', 'Another account', 'other.example.com')]]);
        Livewire::test(Index::class)->call('sync', $integration->id)->assertHasNoErrors()
            ->assertSee('example.com')->assertSee('Access lost')->assertSee('other.example.com');
        $this->assertSame('inaccessible', $zone->fresh()->status);
        Http::assertNotSent(fn (Request $request): bool => $request->method() !== 'GET');
    }

    public function test_disconnect_is_blocked_by_linked_domains_and_preserves_all_links(): void
    {
        [$integration, $zone, $domain] = $this->linkedConnection();
        Livewire::test(Index::class)->call('disconnect', $integration->id)->assertHasErrors('connection.'.$integration->id);
        $this->assertModelExists($integration);
        $this->assertModelExists($zone);
        $this->assertNotNull($domain->fresh()->cloudflare_domain_id);
        Http::assertNothingSent();
    }

    public function test_disconnect_removes_only_unused_local_connection_and_clears_editor(): void
    {
        $integration = $this->connection();
        $other = $this->connection('Other', 'other-token');
        Livewire::test(Index::class)->call('edit', $integration->id)->call('disconnect', $integration->id)
            ->assertHasNoErrors()->assertSet('editingIntegrationId', null)->assertSee('Other');
        $this->assertModelMissing($integration);
        $this->assertModelExists($other);
        Http::assertNothingSent();
    }

    public function test_zone_add_link_carries_connection_zone_and_landing_context(): void
    {
        [$integration, $zone] = $this->linkedConnection();
        $landing = Landing::query()->create(['name' => 'Campaign', 'slug' => 'campaign', 'is_active' => true]);
        Livewire::withQueryParams(['landing' => $landing->id])->test(Index::class)->assertSet('landingId', $landing->id)
            ->assertSee(route('domains.index', ['create' => 1, 'mode' => 'cloudflare', 'integration' => $integration->id, 'zone' => $zone->id, 'landing' => $landing->id]));
    }

    public function test_guests_editors_and_inactive_administrators_cannot_access_settings(): void
    {
        auth()->logout();
        $this->get(route('cloudflare.index'))->assertRedirect(route('login'));
        Livewire::test(Index::class)->assertForbidden();
        $editor = User::factory()->create(['role' => UserRole::Editor]);
        $this->actingAs($editor)->get(route('cloudflare.index'))->assertForbidden();
        $this->get('http://fast-landings.test/admin')->assertDontSee(route('cloudflare.index'));
        Livewire::actingAs($editor)->test(Index::class)->assertForbidden();
        $inactive = User::factory()->create(['role' => UserRole::Administrator, 'is_active' => false]);
        Livewire::actingAs($inactive)->test(Index::class)->assertForbidden();
    }

    #[DataProvider('restrictedActions')]
    public function test_actions_recheck_administrator_access_on_an_already_open_page(string $action, array $revocation): void
    {
        $integration = $this->connection();
        $component = Livewire::test(Index::class)->call('edit', $integration->id);
        User::query()->whereKey($this->administrator->id)->update($revocation);
        $component->call($action, $action === 'save' ? 'replacement-token' : $integration->id)->assertForbidden();
        $this->assertSame('old-token', $integration->fresh()->api_token);
        Http::assertNothingSent();
    }

    public static function restrictedActions(): array
    {
        $cases = [];
        foreach (['add', 'edit', 'cancelEdit', 'save', 'sync', 'disconnect'] as $action) {
            $cases[$action.' after demotion'] = [$action, ['role' => UserRole::Editor->value]];
            $cases[$action.' after deactivation'] = [$action, ['is_active' => false]];
        }

        return $cases;
    }

    public function test_client_cannot_change_the_editing_connection_id(): void
    {
        $integration = $this->connection();
        $this->expectException(CannotUpdateLockedPropertyException::class);
        Livewire::test(Index::class)->set('editingIntegrationId', $integration->id);
    }

    private function connection(string $label = 'Production', string $token = 'old-token'): CloudflareIntegration
    {
        return $this->installation->cloudflareIntegrations()->create([
            'label' => $label, 'api_token' => $token, 'token_fingerprint' => hash('sha256', $token),
            'status' => IntegrationStatus::Active, 'token_status' => 'active',
        ]);
    }

    private function linkedConnection(): array
    {
        $integration = $this->connection();
        $account = $integration->accounts()->create(['cloudflare_id' => 'account-Customer', 'name' => 'Customer', 'status' => 'active']);
        $zone = $account->zones()->create(['cloudflare_id' => 'zone-primary', 'name' => 'example.com', 'status' => 'active']);
        $cloudflareDomain = $zone->domains()->create(['hostname' => 'offer.example.com', 'kind' => 'exact']);
        $domain = Domain::query()->create([
            'hostname' => 'offer.example.com', 'kind' => 'custom', 'provider' => 'cloudflare',
            'cloudflare_domain_id' => $cloudflareDomain->id,
        ]);

        return [$integration, $zone, $domain];
    }

    private function remoteZone(string $id = 'primary', string $account = 'Customer', string $name = 'example.com', string $status = 'active'): array
    {
        return ['id' => 'zone-'.$id, 'name' => $name, 'status' => $status, 'paused' => false,
            'account' => ['id' => 'account-'.$account, 'name' => $account]];
    }

    private function fakeCloudflare(array $zonesByToken): void
    {
        Http::fake(function (Request $request) use ($zonesByToken) {
            $token = str_replace('Bearer ', '', $request->header('Authorization')[0] ?? '');
            $this->assertArrayHasKey($token, $zonesByToken);
            $this->assertSame('GET', $request->method());
            $path = parse_url($request->url(), PHP_URL_PATH);
            $result = match (true) {
                str_ends_with($path, '/user/tokens/verify') => ['id' => 'token-id', 'status' => 'active'],
                str_ends_with($path, '/zones') => $zonesByToken[$token],
                str_ends_with($path, '/dns_records') => [],
                default => throw new \LogicException('Unexpected Cloudflare request.'),
            };

            return Http::response(['success' => true, 'result' => $result]);
        });
    }
}
