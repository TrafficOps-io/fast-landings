<?php

namespace Tests\Feature;

use App\Models\Domain;
use App\Models\Installation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;
use TrafficOps\Cloudflare\DTO\DnsRecordExpectation;

class CloudflareWildcardMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_splitting_and_rolling_back_preserve_managed_and_adopted_record_identity(): void
    {
        Http::preventStrayRequests();
        $migration = require base_path('database/migrations/2026_09_29_000000_split_cloudflare_wildcard_claims.php');
        $migration->down();
        $base = $this->legacyDomain();
        $apexId = $base->cloudflare_domain_id;
        $apexRecord = $base->cloudflareDomain->records()->create([
            ...($expected = new DnsRecordExpectation('A', 'example.com', '192.0.2.10'))->toArray(),
            'signature' => $expected->signature(), 'cloudflare_record_id' => 'apex-record', 'ownership' => 'adopted',
        ]);
        $wildcardRecord = $base->cloudflareDomain->records()->create([
            ...($expected = new DnsRecordExpectation('A', '*.example.com', '192.0.2.10'))->toArray(),
            'signature' => $expected->signature(), 'cloudflare_record_id' => 'wildcard-record', 'ownership' => 'managed',
        ]);

        $migration->up();
        $base->refresh();
        $this->assertSame($apexId, $base->cloudflare_domain_id);
        $this->assertSame('*.example.com', $base->cloudflareWildcardDomain->hostname);
        $this->assertSame($base->cloudflare_wildcard_domain_id, $wildcardRecord->refresh()->domain_id);
        $this->assertSame('managed', $wildcardRecord->ownership->value);
        $this->assertSame('wildcard-record', $wildcardRecord->cloudflare_record_id);
        $this->assertSame($apexId, $apexRecord->refresh()->domain_id);
        $this->assertSame('adopted', $apexRecord->ownership->value);

        $migration->down();
        $this->assertSame($apexId, $wildcardRecord->refresh()->domain_id);
        $this->assertDatabaseCount('cloudflare_domains', 1);
        $migration->up();
        $this->assertDatabaseCount('cloudflare_domains', 2);
        Http::assertNothingSent();
    }

    public function test_a_conflicting_wildcard_claim_stops_migration_before_schema_or_data_changes(): void
    {
        $migration = require base_path('database/migrations/2026_09_29_000000_split_cloudflare_wildcard_claims.php');
        $migration->down();
        $base = $this->legacyDomain();
        $base->cloudflareDomain->zone->domains()->create(['hostname' => '*.example.com', 'kind' => 'wildcard']);
        try {
            $migration->up();
            $this->fail('A conflicting wildcard was silently transferred.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('already reserves [*.example.com]', $exception->getMessage());
            $this->assertFalse(Schema::hasColumn('domains', 'cloudflare_wildcard_domain_id'));
            $this->assertDatabaseCount('cloudflare_domains', 2);
        }
        // Restore the schema for RefreshDatabase's next test.
        $base->cloudflareDomain->zone->domains()->where('hostname', '*.example.com')->delete();
        $migration->up();
    }

    private function legacyDomain(): Domain
    {
        $owner = Installation::query()->create(['name' => 'Installation', 'domain' => 'landings.test', 'origin_target' => '192.0.2.10']);
        $integration = $owner->cloudflareIntegrations()->create([
            'api_token' => 'token', 'token_fingerprint' => hash('sha256', 'token'), 'status' => 'active',
        ]);
        $account = $integration->accounts()->create(['cloudflare_id' => 'account', 'name' => 'Account']);
        $zone = $account->zones()->create(['cloudflare_id' => 'zone', 'name' => 'example.com']);
        $claim = $zone->domains()->create(['hostname' => 'example.com', 'kind' => 'exact', 'status' => 'active']);

        return Domain::query()->create([
            'hostname' => 'example.com', 'kind' => 'custom', 'provider' => 'cloudflare', 'dns_scope' => 'wildcard',
            'status' => 'active', 'cloudflare_domain_id' => $claim->id, 'dns_target' => '192.0.2.10',
        ]);
    }
}
