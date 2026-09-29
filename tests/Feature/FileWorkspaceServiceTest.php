<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Landing;
use App\Models\LandingRelease;
use App\Models\LandingTemplate;
use App\Models\User;
use App\Services\FileWorkspaceService;
use App\Services\LandingArchiveService;
use App\Services\Templates\TemplateArchiveService;
use App\Services\Templates\TemplateLandingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;
use TrafficOps\TemplateDsl\TemplateEngine;
use ZipArchive;

class FileWorkspaceServiceTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private FileWorkspaceService $files;

    private array $temporary = [];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('landings');
        config(['fast-landings.previews.enabled' => false, 'fast-landings.max_extracted_bytes' => 8 * 1024 * 1024]);
        $this->user = User::factory()->create(['role' => UserRole::Administrator]);
        $this->files = app(FileWorkspaceService::class);
    }

    protected function tearDown(): void
    {
        foreach ($this->temporary as $path) {
            @unlink($path);
        }
        parent::tearDown();
    }

    public function test_files_can_be_edited_created_renamed_uploaded_deleted_and_downloaded_without_changing_source(): void
    {
        $release = $this->release(['index.html' => '<h1>Original</h1>', 'assets/app.css' => 'body{}']);
        $id = $this->files->open($release, $this->user);
        $this->assertSame($release->id, $this->files->info($id, $this->user)['revision']);
        $this->files->write($id, 'index.html', '<h1>Edited</h1>', $this->user);
        $this->files->write($id, 'nested/app.js', 'console.log(1)', $this->user, true);
        $this->files->rename($id, 'nested/app.js', 'scripts/site.js', $this->user);
        $this->files->upload($id, 'assets/image.bin', UploadedFile::fake()->createWithContent('image.bin', "\x00\xff"), $this->user);
        $this->files->delete($id, 'assets/app.css', $this->user);

        $entries = collect($this->files->files($id, $this->user))->keyBy('path');
        $this->assertCount(3, $entries);
        $this->assertFalse($entries['assets/image.bin']['editable']);
        $this->assertTrue($entries['index.html']['editable']);
        $this->assertSame('console.log(1)', $this->files->read($id, 'scripts/site.js', $this->user));
        $this->assertSame("\x00\xff", file_get_contents($this->files->absolutePath($id, 'assets/image.bin', $this->user)));
        $this->assertSame('<h1>Original</h1>', Storage::disk('landings')->get($release->storage_path.'/index.html'));

        $archive = $this->files->archive($id, $this->user);
        $this->temporary[] = $archive;
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($archive));
        $this->assertSame(3, $zip->numFiles);
        $this->assertSame('<h1>Edited</h1>', $zip->getFromName('index.html'));
        $this->assertFalse($zip->getFromName('metadata.json'));
        $zip->close();
    }

    public function test_publish_creates_immutable_active_release_and_consumes_the_draft(): void
    {
        $release = $this->release();
        $id = $this->files->open($release, $this->user);
        $this->files->write($id, 'index.html', 'Updated', $this->user);
        $published = $this->files->publish($id, $this->user);

        $this->assertNotSame($release->id, $published->id);
        $this->assertTrue($published->is_active);
        $this->assertFalse($release->fresh()->is_active);
        $this->assertSame('Original', Storage::disk('landings')->get($release->storage_path.'/index.html'));
        $this->assertSame('Updated', Storage::disk('landings')->get($published->storage_path.'/index.html'));
        $this->assertSame([], Storage::disk('landings')->allFiles('_file_editor/'.$this->user->id.'/'.$id));
    }

    public function test_snapshot_prioritizes_the_entrypoint_and_bounds_combined_autocomplete_sources(): void
    {
        $id = $this->files->open($this->release(['index.html' => str_repeat('i', 32)]), $this->user);
        $this->files->write($id, 'a.txt', str_repeat('a', 2 * 1024 * 1024), $this->user, true);
        $this->files->write($id, 'b.TPL', str_repeat('b', 2 * 1024 * 1024 - 64), $this->user, true);
        $this->files->write($id, 'y.txt', str_repeat('y', 33), $this->user, true);
        $this->files->write($id, 'z.tpl.php', str_repeat('z', 32), $this->user, true);
        $this->files->write($id, 'app.js', 'const value = 1;', $this->user, true);
        $this->files->upload($id, 'binary.html', UploadedFile::fake()->createWithContent('binary.html', "\0\xff"), $this->user);
        $this->files->upload($id, 'large.html', UploadedFile::fake()->createWithContent('large.html', str_repeat('l', 3 * 1024 * 1024)), $this->user);

        $snapshot = $this->files->snapshot($id, $this->user);

        $this->assertSame($id, $snapshot['info']['id']);
        $this->assertCount(8, $snapshot['files']);
        $this->assertSame(['index.html', 'b.TPL', 'z.tpl.php'], array_keys($snapshot['sources']));
        $this->assertSame(str_repeat('i', 32), $snapshot['sources']['index.html']);
        $this->assertSame(2 * 1024 * 1024, array_sum(array_map('strlen', $snapshot['sources'])));
        $this->assertFalse(collect($snapshot['files'])->keyBy('path')['large.html']['editable']);
    }

    public function test_publishing_a_template_landing_draft_requires_explicit_detach_from_template(): void
    {
        $template = $this->template();
        $landing = app(TemplateLandingService::class)->create($template, ['name' => 'Generated', 'slug' => 'generated'], ['title' => 'Original'], [], $this->user);
        $release = $landing->activeRelease;
        $id = $this->files->open($release, $this->user);
        $this->files->write($id, 'index.html', '<h1>Hand edited</h1>', $this->user);

        $this->validation(fn () => $this->files->publish($id, $this->user), 'Detach from template');

        $this->assertSame($release->id, $landing->fresh()->activeRelease->id);
        $this->assertSame($template->id, $landing->fresh()->landing_template_id);
        $this->assertDatabaseCount('landing_releases', 1);
        $this->assertSame('<h1>Hand edited</h1>', $this->files->read($id, 'index.html', $this->user), 'The draft survives the refused publish.');

        $published = $this->files->publishDetachingFromTemplate($id, $this->user);
        $this->assertNull($published->landing_template_id);
        $this->assertNull($landing->fresh()->landing_template_id);
    }

    public function test_file_landing_draft_publishes_without_a_detach_confirmation(): void
    {
        $id = $this->files->open($this->release(), $this->user);
        $this->assertFalse($this->files->info($id, $this->user)['template_linked']);

        $published = $this->files->publish($id, $this->user);

        $this->assertTrue($published->is_active);
    }

    public function test_detach_from_template_turns_template_landing_into_file_landing_and_activating_restores_snapshot(): void
    {
        $template = $this->template();
        $landing = app(TemplateLandingService::class)->create($template, ['name' => 'Generated', 'slug' => 'generated'], ['title' => 'Original'], [], $this->user);
        $previous = $landing->activeRelease;
        $id = $this->files->open($previous, $this->user);
        $this->assertTrue($this->files->info($id, $this->user)['template_linked']);
        $this->files->write($id, 'index.html', '<h1>Hand edited</h1>', $this->user);
        $published = $this->files->publishDetachingFromTemplate($id, $this->user);

        $this->assertNull($published->landing_template_id);
        $this->assertNull($published->template_values);
        $this->assertNull($landing->fresh()->landing_template_id);
        $this->assertSame($template->id, $previous->fresh()->landing_template_id);
        $this->assertSame(['title' => 'Original'], $previous->fresh()->template_values);
        app(LandingArchiveService::class)->activate($landing, $previous);
        $this->assertSame($template->id, $landing->fresh()->landing_template_id);
        $this->assertSame(['title' => 'Original'], $landing->fresh()->template_values);
    }

    public function test_template_draft_can_change_an_include_and_its_reference_atomically(): void
    {
        $template = $this->template();
        $original = $template->storage_path;
        $id = $this->files->open($template, $this->user);
        $this->files->write($id, 'parts/heading.tpl', "@param heading String = \"New title\"\n", $this->user, true);
        $this->files->write($id, 'template.tpl', "@template \"Editable\"\n@include \"parts/heading.tpl\"\n@layout\n<h1>{{heading}}</h1>\n@endlayout\n", $this->user);
        $updated = $this->files->publish($id, $this->user);

        $this->assertSame($template->id, $updated->id);
        $this->assertNotSame($original, $updated->storage_path);
        $this->assertSame(['heading' => 'New title'], app(TemplateEngine::class)->defaults($updated->definition));
        Storage::disk('landings')->assertExists($updated->storage_path.'/parts/heading.tpl');
        Storage::disk('landings')->assertMissing($original.'/template.tpl');
    }

    public function test_invalid_template_publish_preserves_original_and_the_draft_for_correction(): void
    {
        $template = $this->template();
        $original = $template->storage_path;
        $id = $this->files->open($template, $this->user);
        $this->files->write($id, 'template.tpl', 'broken source', $this->user);
        $this->validation(fn () => $this->files->publish($id, $this->user));

        $this->assertSame($original, $template->fresh()->storage_path);
        Storage::disk('landings')->assertExists($original.'/template.tpl');
        $this->assertSame('broken source', $this->files->read($id, 'template.tpl', $this->user));
        $this->assertCount(1, Storage::disk('landings')->directories('_templates/packages'));
        $this->assertSame([], Storage::disk('landings')->directories('_templates/staging'));
    }

    public function test_server_built_template_archive_uses_extracted_limits_without_weakening_upload_limits(): void
    {
        config(['fast-landings.max_upload_kb' => 1]);
        $template = $this->template();
        $archives = app(TemplateArchiveService::class);
        $attributes = ['name' => $template->name, 'description' => $template->description];
        $source = Storage::disk('landings')->get($template->storage_path.'/template.tpl');
        $asset = str_repeat('body{}', 1024);
        $compressed = $this->zip(['template.tpl' => $source, 'assets/site.css' => $asset]);
        $this->assertLessThan(1024, $compressed->getSize());
        $template = $archives->update($template, $attributes, $compressed, $this->user);
        $originalPath = $template->storage_path;
        $id = $this->files->open($template, $this->user);
        $this->files->write($id, 'assets/site.css', $asset.' /* edited */', $this->user);
        $path = $this->files->archive($id, $this->user);
        $this->temporary[] = $path;
        $uncompressed = new UploadedFile($path, 'template.zip', 'application/zip', null, true);
        $this->assertGreaterThan(1024, $uncompressed->getSize());

        $this->validation(fn () => $archives->import($uncompressed, $this->user));
        $this->validation(fn () => $archives->update($template, $attributes, $uncompressed, $this->user));
        config(['fast-landings.max_extracted_bytes' => 1024]);
        $this->validation(fn () => $archives->update($template, $attributes, $uncompressed, $this->user, trustedWorkspaceArchive: true), 'larger than');
        $this->assertSame($originalPath, $template->fresh()->storage_path);

        config(['fast-landings.max_extracted_bytes' => 8 * 1024 * 1024]);
        $updated = $this->files->publish($id, $this->user);
        $this->assertNotSame($originalPath, $updated->storage_path);
        $this->assertSame($asset.' /* edited */', Storage::disk('landings')->get($updated->storage_path.'/assets/site.css'));
    }

    #[DataProvider('phpEntrypoints')]
    public function test_php_template_entrypoints_are_selected_and_included_in_autocomplete(string $entrypoint): void
    {
        $source = "@template \"PHP page\"\n@param title String = \"Hello\"\n@layout\n<h1>{{title}}</h1>\n@endlayout";
        $template = app(TemplateArchiveService::class)->import(UploadedFile::fake()->createWithContent($entrypoint, $source), $this->user);
        $id = $this->files->open($template, $this->user);
        $snapshot = $this->files->snapshot($id, $this->user);

        $this->assertSame($entrypoint, $snapshot['info']['entrypoint']);
        $this->assertSame([$entrypoint => $source], $snapshot['sources']);
    }

    public static function phpEntrypoints(): array
    {
        return [['index.tpl.php'], ['index.php']];
    }

    public function test_stale_template_draft_cannot_overwrite_a_published_package(): void
    {
        $template = $this->template();
        $first = $this->files->open($template, $this->user);
        $second = $this->files->open($template, $this->user);
        $current = $this->files->publish($first, $this->user);
        $this->validation(fn () => $this->files->publish($second, $this->user), 'changed since');
        $this->assertSame($current->storage_path, $template->fresh()->storage_path);
        $this->assertCount(1, Storage::disk('landings')->directories('_templates/packages'));
        $this->assertSame('template', $this->files->info($second, $this->user)['kind']);
    }

    public function test_stale_landing_draft_is_rejected_after_another_release_or_activation(): void
    {
        $release = $this->release();
        $first = $this->files->open($release, $this->user);
        $second = $this->files->open($release, $this->user);
        $current = $this->files->publish($first, $this->user);
        $this->validation(fn () => $this->files->publish($second, $this->user), 'changed since');
        $this->assertSame($current->id, $release->landing->fresh()->activeRelease->id);
        $this->assertDatabaseCount('landing_releases', 2);
        $this->assertCount(2, Storage::disk('landings')->directories($release->landing_id.'/releases'));
        $this->assertSame([], Storage::disk('landings')->directories($release->landing_id.'/staging'));
        $this->assertSame('Original', $this->files->read($second, 'index.html', $this->user));
    }

    public function test_draft_is_opened_from_the_active_release_only(): void
    {
        $old = $this->release();
        $active = app(LandingArchiveService::class)->deploy($old->landing, $this->zip(['index.html' => 'Current']), $this->user);

        $this->validation(fn () => $this->files->open($old, $this->user), 'Activate this release first');
        $this->assertSame([], Storage::disk('landings')->allFiles('_file_editor'), 'No draft is left behind for a refused release.');

        $id = $this->files->open($active, $this->user);
        $this->assertSame($active->id, $this->files->info($id, $this->user)['revision']);
        $this->assertSame('Current', $this->files->read($id, 'index.html', $this->user));
    }

    public function test_missing_landing_entrypoint_cannot_be_published(): void
    {
        $release = $this->release();
        $id = $this->files->open($release, $this->user);
        $this->files->rename($id, 'index.html', 'other.html', $this->user);
        $this->validation(fn () => $this->files->publish($id, $this->user), 'index.html');
        $this->assertTrue($release->fresh()->is_active);
        $this->assertSame('Original', $this->files->read($id, 'other.html', $this->user));
    }

    public function test_highly_compressible_edited_files_are_not_rejected_as_zip_bombs(): void
    {
        config(['fast-landings.max_expansion_ratio' => 2]);
        $id = $this->files->open($this->release(), $this->user);
        $this->files->write($id, 'index.html', str_repeat('A', 2 * 1024 * 1024), $this->user);
        $published = $this->files->publish($id, $this->user);
        $this->assertSame(2 * 1024 * 1024, $published->size_bytes);
    }

    #[DataProvider('unsafePaths')]
    public function test_unsafe_relative_file_paths_are_rejected(string $path): void
    {
        $id = $this->files->open($this->release(), $this->user);
        $this->validation(fn () => $this->files->write($id, $path, 'outside', $this->user, true));
        $this->validation(fn () => $this->files->absolutePath($id, $path, $this->user));
        $this->assertSame('Original', $this->files->read($id, 'index.html', $this->user));
    }

    public static function unsafePaths(): array
    {
        return array_map(fn ($path) => [$path], ['../outside', 'a/../../outside', '/outside', 'a//b', 'a/./b', 'a\\b', "a\0b", "a\nb", 'C:/outside', '']);
    }

    public function test_collisions_and_silent_upload_overwrite_are_rejected(): void
    {
        $id = $this->files->open($this->release(['index.html' => 'Original', 'assets/site.css' => 'body{}', 'café.txt' => 'unicode']), $this->user);
        foreach (['INDEX.html', 'index.html/child', 'assets', 'Assets/another.css', "cafe\u{0301}.txt"] as $path) {
            $this->validation(fn () => $this->files->write($id, $path, 'bad', $this->user, true));
        }
        $this->validation(fn () => $this->files->rename($id, 'assets/site.css', 'index.html', $this->user));
        $this->validation(fn () => $this->files->upload($id, 'index.html', UploadedFile::fake()->createWithContent('index.html', 'Overwrite'), $this->user));
        $this->assertSame('Original', $this->files->read($id, 'index.html', $this->user));
    }

    public function test_binary_and_oversized_text_cannot_be_edited_but_remain_downloadable(): void
    {
        $id = $this->files->open($this->release(), $this->user);
        foreach (["\0binary", "\xff", str_repeat('a', 2 * 1024 * 1024 + 1)] as $content) {
            $this->validation(fn () => $this->files->write($id, 'index.html', $content, $this->user));
        }
        $binary = "\x00\xff";
        $this->files->upload($id, 'asset.bin', UploadedFile::fake()->createWithContent('asset.bin', $binary), $this->user);
        $this->validation(fn () => $this->files->read($id, 'asset.bin', $this->user));
        $this->validation(fn () => $this->files->write($id, 'asset.bin', 'text', $this->user));
        $this->assertSame($binary, file_get_contents($this->files->absolutePath($id, 'asset.bin', $this->user)));
    }

    public function test_limits_are_checked_before_mutations(): void
    {
        $id = $this->files->open($this->release(), $this->user);
        config(['fast-landings.max_files' => 1]);
        $this->validation(fn () => $this->files->write($id, 'second.txt', 'another', $this->user, true));
        config(['fast-landings.max_extracted_bytes' => 8]);
        $this->validation(fn () => $this->files->write($id, 'index.html', '123456789', $this->user));
        $this->assertCount(1, $this->files->files($id, $this->user));
        $this->assertSame('Original', $this->files->read($id, 'index.html', $this->user));
    }

    public function test_workspace_is_owner_scoped_and_active_status_and_template_role_are_rechecked(): void
    {
        $id = $this->files->open($this->release(), $this->user);
        $other = User::factory()->create(['role' => UserRole::Administrator]);
        $this->httpFailure(fn () => $this->files->read($id, 'index.html', $other), 404);
        $this->user->update(['is_active' => false]);
        $this->httpFailure(fn () => $this->files->files($id, $this->user), 403);
        $this->user->update(['is_active' => true]);
        $templateId = $this->files->open($this->template(), $this->user);
        $this->user->update(['role' => UserRole::Editor]);
        $this->httpFailure(fn () => $this->files->files($templateId, $this->user), 403);
        $this->assertCount(1, $this->files->files($id, $this->user));
    }

    public function test_symlink_cannot_escape_a_workspace(): void
    {
        $id = $this->files->open($this->release(), $this->user);
        $outside = tempnam(sys_get_temp_dir(), 'files-outside-');
        $this->temporary[] = $outside;
        file_put_contents($outside, 'secret');
        $link = Storage::disk('landings')->path('_file_editor/'.$this->user->id.'/'.$id.'/content/link.txt');
        symlink($outside, $link);
        try {
            $this->validation(fn () => $this->files->absolutePath($id, 'link.txt', $this->user));
            $this->validation(fn () => $this->files->files($id, $this->user));
            $this->validation(fn () => $this->files->publish($id, $this->user));
        } finally {
            unlink($link);
        }
        $this->assertSame('secret', file_get_contents($outside));
    }

    public function test_discard_removes_only_the_requested_draft(): void
    {
        $release = $this->release();
        $first = $this->files->open($release, $this->user);
        $second = $this->files->open($release, $this->user);
        $this->files->discard($first, $this->user);
        $this->httpFailure(fn () => $this->files->info($first, $this->user), 404);
        $this->assertSame('Original', $this->files->read($second, 'index.html', $this->user));
        Storage::disk('landings')->assertExists($release->storage_path.'/index.html');
    }

    public function test_pruning_removes_idle_drafts_and_preserves_current_or_locked_drafts(): void
    {
        $release = $this->release();
        $expired = $this->files->open($release, $this->user);
        $current = $this->files->open($release, $this->user);
        $busy = $this->files->open($release, $this->user);
        $base = '_file_editor/'.$this->user->id.'/';
        $incomplete = $base.Str::ulid();
        Storage::disk('landings')->put($incomplete.'/content/index.html', 'Interrupted copy');
        touch(Storage::disk('landings')->path($incomplete), now()->subHours(25)->timestamp);
        touch(Storage::disk('landings')->path($base.$expired.'/lock'), now()->subHours(25)->timestamp);
        touch(Storage::disk('landings')->path($base.$busy.'/lock'), now()->subHours(25)->timestamp);
        $lock = fopen(Storage::disk('landings')->path($base.$busy.'/lock'), 'r+');
        flock($lock, LOCK_EX);
        try {
            $this->artisan('fast-landings:prune-staging')->assertSuccessful();
            $this->httpFailure(fn () => $this->files->info($expired, $this->user), 404);
            Storage::disk('landings')->assertMissing($incomplete.'/content/index.html');
            Storage::disk('landings')->assertExists($base.$busy.'/metadata.json');
            $this->assertSame('Original', $this->files->read($current, 'index.html', $this->user));
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function release(array $files = ['index.html' => 'Original']): LandingRelease
    {
        $landing = Landing::query()->create(['name' => 'Editable', 'slug' => 'editable-'.Str::lower(Str::random(8)), 'is_active' => true]);

        return app(LandingArchiveService::class)->deploy($landing, $this->zip($files), $this->user);
    }

    private function template(): LandingTemplate
    {
        $source = "@template \"Editable\"\n@param title String = \"Original\"\n@layout\n<h1>{{title}}</h1>\n@endlayout\n";

        return app(TemplateArchiveService::class)->import(UploadedFile::fake()->createWithContent('template.tpl', $source), $this->user);
    }

    private function zip(array $files): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'file-editor-test-');
        $this->temporary[] = $path;
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        foreach ($files as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();

        return new UploadedFile($path, 'landing.zip', 'application/zip', null, true);
    }

    private function validation(callable $action, ?string $message = null): void
    {
        try {
            $action();
            $this->fail('The invalid operation was accepted.');
        } catch (ValidationException $exception) {
            if ($message !== null) {
                $this->assertStringContainsString($message, $exception->getMessage());
            }
        }
    }

    private function httpFailure(callable $action, int $status): void
    {
        try {
            $action();
            $this->fail('The forbidden operation was accepted.');
        } catch (HttpException $exception) {
            $this->assertSame($status, $exception->getStatusCode());
        }
    }
}
