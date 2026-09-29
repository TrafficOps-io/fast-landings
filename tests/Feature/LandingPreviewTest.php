<?php

namespace Tests\Feature;

use App\Jobs\GenerateLandingPreview;
use App\Livewire\Landings\Index;
use App\Models\Landing;
use App\Models\LandingRelease;
use App\Models\User;
use App\Services\LandingArchiveService;
use App\Services\LandingPreviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class LandingPreviewTest extends TestCase
{
    use RefreshDatabase;

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wl6Vh8AAAAASUVORK5CYII=';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('landings');
        Queue::fake();
        config(['fast-landings.previews.enabled' => true]);
    }

    public function test_missing_previews_are_queued_once_and_stalled_requests_are_recoverable(): void
    {
        $release = $this->release();
        $service = app(LandingPreviewService::class);
        $this->assertSame(1, $service->requestMissing());
        $this->assertSame(0, $service->requestMissing());
        $this->assertFalse($service->request($release, force: true));
        Queue::assertPushed(GenerateLandingPreview::class, 1);
        $oldToken = $release->fresh()->preview_token;
        $release->forceFill(['preview_requested_at' => now()->subMinutes(6)])->save();
        $this->assertSame(1, $service->requestMissing());
        $this->assertNotSame($oldToken, $release->fresh()->preview_token);
    }

    public function test_renderer_saves_an_image_outside_the_public_release_and_cards_use_it(): void
    {
        $release = $this->release();
        $service = app(LandingPreviewService::class);
        $service->request($release);
        $release->refresh();
        Process::fake(function ($process) use ($release) {
            $this->assertSame(Storage::disk('landings')->path($release->storage_path), $process->command[2]);
            file_put_contents($process->command[3], base64_decode(self::PNG));

            return Process::result();
        });

        (new GenerateLandingPreview($release->id, $release->preview_token))->handle($service);
        $release->refresh();
        $this->assertSame('ready', $release->preview_status);
        $this->assertStringStartsWith($release->previewDirectory().'/', $release->preview_path);
        Storage::disk('landings')->assertExists($release->preview_path);
        $this->assertNotNull($release->preview_generated_at);
        Livewire::actingAs(User::factory()->create())->test(Index::class)
            ->assertSee('Screenshot of Preview landing')->assertSeeHtml('/preview?v=');
    }

    public function test_php_entrypoints_never_queue_source_screenshots(): void
    {
        $release = $this->release();
        $release->update(['entrypoint' => 'index.php']);
        $service = app(LandingPreviewService::class);

        $this->assertFalse($service->request($release, force: true));
        $this->assertSame(0, $service->requestMissing());
        Queue::assertNothingPushed();
        Livewire::actingAs(User::factory()->create())->test(Index::class)
            ->assertSee('Open the landing to view this PHP page')
            ->assertDontSee('Refresh preview');
    }

    public function test_old_or_deleted_jobs_do_not_render_or_overwrite_new_requests(): void
    {
        $release = $this->release();
        $service = app(LandingPreviewService::class);
        $service->request($release);
        Process::fake();
        (new GenerateLandingPreview($release->id, 'old-token'))->handle($service);
        (new GenerateLandingPreview($release->id, 'old-token'))->failed(new \RuntimeException);
        $this->assertSame('queued', $release->fresh()->preview_status);
        $token = $release->fresh()->preview_token;
        $release->delete();
        (new GenerateLandingPreview($release->id, $token))->handle($service);
        Process::assertNothingRan();
    }

    public function test_failed_refresh_preserves_last_image_and_allows_retry(): void
    {
        $release = $this->release();
        $release->forceFill(['preview_path' => $release->previewDirectory().'/previous.png', 'preview_status' => 'ready'])->save();
        Storage::disk('landings')->put($release->preview_path, base64_decode(self::PNG));
        $service = app(LandingPreviewService::class);
        $service->request($release, force: true);
        (new GenerateLandingPreview($release->id, $release->fresh()->preview_token))->failed(new \RuntimeException);
        $this->assertSame('failed', $release->fresh()->preview_status);
        Storage::disk('landings')->assertExists($release->preview_path);
        $this->assertTrue($service->request($release, force: true));
    }

    public function test_preview_endpoint_requires_an_active_panel_session_and_revalidates_images(): void
    {
        $release = $this->release();
        $release->forceFill(['preview_path' => $release->previewDirectory().'/preview.png', 'preview_status' => 'ready'])->save();
        Storage::disk('landings')->put($release->preview_path, base64_decode(self::PNG));
        $url = route('landings.preview', $release);
        $this->get($url)->assertRedirect(route('login'));
        $user = User::factory()->create();
        $response = $this->actingAs($user)->get($url)->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        $this->withHeader('If-None-Match', $response->headers->get('ETag'))->get($url)->assertStatus(304);
        $this->flushHeaders();
        $user->update(['is_active' => false]);
        $this->get($url)->assertRedirect(route('login'));
        $this->get('http://untrusted.test/admin/landing-releases/'.$release->id.'/preview')->assertNotFound();
    }

    public function test_reactivation_reuses_cached_screenshot_and_deleting_release_removes_it(): void
    {
        $release = $this->release();
        $release->forceFill(['preview_path' => $release->previewDirectory().'/preview.png', 'preview_status' => 'ready'])->save();
        Storage::disk('landings')->put($release->preview_path, base64_decode(self::PNG));
        $release->update(['is_active' => false]);
        $other = $this->release($release->landing);
        $archives = app(LandingArchiveService::class);
        $archives->activate($release->landing, $release);
        $this->assertSame($release->id, $release->landing->fresh()->activeRelease->id);
        Queue::assertNothingPushed();
        $archives->activate($release->landing, $other);
        $archives->delete($release->fresh());
        Storage::disk('landings')->assertMissing($release->preview_path);
    }

    private function release(?Landing $landing = null): LandingRelease
    {
        $landing ??= Landing::create(['name' => 'Preview landing', 'slug' => 'preview-'.Str::lower(Str::random(8)), 'is_active' => true]);
        $release = $landing->releases()->create([
            'original_name' => 'landing.zip', 'storage_path' => $landing->id.'/releases/'.Str::ulid(),
            'entrypoint' => 'index.html', 'size_bytes' => 20, 'file_count' => 1, 'checksum' => hash('sha256', Str::random()),
            'is_active' => true, 'activated_at' => now(),
        ]);
        Storage::disk('landings')->put($release->storage_path.'/index.html', '<h1>Preview</h1>');

        return $release;
    }
}
