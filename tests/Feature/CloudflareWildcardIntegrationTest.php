<?php

namespace Tests\Feature;

use App\Enums\DomainStatus;
use App\Models\Installation;
use App\Services\DomainManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use TrafficOps\Cloudflare\Contracts\PublicDnsResolverContract;
use TrafficOps\Cloudflare\DTO\DnsLookupResult;
use TrafficOps\Cloudflare\Exceptions\CloudflareTransportException;
use TrafficOps\Cloudflare\Models\CloudflareDomain;

class CloudflareWildcardIntegrationTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('cleanupChoices')]
    public function test_wildcard_lifecycle_uses_independent_apex_and_wildcard_claims(bool $cleanup): void
    {
        $records = [];
        $missing = false;
        $apexTimeout = false;
        Http::preventStrayRequests();
        Http::fake(function (Request $request) use (&$records) {
            $url = $request->url();
            if (str_contains($url, '/tokens/verify')) {
                return Http::response(['success' => true, 'result' => ['id' => 'token', 'status' => 'active']]);
            }
            if (str_contains($url, '/dns_records')) {
                if ($request->method() === 'DELETE') {
                    $id = basename($url);
                    $records = array_values(array_filter($records, fn (array $record) => $record['id'] !== $id));

                    return Http::response(['success' => true, 'result' => ['id' => $id]]);
                }
                if ($request->method() === 'POST') {
                    $record = ['id' => 'record-'.count($records), ...$request->data()];
                    $records[] = $record;

                    return Http::response(['success' => true, 'result' => $record]);
                }

                return Http::response(['success' => true, 'result' => $records]);
            }

            return Http::response(['success' => true, 'result' => [[
                'id' => 'zone', 'name' => 'example.com', 'status' => 'active', 'paused' => false,
                'account' => ['id' => 'account', 'name' => 'Account'],
            ]]]);
        });
        $resolver = new class($missing, $apexTimeout) implements PublicDnsResolverContract
        {
            public function __construct(public bool &$missing, public bool &$apexTimeout) {}

            public function resolve(string $name, string $type): DnsLookupResult
            {
                if ($name === 'example.com' && $this->apexTimeout) {
                    throw new CloudflareTransportException('Resolver timeout');
                }

                return new DnsLookupResult($name, $type, $this->missing ? [] : ['192.0.2.10']);
            }
        };
        app()->instance(PublicDnsResolverContract::class, $resolver);
        $owner = Installation::query()->create([
            'name' => 'Installation', 'domain' => 'landings.test', 'origin_target' => '192.0.2.10',
        ]);
        $integration = $owner->cloudflareIntegrations()->create([
            'api_token' => 'test-token', 'token_fingerprint' => hash('sha256', 'test-token'),
            'status' => 'active', 'token_status' => 'active',
        ]);
        $manager = app(DomainManager::class);
        $manager->syncCloudflare($integration->id);
        $zone = $manager->zones($integration->id)->sole();
        $base = $manager->createCloudflare(null, 'example.com', $integration->id, $zone->id, wildcard: true);
        $this->assertDatabaseCount('cloudflare_domains', 2);
        foreach (CloudflareDomain::query()->with('records')->get() as $claim) {
            $this->assertCount(1, $claim->records);
            $this->assertSame($claim->hostname, $claim->records->sole()->name);
        }
        $this->assertNotNull($base->cloudflare_wildcard_domain_id);

        $result = $manager->reconcileCloudflare($base);
        $this->assertSame(2, $result->created);
        $this->assertSame(DomainStatus::Active, $manager->checkCloudflare($base)->status);
        $child = $manager->createSubdomain($base, null, 'offer');
        $this->assertSame(DomainStatus::Active, $manager->checkCloudflare($child)->status);

        // Losing the public DNS answer after verification must stop serving.
        $missing = true;
        $apexTimeout = true; // A wildcard mismatch must win over the other claim's timeout.
        $this->assertSame(DomainStatus::Drifted, $manager->checkCloudflare($base)->status);
        $this->assertSame(DomainStatus::Drifted, $child->refresh()->status);

        $manager->remove($child);
        $manager->remove($base, cleanupManagedRecords: $cleanup);
        $this->assertDatabaseCount('cloudflare_domains', 0);
        $this->assertCount($cleanup ? 0 : 2, $records);
    }

    public static function cleanupChoices(): array
    {
        return ['keep DNS' => [false], 'clean managed DNS' => [true]];
    }
}
