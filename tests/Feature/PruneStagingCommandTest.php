<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PruneStagingCommandTest extends TestCase
{
    public function test_it_removes_only_abandoned_staging_directories(): void
    {
        Storage::fake('landings');
        config(['fast-landings.storage_disk' => 'landings']);
        $disk = Storage::disk('landings');
        $old = 'landing-old/staging/release-old';
        $fresh = 'landing-new/staging/release-new';
        $disk->put($old.'/index.html', 'old');
        $disk->put($fresh.'/index.html', 'fresh');
        touch($disk->path($old), now()->subDays(2)->getTimestamp());

        $this->artisan('fast-landings:prune-staging', ['--hours' => 24])
            ->expectsOutput('Removed 1 abandoned staging directory.')
            ->assertSuccessful();

        $disk->assertMissing($old.'/index.html');
        $disk->assertExists($fresh.'/index.html');
    }

    public function test_it_rejects_unsafe_retention_values(): void
    {
        $this->artisan('fast-landings:prune-staging', ['--hours' => 0])
            ->expectsOutput('The --hours value must be an integer between 1 and 720.')
            ->assertFailed();
    }
}
