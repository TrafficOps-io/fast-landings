<?php

namespace Tests\Feature;

use App\Enums\DomainKind;
use App\Enums\DomainProvider;
use App\Enums\DomainStatus;
use App\Models\Domain;
use App\Models\Landing;
use App\Models\LandingRelease;
use App\Support\ServableProjection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * deploy/projection-sync.php publishes host symlinks from ServableProjection::rows().
 * Those rows must select exactly the domains that Domain::servable() serves.
 */
class ProjectionSyncPredicateTest extends TestCase
{
    use RefreshDatabase;

    public function test_projection_rows_select_the_same_domains_as_the_servable_scope(): void
    {
        $this->domain('active.example.test', $this->landing('Active', published: true, withRelease: true), DomainStatus::Active, verified: true);
        $this->domain('steady.example.test', $this->landing('Steady', published: true, withRelease: true), DomainStatus::Unreachable, verified: true);
        $this->domain('erroring.example.test', $this->landing('Erroring', published: true, withRelease: true), DomainStatus::Error, verified: true);
        $this->domain('drifted.example.test', $this->landing('Drifted', published: true, withRelease: true), DomainStatus::Drifted, verified: true);
        $this->domain('pending.example.test', $this->landing('Pending', published: true, withRelease: true), DomainStatus::Pending, verified: false);
        $this->domain('flaky.example.test', $this->landing('Flaky', published: true, withRelease: true), DomainStatus::Unreachable, verified: false);
        $this->domain('paused.example.test', $this->landing('Paused', published: false, withRelease: true), DomainStatus::Active, verified: true);
        $this->domain('empty.example.test', $this->landing('Empty', published: true, withRelease: false), DomainStatus::Active, verified: true);
        $this->domain('inventory.example.test', null, DomainStatus::Active, verified: true);

        $rows = ServableProjection::rows();

        $expected = ['active.example.test', 'erroring.example.test', 'steady.example.test'];
        $this->assertSame($expected, $rows->pluck('hostname')->sort()->values()->all());
        $this->assertSame($expected, Domain::query()->servable()->pluck('hostname')->sort()->values()->all());
        foreach ($rows as $row) {
            $release = Landing::query()->findOrFail($row->landing_id)->activeRelease;
            $this->assertSame($release->id, $row->release_id);
            $this->assertSame($release->storage_path, $row->storage_path);
        }
    }

    private function landing(string $name, bool $published, bool $withRelease): Landing
    {
        $landing = Landing::query()->create([
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'is_active' => $published,
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

    private function domain(string $hostname, ?Landing $landing, DomainStatus $status, bool $verified): Domain
    {
        return Domain::query()->create([
            'landing_id' => $landing?->id,
            'hostname' => $hostname,
            'kind' => DomainKind::Custom,
            'provider' => DomainProvider::Dns,
            'status' => $status,
            'verified_at' => $verified ? now() : null,
            'is_primary' => $landing !== null,
            'dns_target' => 'origin.fast-landings.test',
        ]);
    }
}
