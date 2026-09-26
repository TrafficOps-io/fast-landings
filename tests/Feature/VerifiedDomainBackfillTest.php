<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class VerifiedDomainBackfillTest extends TestCase
{
    use RefreshDatabase;

    public function test_existing_active_domains_are_backfilled_as_verified_and_others_are_not(): void
    {
        $this->artisan('migrate:rollback', ['--step' => 1])->assertSuccessful();
        $this->assertFalse(DB::getSchemaBuilder()->hasColumn('domains', 'verified_at'));
        $active = $this->insertDomain('active.example.test', 'active');
        $pending = $this->insertDomain('pending.example.test', 'pending');
        $drifted = $this->insertDomain('drifted.example.test', 'drifted');

        $this->artisan('migrate')->assertSuccessful();

        $this->assertNotNull(DB::table('domains')->where('id', $active)->value('verified_at'));
        $this->assertNull(DB::table('domains')->where('id', $pending)->value('verified_at'));
        $this->assertNull(DB::table('domains')->where('id', $drifted)->value('verified_at'));
    }

    private function insertDomain(string $hostname, string $status): string
    {
        $id = (string) Str::ulid();
        DB::table('domains')->insert([
            'id' => $id,
            'hostname' => $hostname,
            'kind' => 'custom',
            'provider' => 'dns',
            'status' => $status,
            'is_primary' => false,
            'dns_target' => 'origin.fast-landings.test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }
}
