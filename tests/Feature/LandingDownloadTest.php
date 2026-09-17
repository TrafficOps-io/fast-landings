<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Livewire\Landings\Index;
use App\Livewire\Landings\Show;
use App\Models\Landing;
use App\Models\LandingRelease;
use App\Models\User;
use App\Services\FileWorkspaceService;
use App\Services\LandingArchiveService;
use App\Services\LandingRuntimeCompiler;
use App\Services\Templates\TemplateArchiveService;
use App\Services\Templates\TemplateLandingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Tests\TestCase;
use ZipArchive;

class LandingDownloadTest extends TestCase
{
    use RefreshDatabase;

    private User $editor;

    private User $uploader;

    /** @var list<string> */
    private array $temporaryFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('landings');
        Queue::fake();
        config(['fast-landings.storage_disk' => 'landings']);
        $this->editor = User::factory()->create(['role' => UserRole::Editor, 'is_active' => true]);
        $this->uploader = User::factory()->create(['role' => UserRole::Administrator, 'is_active' => true]);
        $this->actingAs($this->editor);
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        parent::tearDown();
    }

    public function test_uploaded_landing_download_preserves_deployed_paths_and_file_bytes(): void
    {
        $files = [
            'index.php' => '<?php throw new RuntimeException("Do not execute while downloading");',
            'success.php' => '<?php echo htmlspecialchars($_POST["name"] ?? "", ENT_QUOTES);',
            'assets/site.css' => 'body { color: teal; }',
            'assets/images/logo.png' => "\x89PNG\r\n\x1a\n\x00\xff\x01",
            'assets/fonts/site.woff2' => "wOF2\x00\xff\x80\x01",
        ];
        $wrapped = [];
        foreach ($files as $path => $contents) {
            $wrapped['campaign/'.$path] = $contents;
        }
        $release = $this->deploy($wrapped);

        $response = $this->get(route('landings.download', $release))
            ->assertOk()
            ->assertDownload($release->landing->slug.'-'.$release->id.'.zip')
            ->assertHeader('Content-Type', 'application/zip')
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        $this->assertTrue($response->headers->hasCacheControlDirective('private'));
        $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
        $this->assertEquals($files, $this->archiveContents($response));
    }

    public function test_download_contains_published_runtime_scripts_without_private_sources_or_drafts(): void
    {
        $release = $this->deploy([
            'index.tpl.html' => '<h1>Published {query.title}</h1>',
            'assets/site.css' => 'body { color: teal; }',
        ]);
        $disk = Storage::disk('landings');
        $compiled = $disk->get($release->storage_path.'/index.php');
        $disk->assertExists($release->storage_path.'/'.LandingRuntimeCompiler::SOURCE_MANIFEST);
        $workspace = app(FileWorkspaceService::class)->open($release, $this->editor);
        app(FileWorkspaceService::class)->write($workspace, 'index.tpl.html', '<h1>Unpublished {query.title}</h1>', $this->editor);

        $contents = $this->archiveContents($this->get(route('landings.download', $release))->assertOk());

        $this->assertEquals([
            'index.php' => $compiled,
            'assets/site.css' => 'body { color: teal; }',
        ], $contents);
        $this->assertArrayNotHasKey(LandingRuntimeCompiler::SOURCE_MANIFEST, $contents);
        $this->assertArrayNotHasKey('index.tpl.html', $contents);
        $this->assertStringNotContainsString('Unpublished', $contents['index.php']);
    }

    public function test_generated_landing_download_includes_compiled_pages_assets_and_uploaded_images(): void
    {
        $template = app(TemplateArchiveService::class)->import($this->zip([
            'index.tpl.html' => <<<'TPL'
@template "Downloadable website"
@param title String = "Original title"
@param hero Image = ""
@layout
<h1>{{title}}</h1><img src="{{hero}}"><p>{query.visitor}</p>
@endlayout
TPL,
            'success.tpl.php' => <<<'TPL'
@layout
<h1>{{title}}</h1><?php echo htmlspecialchars($_POST['name'] ?? '', ENT_QUOTES); ?>
@endlayout
TPL,
            'assets/site.css' => 'h1 { color: purple; }',
        ]), $this->uploader);
        $image = UploadedFile::fake()->image('hero.png', 20, 20);
        $imageBytes = file_get_contents($image->getRealPath());
        $landing = app(TemplateLandingService::class)->create($template, [
            'name' => 'Generated website', 'slug' => 'generated-website',
        ], ['title' => 'Saved campaign', 'hero' => ''], ['hero' => $image], $this->uploader);
        $release = $landing->activeRelease;
        $imagePath = $release->template_values['hero'];
        $disk = Storage::disk('landings');
        $disk->assertExists($release->storage_path.'/'.LandingRuntimeCompiler::SOURCE_MANIFEST);

        $contents = $this->archiveContents($this->get(route('landings.download', $release))->assertOk());

        $this->assertEquals([
            'index.php' => $disk->get($release->storage_path.'/index.php'),
            'success.php' => $disk->get($release->storage_path.'/success.php'),
            'assets/site.css' => 'h1 { color: purple; }',
            $imagePath => $imageBytes,
        ], $contents);
        $this->assertStringContainsString('<h1>Saved campaign</h1>', $contents['index.php']);
        $this->assertStringContainsString($imagePath, $contents['index.php']);
        $this->assertStringContainsString("\$_POST['name']", $contents['success.php']);
        $this->assertArrayNotHasKey('index.tpl.html', $contents);
        $this->assertArrayNotHasKey('success.tpl.php', $contents);
        $this->assertArrayNotHasKey(LandingRuntimeCompiler::SOURCE_MANIFEST, $contents);
    }

    public function test_editor_can_download_historical_release_of_paused_landing_without_domains(): void
    {
        $previous = $this->deploy(['index.html' => '<h1>Previous release</h1>']);
        $landing = $previous->landing;
        $current = $this->deploy(['index.html' => '<h1>Current release</h1>'], $landing);
        $landing->update(['is_active' => false]);
        $this->assertFalse($previous->fresh()->is_active);
        $this->assertCount(0, $landing->domains);

        $this->assertSame(['index.html' => '<h1>Previous release</h1>'], $this->archiveContents(
            $this->get(route('landings.download', $previous))->assertOk(),
        ));
        $this->assertSame(['index.html' => '<h1>Current release</h1>'], $this->archiveContents(
            $this->get(route('landings.download', $current))->assertOk(),
        ));
        $this->assertSame($current->id, $landing->fresh()->activeRelease->id);
    }

    public function test_download_requires_an_active_user_on_the_panel_host(): void
    {
        $release = $this->deploy(['index.html' => 'Private landing']);
        auth()->logout();

        $this->get(route('landings.download', $release))->assertRedirect(route('login'));

        $this->actingAs(User::factory()->create(['is_active' => false]))
            ->get(route('landings.download', $release))->assertRedirect(route('login'));
        $this->assertGuest();

        $this->actingAs($this->editor)
            ->get('http://customer.example.test/admin/landing-releases/'.$release->id.'/download')
            ->assertNotFound();
    }

    public function test_missing_release_content_or_entrypoint_returns_not_found(): void
    {
        $release = $this->deploy(['index.html' => 'Home', 'assets/site.css' => 'body {}']);
        $disk = Storage::disk('landings');
        $disk->delete($release->storage_path.'/index.html');

        $this->get(route('landings.download', $release))->assertNotFound();

        $disk->deleteDirectory($release->storage_path);
        $this->get(route('landings.download', $release))->assertNotFound();
        $this->get(route('landings.download', ['release' => (string) Str::ulid()]))->assertNotFound();
    }

    public function test_temporary_archive_is_deleted_after_the_response_is_sent(): void
    {
        $release = $this->deploy(['index.html' => 'Ready to download']);
        $response = $this->get(route('landings.download', $release))->assertOk();
        $this->assertInstanceOf(BinaryFileResponse::class, $response->baseResponse);
        $path = $response->baseResponse->getFile()->getPathname();
        $this->temporaryFiles[] = $path;
        $this->assertFileExists($path);

        ob_start();
        try {
            $response->baseResponse->sendContent();
        } finally {
            ob_end_clean();
        }

        $this->assertFileDoesNotExist($path);
        Storage::disk('landings')->assertExists($release->storage_path.'/index.html');
    }

    public function test_download_rejects_symlinks_to_files_or_directories_outside_the_release(): void
    {
        $release = $this->deploy(['index.html' => 'Home']);
        $disk = Storage::disk('landings');
        $disk->put('outside-release/private.txt', 'Private file');
        $link = $disk->path($release->storage_path.'/outside');

        foreach (['outside-release/private.txt', 'outside-release'] as $target) {
            $this->assertTrue(symlink($disk->path($target), $link));
            try {
                $this->get(route('landings.download', $release))->assertNotFound();
            } finally {
                unlink($link);
            }
        }
    }

    public function test_library_and_landing_page_offer_downloads_for_available_releases(): void
    {
        $previous = $this->deploy(['index.html' => 'Previous']);
        $current = $this->deploy(['index.html' => 'Current'], $previous->landing);
        $current->landing->update(['is_active' => false]);

        Livewire::test(Index::class)
            ->assertSeeHtml('href="'.route('landings.download', $current).'"')
            ->assertDontSeeHtml('href="'.route('landings.download', $previous).'"');

        Livewire::test(Show::class, ['landing' => $current->landing])
            ->assertSeeHtml('href="'.route('landings.download', $current).'"')
            ->assertSeeHtml('href="'.route('landings.download', $previous).'"')
            ->assertSee('Download ZIP');
    }

    public function test_landings_without_releases_do_not_offer_download_links(): void
    {
        $landing = Landing::query()->create(['name' => 'Empty landing', 'slug' => 'empty-landing', 'is_active' => true]);

        Livewire::test(Index::class)->assertDontSee('Download ZIP')->assertDontSee('/download');
        Livewire::test(Show::class, ['landing' => $landing])->assertDontSee('Download ZIP')->assertDontSee('/download');
    }

    private function deploy(array $files, ?Landing $landing = null): LandingRelease
    {
        $landing ??= Landing::query()->create(['name' => 'Download campaign', 'slug' => 'download-campaign', 'is_active' => true]);

        return app(LandingArchiveService::class)->deploy($landing, $this->zip($files), $this->uploader);
    }

    private function zip(array $files): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'landing-download-test-');
        $this->temporaryFiles[] = $path;
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        foreach ($files as $name => $contents) {
            $this->assertTrue($zip->addFromString($name, $contents));
        }
        $this->assertTrue($zip->close());

        return new UploadedFile($path, 'landing.zip', 'application/zip', null, true);
    }

    /** @return array<string, string> */
    private function archiveContents(TestResponse $response): array
    {
        $this->assertInstanceOf(BinaryFileResponse::class, $response->baseResponse);
        $path = $response->baseResponse->getFile()->getPathname();
        $this->temporaryFiles[] = $path;
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path));
        try {
            $files = [];
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $files[$zip->getNameIndex($index)] = $zip->getFromIndex($index);
            }

            return $files;
        } finally {
            $zip->close();
        }
    }
}
