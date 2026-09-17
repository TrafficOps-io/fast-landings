<?php

namespace Tests\Feature;

use App\Models\Landing;
use App\Models\LandingRelease;
use App\Models\User;
use App\Services\LandingArchiveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
use ZipArchive;

class LandingArchiveServiceTest extends TestCase
{
    use RefreshDatabase;

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
            'fast-landings.max_expansion_ratio' => 200,
            'fast-landings.max_path_bytes' => 1024,
            'fast-landings.max_path_depth' => 32,
        ]);
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryArchives as $archive) {
            if (is_file($archive)) {
                unlink($archive);
            }
        }

        parent::tearDown();
    }

    public function test_valid_zip_is_extracted_and_activated_as_a_release(): void
    {
        $landing = $this->landing();
        $uploader = User::factory()->create();
        $previous = $this->release($landing, active: true);
        $archive = $this->zip([
            'index.html' => '<h1>Campaign</h1>',
            'assets/app.css' => 'body { color: teal; }',
        ]);

        $release = app(LandingArchiveService::class)->deploy($landing, $archive, $uploader);

        $this->assertTrue($release->is_active);
        $this->assertNotNull($release->activated_at);
        $this->assertSame($landing->id, $release->landing_id);
        $this->assertSame($uploader->id, $release->uploaded_by);
        $this->assertSame('landing.zip', $release->original_name);
        $this->assertSame('index.html', $release->entrypoint);
        $this->assertSame(2, $release->file_count);
        $this->assertSame(strlen('<h1>Campaign</h1>') + strlen('body { color: teal; }'), $release->size_bytes);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $release->checksum);
        $this->assertFalse($previous->refresh()->is_active);
        $this->assertNull($previous->activated_at);

        Storage::disk('landings')->assertExists($release->storage_path.'/index.html');
        Storage::disk('landings')->assertExists($release->storage_path.'/assets/app.css');
        $this->assertSame('<h1>Campaign</h1>', Storage::disk('landings')->get($release->storage_path.'/index.html'));
        $this->assertSame([], Storage::disk('landings')->directories($landing->id.'/staging'));
    }

    public function test_single_wrapper_folder_is_removed_during_extraction(): void
    {
        $archive = $this->zip([
            'campaign/index.html' => '<h1>Wrapped</h1>',
            'campaign/js/app.js' => 'console.log("wrapped")',
            '__MACOSX/campaign/._index.html' => 'metadata',
        ]);

        $release = app(LandingArchiveService::class)->deploy($this->landing(), $archive, User::factory()->create());

        Storage::disk('landings')->assertExists($release->storage_path.'/index.html');
        Storage::disk('landings')->assertExists($release->storage_path.'/js/app.js');
        Storage::disk('landings')->assertMissing($release->storage_path.'/campaign/index.html');
        Storage::disk('landings')->assertMissing($release->storage_path.'/__MACOSX/campaign/._index.html');
        $this->assertSame(2, $release->file_count);
    }

    public function test_path_traversal_is_rejected_without_creating_a_release(): void
    {
        $archive = $this->zip([
            'index.html' => 'safe',
            '../outside.txt' => 'unsafe',
        ]);

        $exception = $this->deployExpectingValidationFailure($archive);

        $this->assertStringContainsString('unsafe path', $exception->errors()['archive'][0]);
        $this->assertDatabaseCount('landing_releases', 0);
        $this->assertSame([], Storage::disk('landings')->allFiles());
    }

    public function test_php_site_keeps_handler_source_without_executing_it_during_deployment(): void
    {
        $source = '<?php throw new RuntimeException("Only execute on a visitor request");';
        $release = app(LandingArchiveService::class)->deploy($this->landing(), $this->zip([
            'index.php' => '<form method="post" action="success.php"><input name="name"></form>',
            'success.php' => $source,
        ]), User::factory()->create());

        $this->assertSame('index.php', $release->entrypoint);
        $this->assertSame($source, Storage::disk('landings')->get($release->storage_path.'/success.php'));
    }

    public function test_wrapped_site_can_have_php_and_html_indexes_and_nested_pages(): void
    {
        $release = app(LandingArchiveService::class)->deploy($this->landing(), $this->zip([
            'site/index.php' => '<?php echo "Home";',
            'site/index.html' => 'Static alternative',
            'site/checkout/index.php' => '<?php echo "Checkout";',
            'site/checkout/success.php' => '<?php echo $_POST["name"] ?? "";',
        ]), User::factory()->create());

        $this->assertSame('index.php', $release->entrypoint);
        Storage::disk('landings')->assertExists($release->storage_path.'/checkout/index.php');
        Storage::disk('landings')->assertMissing($release->storage_path.'/site/index.php');
    }

    public function test_separate_php_and_html_wrapper_roots_are_rejected(): void
    {
        $exception = $this->deployExpectingValidationFailure($this->zip([
            'first/index.php' => '<?php echo "First";',
            'second/index.html' => 'Second',
        ]));

        $this->assertStringContainsString('one top-level folder', $exception->errors()['archive'][0]);
        $this->assertDatabaseCount('landing_releases', 0);
    }

    public function test_symbolic_link_is_rejected_without_creating_a_release(): void
    {
        $archive = $this->zip(
            ['index.html' => 'safe', 'assets/escape' => '../../outside.txt'],
            function (ZipArchive $zip): void {
                $this->assertTrue($zip->setExternalAttributesName(
                    'assets/escape',
                    ZipArchive::OPSYS_UNIX,
                    (0120000 | 0777) << 16,
                ));
            },
        );

        $exception = $this->deployExpectingValidationFailure($archive);

        $this->assertStringContainsString('unsafe path', $exception->errors()['archive'][0]);
        $this->assertDatabaseCount('landing_releases', 0);
        $this->assertSame([], Storage::disk('landings')->allFiles());
    }

    public function test_unix_special_file_is_rejected_without_creating_a_release(): void
    {
        $archive = $this->zip(
            ['index.html' => 'safe', 'assets/fifo' => 'unsafe'],
            function (ZipArchive $zip): void {
                $this->assertTrue($zip->setExternalAttributesName(
                    'assets/fifo',
                    ZipArchive::OPSYS_UNIX,
                    (0010000 | 0644) << 16,
                ));
            },
        );

        $exception = $this->deployExpectingValidationFailure($archive);

        $this->assertStringContainsString('unsafe path', $exception->errors()['archive'][0]);
        $this->assertDatabaseCount('landing_releases', 0);
        $this->assertSame([], Storage::disk('landings')->allFiles());
    }

    public function test_oversized_archive_is_rejected_and_current_release_stays_active(): void
    {
        config(['fast-landings.max_extracted_bytes' => 4]);
        $landing = $this->landing();
        $current = $this->release($landing, active: true);
        Storage::disk('landings')->put($current->storage_path.'/index.html', 'current');
        $archive = $this->zip(['index.html' => '12345']);

        try {
            app(LandingArchiveService::class)->deploy($landing, $archive, User::factory()->create());
            $this->fail('The oversized archive was accepted.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('larger than', $exception->errors()['archive'][0]);
        }

        $this->assertTrue($current->refresh()->is_active);
        $this->assertNotNull($current->activated_at);
        $this->assertDatabaseCount('landing_releases', 1);
        $this->assertSame([$current->storage_path.'/index.html'], Storage::disk('landings')->allFiles());
    }

    public function test_case_or_unicode_colliding_paths_are_rejected(): void
    {
        $archive = $this->zip([
            'index.html' => 'safe',
            'Assets/app.js' => 'first',
            'assets/App.js' => 'second',
        ]);

        $exception = $this->deployExpectingValidationFailure($archive);

        $this->assertStringContainsString('colliding paths', $exception->errors()['archive'][0]);
        $this->assertSame([], Storage::disk('landings')->allFiles());
    }

    public function test_wrapper_archive_cannot_silently_include_files_outside_its_root(): void
    {
        $archive = $this->zip([
            'campaign/index.html' => 'safe',
            'campaign/app.js' => 'safe',
            'outside.txt' => 'must not be ignored',
        ]);

        $exception = $this->deployExpectingValidationFailure($archive);

        $this->assertStringContainsString('same top-level folder', $exception->errors()['archive'][0]);
        $this->assertSame([], Storage::disk('landings')->allFiles());
    }

    public function test_excessive_expansion_ratio_is_rejected(): void
    {
        config([
            'fast-landings.max_extracted_bytes' => 4 * 1024 * 1024,
            'fast-landings.max_expansion_ratio' => 2,
        ]);
        $archive = $this->zip(['index.html' => str_repeat('A', 2 * 1024 * 1024)]);

        $exception = $this->deployExpectingValidationFailure($archive);

        $this->assertStringContainsString('expansion ratio', $exception->errors()['archive'][0]);
        $this->assertSame([], Storage::disk('landings')->allFiles());
    }

    public function test_activation_switches_releases_atomically(): void
    {
        $landing = $this->landing();
        $current = $this->release($landing, active: true);
        $replacement = $this->release($landing, active: false);
        Storage::disk('landings')->put($replacement->storage_path.'/index.html', 'replacement');

        app(LandingArchiveService::class)->activate($landing, $replacement);

        $this->assertFalse($current->refresh()->is_active);
        $this->assertNull($current->activated_at);
        $this->assertTrue($replacement->refresh()->is_active);
        $this->assertNotNull($replacement->activated_at);
        $this->assertSame(1, LandingRelease::query()
            ->where('landing_id', $landing->id)
            ->where('is_active', true)
            ->count());
    }

    private function landing(): Landing
    {
        return Landing::query()->create([
            'name' => 'Campaign',
            'slug' => 'campaign-'.Str::lower(Str::random(8)),
            'is_active' => true,
        ]);
    }

    private function release(Landing $landing, bool $active): LandingRelease
    {
        return LandingRelease::query()->create([
            'landing_id' => $landing->id,
            'uploaded_by' => null,
            'original_name' => 'previous.zip',
            'storage_path' => $landing->id.'/releases/'.Str::ulid(),
            'entrypoint' => 'index.html',
            'size_bytes' => 7,
            'file_count' => 1,
            'checksum' => hash('sha256', Str::random()),
            'is_active' => $active,
            'activated_at' => $active ? now() : null,
        ]);
    }

    /**
     * @param  array<string, string>  $files
     * @param  null|callable(ZipArchive): void  $configure
     */
    private function zip(array $files, ?callable $configure = null): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'fast-landings-zip-');
        $this->assertNotFalse($path);
        $this->temporaryArchives[] = $path;

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        foreach ($files as $name => $contents) {
            $this->assertTrue($zip->addFromString($name, $contents));
        }
        $configure?->__invoke($zip);
        $this->assertTrue($zip->close());

        return new UploadedFile($path, 'landing.zip', 'application/zip', null, true);
    }

    private function deployExpectingValidationFailure(UploadedFile $archive): ValidationException
    {
        try {
            app(LandingArchiveService::class)->deploy($this->landing(), $archive, User::factory()->create());
        } catch (ValidationException $exception) {
            return $exception;
        }

        $this->fail('The unsafe archive was accepted.');
    }
}
