<?php

namespace Tests\Feature;

use App\Enums\DomainKind;
use App\Enums\DomainProvider;
use App\Enums\DomainStatus;
use App\Models\Domain;
use App\Models\Landing;
use App\Models\LandingRelease;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CaddyAskControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['fast-landings.caddy_ask_token' => 'private-caddy-token']);
        $landing = $this->landing('Routable', active: true, withRelease: true);
        Domain::query()->create([
            'landing_id' => $landing->id,
            'hostname' => 'configured.example.test',
            'system_subdomain' => null,
            'kind' => DomainKind::Custom,
            'provider' => DomainProvider::Dns,
            'status' => DomainStatus::Active,
            'is_primary' => true,
            'dns_target' => 'origin.fast-landings.test',
        ]);
    }

    public function test_valid_token_allows_only_configured_domains(): void
    {
        $this->get('/internal/caddy/ask?token=private-caddy-token&domain=configured.example.test')
            ->assertOk()
            ->assertHeaderMissing('Set-Cookie');

        $this->get('/internal/caddy/ask?token=private-caddy-token&domain=CONFIGURED.EXAMPLE.TEST.')
            ->assertOk();

        $this->get('/internal/caddy/ask?token=private-caddy-token&domain=unknown.example.test')
            ->assertNotFound();
    }

    public function test_missing_or_incorrect_token_is_forbidden_before_domain_lookup(): void
    {
        $this->get('/internal/caddy/ask?domain=configured.example.test')->assertForbidden();
        $this->get('/internal/caddy/ask?token=wrong&domain=configured.example.test')->assertForbidden();
        $this->get('/internal/caddy/ask?token=wrong&domain=unknown.example.test')->assertForbidden();
    }

    public function test_empty_configured_token_disables_the_endpoint(): void
    {
        config(['fast-landings.caddy_ask_token' => '']);

        $this->get('/internal/caddy/ask?token=&domain=configured.example.test')->assertForbidden();
    }

    public function test_registered_but_unroutable_domains_are_rejected(): void
    {
        $pendingLanding = $this->landing('Pending', active: true, withRelease: true);
        $inactiveLanding = $this->landing('Inactive', active: false, withRelease: true);
        $emptyLanding = $this->landing('Empty', active: true, withRelease: false);

        $this->domain('pending.example.test', $pendingLanding, DomainStatus::Pending);
        $this->domain('unassigned.example.test', null, DomainStatus::Active);
        $this->domain('inactive.example.test', $inactiveLanding, DomainStatus::Active);
        $this->domain('empty.example.test', $emptyLanding, DomainStatus::Active);

        foreach (['pending', 'unassigned', 'inactive', 'empty'] as $label) {
            $this->get("/internal/caddy/ask?token=private-caddy-token&domain={$label}.example.test")
                ->assertNotFound();
        }
    }

    public function test_wildcard_dns_never_authorizes_unknown_subdomain_certificates(): void
    {
        $rootLanding = $this->landing('Wildcard root', active: true, withRelease: true);
        $base = $this->domain('example.test', $rootLanding, DomainStatus::Active);
        $base->update(['dns_scope' => 'wildcard']);
        $child = $this->domain('offer.example.test', $this->landing('Explicit offer', active: true, withRelease: true), DomainStatus::Active);
        $child->update(['parent_domain_id' => $base->id]);
        $unassigned = $this->domain('unused.example.test', null, DomainStatus::Active);
        $unassigned->update(['parent_domain_id' => $base->id]);

        foreach (['example.test', 'offer.example.test'] as $hostname) {
            $this->get('/internal/caddy/ask?token=private-caddy-token&domain='.$hostname)->assertOk();
        }
        foreach (['unknown.example.test', 'nested.offer.example.test', 'unused.example.test', '*.example.test'] as $hostname) {
            $this->get('/internal/caddy/ask?token=private-caddy-token&domain='.$hostname)->assertNotFound();
        }
    }

    private function landing(string $name, bool $active, bool $withRelease): Landing
    {
        $landing = Landing::query()->create([
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'is_active' => $active,
        ]);

        if ($withRelease) {
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
        }

        return $landing;
    }

    private function domain(string $hostname, ?Landing $landing, DomainStatus $status): Domain
    {
        return Domain::query()->create([
            'landing_id' => $landing?->id,
            'hostname' => $hostname,
            'kind' => DomainKind::Custom,
            'provider' => DomainProvider::Dns,
            'status' => $status,
            'is_primary' => $landing !== null,
            'dns_target' => 'origin.fast-landings.test',
        ]);
    }
}
