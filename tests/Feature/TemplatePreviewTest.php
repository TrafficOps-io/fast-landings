<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Jobs\GenerateTemplatePreview;
use App\Livewire\Landings\FromTemplate;
use App\Livewire\Templates\Index;
use App\Models\LandingTemplate;
use App\Models\User;
use App\Services\Templates\TemplateArchiveService;
use App\Services\Templates\TemplatePreviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Process\Exceptions\ProcessFailedException;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class TemplatePreviewTest extends TestCase
{
    use RefreshDatabase;

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wl6Vh8AAAAASUVORK5CYII=';

    private User $administrator;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('landings');
        Queue::fake();
        config(['fast-landings.previews.enabled' => true]);
        $this->administrator = User::factory()->create(['role' => UserRole::Administrator, 'is_active' => true]);
        $this->actingAs($this->administrator);
    }

    public function test_import_queues_only_opted_in_templates_and_preserves_form_defaults(): void
    {
        $plain = $this->import();
        $this->assertNull($plain->preview_status);
        Queue::assertNothingPushed();
        $template = $this->import(preview: true)->fresh();
        $this->assertSame('queued', $template->preview_status);
        Queue::assertPushed(GenerateTemplatePreview::class, 1);
        $this->assertFalse(app(TemplatePreviewService::class)->request($template, force: true));
        Livewire::test(FromTemplate::class, ['template' => $template])->assertSet('values.title', 'Default title');
        $this->assertDatabaseCount('landings', 0);
        $this->assertDatabaseCount('landing_releases', 0);
    }

    public function test_generated_preview_uses_demo_values_and_copies_assets_without_publishing_a_landing(): void
    {
        $template = $this->import(preview: true)->fresh();
        $template->update(['asset_paths' => ['assets/theme.css']]);
        Storage::disk('landings')->put($template->storage_path.'/assets/theme.css', 'body { color: teal; }');
        Process::fake(function ($process) {
            $source = $process->command[2];
            $this->assertStringContainsString('<h1>Demo title</h1>', file_get_contents($source.'/index.html'));
            $this->assertSame('body { color: teal; }', file_get_contents($source.'/assets/theme.css'));
            file_put_contents($process->command[3], base64_decode(self::PNG));

            return Process::result();
        });
        $this->generate($template);
        $template->refresh();
        $this->assertSame('ready', $template->preview_status);
        Storage::disk('landings')->assertExists($template->preview_path);
        $this->assertSame([$template->preview_path], Storage::disk('landings')->allFiles($template->previewDirectory()));
        $this->assertDatabaseCount('landings', 0);
        $this->assertDatabaseCount('landing_releases', 0);
        Livewire::test(Index::class)->assertSee('Screenshot of Preview template')->assertSeeHtml('/preview?v=');
    }

    public function test_public_url_takes_precedence_and_is_linked_in_the_library(): void
    {
        $template = $this->import(preview: true, url: 'https://preview.example.com/demo')->fresh();
        Process::fake(function ($process) {
            $this->assertSame('--url', $process->command[2]);
            $this->assertSame('https://preview.example.com/demo', $process->command[3]);
            file_put_contents($process->command[4], base64_decode(self::PNG));

            return Process::result();
        });
        $this->generate($template);
        $this->assertSame('ready', $template->fresh()->preview_status);
        Livewire::test(Index::class)->assertSeeHtml('href="https://preview.example.com/demo"')->assertSee('Open preview');
    }

    public function test_backfill_handles_empty_preview_data_and_recovers_stalled_jobs(): void
    {
        config(['fast-landings.previews.enabled' => false]);
        $this->import();
        $template = $this->import(preview: true, data: '{}');
        $this->import(url: 'https://preview.example.com/demo');
        Queue::assertNothingPushed();
        config(['fast-landings.previews.enabled' => true]);
        $service = app(TemplatePreviewService::class);
        $this->assertSame(2, $service->requestMissing());
        $this->assertSame(0, $service->requestMissing());
        $oldToken = $template->fresh()->preview_token;
        $template->forceFill(['preview_requested_at' => now()->subMinutes(6)])->save();
        $this->assertSame(1, $service->requestMissing());
        $this->assertNotSame($oldToken, $template->fresh()->preview_token);
    }

    public function test_package_replacement_invalidates_old_jobs_and_removes_old_preview(): void
    {
        $template = $this->import(preview: true)->fresh();
        $oldToken = $template->preview_token;
        $oldPath = $template->previewDirectory().'/old.png';
        $template->forceFill(['preview_path' => $oldPath, 'preview_status' => 'ready'])->save();
        Storage::disk('landings')->put($oldPath, base64_decode(self::PNG));
        $archives = app(TemplateArchiveService::class);
        $renamed = $archives->update($template, ['name' => 'Renamed'], null, $this->administrator);
        $this->assertSame($oldPath, $renamed->preview_path);
        $updated = $archives->update($renamed, ['name' => 'Replaced'], $this->file(preview: true), $this->administrator)->fresh();
        $this->assertSame('queued', $updated->preview_status);
        $this->assertNotSame($oldToken, $updated->preview_token);
        $this->assertNull($updated->preview_path);
        Storage::disk('landings')->assertMissing($oldPath);
        Process::fake();
        (new GenerateTemplatePreview($template->id, $oldToken))->handle(app(TemplatePreviewService::class));
        (new GenerateTemplatePreview($template->id, $oldToken))->failed(new \RuntimeException);
        Process::assertNothingRan();
        $this->assertSame('queued', $updated->fresh()->preview_status);

        $withoutPreview = $archives->update($updated, ['name' => 'Plain'], $this->file(), $this->administrator)->fresh();
        $this->assertFalse($withoutPreview->hasPreviewSource());
        $this->assertNull($withoutPreview->preview_status);
    }

    public function test_render_finishing_after_package_replacement_cannot_restore_stale_image(): void
    {
        $template = $this->import(preview: true)->fresh();
        $oldPath = $template->previewDirectory().'/'.$template->preview_token.'.png';
        Process::fake(function ($process) use ($template) {
            app(TemplateArchiveService::class)->update($template, ['name' => 'Replacement'], $this->file(preview: true), $this->administrator);
            file_put_contents($process->command[3], base64_decode(self::PNG));

            return Process::result();
        });
        $this->generate($template);
        $this->assertSame('queued', $template->fresh()->preview_status);
        $this->assertNull($template->fresh()->preview_path);
        Storage::disk('landings')->assertMissing($oldPath);
        $this->assertSame([], Storage::disk('landings')->allFiles($template->previewDirectory()));
    }

    public function test_failure_cleans_working_files_and_preserves_previous_image_for_retry(): void
    {
        $template = $this->import(preview: true)->fresh();
        $oldPath = $template->previewDirectory().'/previous.png';
        $template->forceFill(['preview_path' => $oldPath, 'preview_status' => 'ready'])->save();
        Storage::disk('landings')->put($oldPath, base64_decode(self::PNG));
        $service = app(TemplatePreviewService::class);
        $this->assertTrue($service->request($template, force: true));
        $template->refresh();
        Process::fake(fn () => Process::result(exitCode: 1, errorOutput: 'Capture failed'));
        try {
            $this->generate($template);
            $this->fail('Expected renderer failure.');
        } catch (ProcessFailedException $exception) {
            (new GenerateTemplatePreview($template->id, $template->preview_token))->failed($exception);
        }
        $this->assertSame('failed', $template->fresh()->preview_status);
        $this->assertSame([$oldPath], Storage::disk('landings')->allFiles($template->previewDirectory()));
        $this->assertTrue($service->request($template, force: true));
    }

    public function test_preview_endpoint_requires_active_session_and_supports_cache_revalidation(): void
    {
        $template = $this->import(preview: true);
        $path = $template->previewDirectory().'/preview.png';
        $template->forceFill(['preview_path' => $path])->save();
        Storage::disk('landings')->put($path, base64_decode(self::PNG));
        $url = route('templates.preview', $template);
        auth()->logout();
        $this->get($url)->assertRedirect(route('login'));
        $user = User::factory()->create(['is_active' => true]);
        $response = $this->actingAs($user)->get($url)->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->withHeader('If-None-Match', $response->headers->get('ETag'))->get($url)->assertStatus(304);
        $this->flushHeaders();
        $user->update(['is_active' => false]);
        $this->get($url)->assertRedirect(route('login'));
        $this->get('http://untrusted.test/admin/templates/'.$template->id.'/preview')->assertNotFound();
    }

    public function test_only_administrators_can_refresh_and_deletion_cleans_cached_preview(): void
    {
        $template = $this->import(preview: true)->fresh();
        $path = $template->previewDirectory().'/preview.png';
        $template->forceFill(['preview_path' => $path, 'preview_status' => 'ready'])->save();
        Storage::disk('landings')->put($path, base64_decode(self::PNG));
        Livewire::actingAs(User::factory()->create(['role' => UserRole::Editor, 'is_active' => true]))
            ->test(Index::class)->call('refreshPreview', $template->id)->assertForbidden();
        $this->actingAs($this->administrator);
        app(TemplateArchiveService::class)->delete($template);
        Storage::disk('landings')->assertMissing($path);
        Process::fake();
        $this->generate($template);
        Process::assertNothingRan();
    }

    private function generate(LandingTemplate $template): void
    {
        (new GenerateTemplatePreview($template->id, $template->preview_token))->handle(app(TemplatePreviewService::class));
    }

    private function import(bool $preview = false, ?string $url = null, string $data = '{"title":"Demo title"}'): LandingTemplate
    {
        return app(TemplateArchiveService::class)->import($this->file($preview, $url, $data), $this->administrator);
    }

    private function file(bool $preview = false, ?string $url = null, string $data = '{"title":"Demo title"}'): UploadedFile
    {
        $source = '@template "Preview template"'.($url ? ' previewUrl="'.$url.'"' : '')."\n";
        if ($preview) {
            $source .= "@previewData\n{$data}\n@endpreviewData\n";
        }
        $source .= <<<'TPL'
@param title String = "Default title" required
@layout
<!doctype html><html><head><link rel="stylesheet" href="assets/theme.css"></head><body><h1>{{title}}</h1></body></html>
@endlayout
TPL;

        return UploadedFile::fake()->createWithContent('template.tpl', $source);
    }
}
