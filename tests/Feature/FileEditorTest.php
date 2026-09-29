<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Livewire\Files\Editor;
use App\Livewire\Landings\Show;
use App\Models\Landing;
use App\Models\LandingRelease;
use App\Models\LandingTemplate;
use App\Models\User;
use App\Services\LandingArchiveService;
use App\Services\Templates\TemplateArchiveService;
use App\Services\Templates\TemplateLandingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Tests\TestCase;
use ZipArchive;

class FileEditorTest extends TestCase
{
    use RefreshDatabase;

    private User $administrator;

    /** @var list<string> */
    private array $temporaryArchives = [];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('landings');
        config([
            'fast-landings.storage_disk' => 'landings',
            'fast-landings.max_files' => 100,
            'fast-landings.max_extracted_bytes' => 1024 * 1024,
            'fast-landings.previews.enabled' => false,
        ]);
        $this->administrator = User::factory()->create(['role' => UserRole::Administrator]);
        $this->actingAs($this->administrator);
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryArchives as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        parent::tearDown();
    }

    public function test_editors_can_edit_landing_files_but_template_files_require_an_administrator(): void
    {
        $template = $this->template();
        $release = $this->release();
        $editor = User::factory()->create();
        $this->actingAs($editor);

        $this->get(route('landings.files', $release))->assertOk();
        $this->get(route('templates.files', $template))->assertForbidden();
        Livewire::test(Editor::class, ['template' => $template])->assertForbidden();

        auth()->logout();
        $this->get(route('landings.files', $release))->assertRedirect(route('login'));
        $this->get(route('templates.files', $template))->assertRedirect(route('login'));

        $this->actingAs(User::factory()->create(['is_active' => false]));
        $this->get(route('landings.files', $release))->assertRedirect(route('login'));
    }

    public function test_landing_file_changes_are_drafted_and_published_as_a_new_immutable_release(): void
    {
        $release = $this->release();
        $landing = $release->landing;
        $originalHtml = Storage::disk('landings')->get($release->storage_path.'/index.html');
        $editor = Livewire::test(Editor::class, ['release' => $release]);

        $editor->call('selectFile', 'index.html')
            ->call('saveFile', '<h1>Edited in the browser</h1>')
            ->assertHasNoErrors()
            ->set('newPath', 'assets/draft.css')
            ->set('newContent', 'body { color: teal; }')
            ->call('createFile')
            ->assertHasNoErrors()
            ->call('selectFile', 'assets/draft.css')
            ->set('renamePath', 'assets/theme.css')
            ->call('renameFile')
            ->assertHasNoErrors()
            ->set('newPath', 'delete-me.txt')
            ->set('newContent', 'Temporary file')
            ->call('createFile')
            ->assertHasNoErrors()
            ->call('selectFile', 'delete-me.txt')
            ->call('deleteFile')
            ->assertHasNoErrors();

        $this->assertSame($release->id, $landing->fresh()->activeRelease->id);
        $this->assertSame($originalHtml, Storage::disk('landings')->get($release->storage_path.'/index.html'));
        Storage::disk('landings')->assertMissing($release->storage_path.'/assets/theme.css');
        $this->assertDatabaseCount('landing_releases', 1);

        $editor->call('publish')->assertHasNoErrors();

        $published = $landing->fresh()->activeRelease;
        $this->assertNotSame($release->id, $published->id);
        $this->assertNotSame($release->checksum, $published->checksum);
        $this->assertSame('<h1>Edited in the browser</h1>', Storage::disk('landings')->get($published->storage_path.'/index.html'));
        $this->assertSame('body { color: teal; }', Storage::disk('landings')->get($published->storage_path.'/assets/theme.css'));
        Storage::disk('landings')->assertMissing($published->storage_path.'/assets/draft.css');
        Storage::disk('landings')->assertMissing($published->storage_path.'/delete-me.txt');
        $this->assertSame($originalHtml, Storage::disk('landings')->get($release->storage_path.'/index.html'));
        $this->assertFalse($release->fresh()->is_active);
        $this->assertDatabaseCount('landing_releases', 2);
    }

    public function test_template_file_publication_reparses_source_without_modifying_existing_landings(): void
    {
        $template = $this->template();
        $landing = app(TemplateLandingService::class)->create($template, [
            'name' => 'From template', 'slug' => 'from-template',
        ], ['title' => 'Original title'], [], $this->administrator);
        $release = $landing->activeRelease;
        $originalHtml = Storage::disk('landings')->get($release->storage_path.'/index.html');
        $source = $this->source('Updated source');

        Livewire::test(Editor::class, ['template' => $template])
            ->call('selectFile', 'template.tpl')
            ->call('saveFile', $source)
            ->assertHasNoErrors()
            ->set('newPath', 'assets/theme.css')
            ->set('newContent', 'h1 { color: blue; }')
            ->call('createFile')
            ->call('publish')
            ->assertHasNoErrors();

        $template->refresh();
        $this->assertSame($source, Storage::disk('landings')->get($template->storage_path.'/template.tpl'));
        $this->assertContains('assets/theme.css', $template->asset_paths);
        $this->assertStringContainsString('Updated source', json_encode($template->definition));
        $this->assertSame($originalHtml, Storage::disk('landings')->get($release->storage_path.'/index.html'));
        $this->assertSame($release->id, $landing->fresh()->activeRelease->id);
        $this->assertDatabaseCount('landing_releases', 1);
    }

    public function test_template_landing_editor_warns_that_publishing_detaches_from_template(): void
    {
        $template = $this->template();
        $landing = app(TemplateLandingService::class)->create($template, [
            'name' => 'Template landing', 'slug' => 'template-landing',
        ], ['title' => 'Snapshot title'], [], $this->administrator);
        $release = $landing->activeRelease;

        $editor = Livewire::test(Editor::class, ['release' => $release])
            ->assertSee('Detach from template')
            ->call('selectFile', 'index.html')
            ->call('saveFile', '<h1>Custom HTML</h1>');

        $editor->call('publish')->assertHasErrors('detachFromTemplate');
        $this->assertSame($release->id, $landing->fresh()->activeRelease->id);
        $this->assertSame($template->id, $landing->fresh()->landing_template_id);

        $editor->call('publishDetachingFromTemplate')->assertHasNoErrors();
        $this->assertNotSame($release->id, $landing->fresh()->activeRelease->id);
        $this->assertNull($landing->fresh()->landing_template_id);
    }

    public function test_files_of_an_inactive_release_cannot_be_drafted_until_it_is_activated(): void
    {
        $old = $this->release();
        $landing = $old->landing;
        $active = app(LandingArchiveService::class)->deploy($landing, $this->archive(['index.html' => '<h1>Current</h1>']), $this->administrator);

        $this->get(route('landings.files', $old))
            ->assertRedirect(route('landings.show', $landing))
            ->assertSessionHasErrors(['release' => 'Activate this release first.']);
        $this->assertDatabaseCount('landing_releases', 2);

        Livewire::test(Show::class, ['landing' => $landing])
            ->assertSee('Activate this release first')
            ->assertSeeHtml(route('landings.files', $active))
            ->assertDontSeeHtml(route('landings.files', $old));
    }

    public function test_file_landing_editor_does_not_mention_detaching(): void
    {
        Livewire::test(Editor::class, ['release' => $this->release()])
            ->assertDontSee('Detach from template')
            ->assertSee('Save and activate');
    }

    public function test_detach_from_template_clears_the_template_snapshot_only_on_the_new_release(): void
    {
        $template = $this->template();
        $landing = app(TemplateLandingService::class)->create($template, [
            'name' => 'Template snapshot', 'slug' => 'template-snapshot',
        ], ['title' => 'Snapshot title'], [], $this->administrator);
        $release = $landing->activeRelease;

        Livewire::test(Editor::class, ['release' => $release])
            ->call('selectFile', 'index.html')
            ->call('saveFile', '<h1>Custom HTML</h1>')
            ->call('publishDetachingFromTemplate')
            ->assertHasNoErrors();

        $landing->refresh();
        $this->assertNull($landing->landing_template_id);
        $this->assertNull($landing->template_values);
        $this->assertNull($landing->activeRelease->landing_template_id);
        $this->assertNull($landing->activeRelease->template_values);
        $this->assertSame($template->id, $release->fresh()->landing_template_id);
        $this->assertSame('Snapshot title', $release->fresh()->template_values['title']);
    }

    public function test_uploaded_binary_files_can_be_downloaded_and_are_included_in_the_archive(): void
    {
        $release = $this->release();
        $binary = "\x00\xFF\x01binary\x00content";
        $editor = Livewire::test(Editor::class, ['release' => $release])
            ->set('fileUpload', UploadedFile::fake()->createWithContent('document.bin', $binary))
            ->set('uploadPath', 'downloads/document.bin')
            ->call('uploadFile')
            ->assertHasNoErrors();
        $workspace = $editor->get('workspaceId');

        $download = $this->get(route('files.show', [
            'workspace' => $workspace, 'path' => 'downloads/document.bin', 'download' => 1,
        ]))->assertOk()->assertDownload('document.bin');
        $this->assertSame($binary, $this->responseFile($download));

        $archive = $this->get(route('files.download', $workspace))->assertOk()->assertDownload();
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($archive->baseResponse->getFile()->getPathname()));
        try {
            $this->assertSame($binary, $zip->getFromName('downloads/document.bin'));
            $this->assertSame('<h1>Original landing</h1>', $zip->getFromName('index.html'));
        } finally {
            $zip->close();
        }
    }

    public function test_active_source_preview_is_plain_text_sandboxed_and_private(): void
    {
        $release = $this->release();
        $editor = Livewire::test(Editor::class, ['release' => $release])
            ->set('newPath', 'image.svg')
            ->set('newContent', '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(document.cookie)"/>')
            ->call('createFile')
            ->assertHasNoErrors();
        $workspace = $editor->get('workspaceId');

        foreach (['index.html', 'image.svg'] as $path) {
            $response = $this->get(route('files.show', ['workspace' => $workspace, 'path' => $path]))
                ->assertOk()
                ->assertHeader('X-Content-Type-Options', 'nosniff');
            $this->assertStringStartsWith('text/plain', $response->headers->get('Content-Type'));
            $this->assertStringContainsString('sandbox', $response->headers->get('Content-Security-Policy'));
            $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        }
    }

    public function test_workspaces_are_private_to_the_user_who_opened_them(): void
    {
        $release = $this->release();
        $workspace = Livewire::test(Editor::class, ['release' => $release])->get('workspaceId');
        $fileUrl = route('files.show', ['workspace' => $workspace, 'path' => 'index.html']);
        $archiveUrl = route('files.download', $workspace);

        $this->actingAs(User::factory()->create());
        $this->assertContains($this->get($fileUrl)->status(), [403, 404]);
        $this->assertContains($this->get($archiveUrl)->status(), [403, 404]);

        auth()->logout();
        $this->get($fileUrl)->assertRedirect(route('login'));
        $this->get($archiveUrl)->assertRedirect(route('login'));
    }

    public function test_file_actions_reauthorize_an_open_editor_after_account_access_changes(): void
    {
        $template = $this->template();
        $editor = Livewire::test(Editor::class, ['template' => $template]);
        User::query()->whereKey($this->administrator->id)->update(['role' => UserRole::Editor]);

        $editor->call('saveFile', $this->source('Unauthorized edit'))->assertForbidden();

        $release = $this->release();
        $editor = Livewire::test(Editor::class, ['release' => $release]);
        User::query()->whereKey($this->administrator->id)->update(['is_active' => false]);

        $editor->call('saveFile', '<h1>Unauthorized edit</h1>')->assertForbidden();
        $this->assertSame('<h1>Original landing</h1>', Storage::disk('landings')->get($release->storage_path.'/index.html'));
    }

    public function test_path_traversal_is_rejected_by_both_file_actions_and_downloads(): void
    {
        $release = $this->release();
        $editor = Livewire::test(Editor::class, ['release' => $release]);
        $workspace = $editor->get('workspaceId');

        foreach (['../outside.txt', '/outside.txt', 'assets/../../outside.txt', 'assets\\..\\outside.txt'] as $path) {
            $editor->set('newPath', $path)
                ->set('newContent', 'Must not escape')
                ->call('createFile')
                ->assertHasErrors();
            $this->get(route('files.show', ['workspace' => $workspace, 'path' => $path, 'download' => 1]))->assertNotFound();
        }

        $this->assertSame('<h1>Original landing</h1>', Storage::disk('landings')->get($release->storage_path.'/index.html'));
        $this->assertDatabaseCount('landing_releases', 1);
    }

    public function test_a_stale_file_editor_cannot_replace_a_more_recent_release(): void
    {
        $release = $this->release();
        $editor = Livewire::test(Editor::class, ['release' => $release])
            ->call('selectFile', 'index.html')
            ->call('saveFile', '<h1>Stale edit</h1>')
            ->assertHasNoErrors();
        $newer = app(LandingArchiveService::class)->deploy(
            $release->landing,
            $this->archive(['index.html' => '<h1>Newer upload</h1>']),
            $this->administrator,
        );

        $editor->call('publish')->assertHasErrors();

        $this->assertSame($newer->id, $release->landing->fresh()->activeRelease->id);
        $this->assertSame('<h1>Newer upload</h1>', Storage::disk('landings')->get($newer->storage_path.'/index.html'));
        $this->assertDatabaseCount('landing_releases', 2);
    }

    public function test_discarding_changes_leaves_source_files_untouched_and_revokes_workspace_downloads(): void
    {
        $release = $this->release();
        $editor = Livewire::test(Editor::class, ['release' => $release])
            ->call('selectFile', 'index.html')
            ->call('saveFile', '<h1>Discarded edit</h1>')
            ->assertHasNoErrors();
        $workspace = $editor->get('workspaceId');

        $editor->call('discard')->assertHasNoErrors();

        $this->assertSame('<h1>Original landing</h1>', Storage::disk('landings')->get($release->storage_path.'/index.html'));
        $this->assertDatabaseCount('landing_releases', 1);
        $this->get(route('files.download', $workspace))->assertNotFound();
    }

    private function template(): LandingTemplate
    {
        return app(TemplateArchiveService::class)->import(
            UploadedFile::fake()->createWithContent('template.tpl', $this->source()),
            $this->administrator,
        );
    }

    private function source(string $defaultTitle = 'Original title'): string
    {
        return "@template \"Editable template\"\n@param title String = \"{$defaultTitle}\"\n@layout\n<h1>{{title}}</h1>\n@endlayout\n";
    }

    private function release(): LandingRelease
    {
        $landing = Landing::query()->create([
            'name' => 'Editable landing', 'slug' => 'editable-landing', 'is_active' => true,
        ]);

        return app(LandingArchiveService::class)->deploy($landing, $this->archive([
            'index.html' => '<h1>Original landing</h1>',
            'assets/original.css' => 'body { margin: 0; }',
        ]), $this->administrator);
    }

    private function archive(array $files): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'file-editor-test-');
        $this->temporaryArchives[] = $path;
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        foreach ($files as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();

        return new UploadedFile($path, 'landing.zip', 'application/zip', null, true);
    }

    private function responseFile(TestResponse $response): string
    {
        $this->assertInstanceOf(BinaryFileResponse::class, $response->baseResponse);

        return file_get_contents($response->baseResponse->getFile()->getPathname());
    }
}
