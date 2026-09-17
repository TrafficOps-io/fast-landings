<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Livewire\Landings\FromTemplate;
use App\Models\Landing;
use App\Models\LandingTemplate;
use App\Models\User;
use App\Services\FileWorkspaceService;
use App\Services\Templates\TemplateArchiveService;
use App\Services\Templates\TemplateLandingService;
use App\Services\Templates\TemplatePreviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;
use ZipArchive;

class TemplateWebsitesTest extends TestCase
{
    use RefreshDatabase;

    private User $administrator;

    private array $archives = [];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('landings');
        Queue::fake();
        $this->administrator = User::factory()->create(['role' => UserRole::Administrator, 'is_active' => true]);
        $this->actingAs($this->administrator);
    }

    protected function tearDown(): void
    {
        foreach ($this->archives as $path) {
            @unlink($path);
        }
        parent::tearDown();
    }

    public function test_downloadable_form_example_compiles_both_pages_with_the_same_settings(): void
    {
        $file = new UploadedFile(public_path('examples/form-website-template.zip'), 'form-website-template.zip', 'application/zip', null, true);
        $template = app(TemplateArchiveService::class)->import($file, $this->administrator);
        $landing = $this->publish($template, ['title' => 'Ask us', 'successTitle' => 'Received & saved']);
        $root = $landing->activeRelease->storage_path;
        $disk = Storage::disk('landings');

        $this->assertSame('index.html', $landing->activeRelease->entrypoint);
        $this->assertStringContainsString('<h1>Ask us</h1>', $disk->get($root.'/index.html'));
        $this->assertStringContainsString('action="/success.php?source=landing"', $disk->get($root.'/index.html'));
        $this->assertStringContainsString('<h1>Received &amp; saved</h1>', $disk->get($root.'/success.php'));
        $this->assertStringContainsString("\$_POST['name']", $disk->get($root.'/success.php'));
        $this->assertStringNotContainsString('@layout', $disk->get($root.'/success.php'));
        $disk->assertExists($root.'/assets/site.css');
        $disk->assertMissing($root.'/index.tpl.html');
        $disk->assertMissing($root.'/success.tpl.php');
        $this->assertSame(['success.php'], array_keys($template->definition['pages']));
        $this->assertSame(['index', 'success'], array_column($template->definition['sections'], 'id'));
    }

    public function test_creation_form_groups_and_saves_settings_from_both_example_pages(): void
    {
        $template = app(TemplateArchiveService::class)->import(new UploadedFile(public_path('examples/form-website-template.zip'), 'form-website-template.zip', 'application/zip', null, true), $this->administrator);
        Livewire::test(FromTemplate::class, ['template' => $template])
            ->assertSee('Main page')->assertSee('Success page')
            ->set('values.title', 'Custom form')->set('values.successTitle', 'Custom success')
            ->set('name', 'Website')->set('slug', 'website')
            ->call('create')->assertHasNoErrors();

        $landing = Landing::query()->where('slug', 'website')->sole();
        $root = $landing->activeRelease->storage_path;
        $this->assertStringContainsString('<h1>Custom form</h1>', Storage::disk('landings')->get($root.'/index.html'));
        $this->assertStringContainsString('<h1>Custom success</h1>', Storage::disk('landings')->get($root.'/success.php'));
    }

    public function test_php_entrypoint_and_plain_php_companion_are_not_executed_during_compilation(): void
    {
        $source = <<<'PHP'
<?php
throw new RuntimeException('Must only run on a visitor request.');
echo '{{not_a_template_parameter}}';
?>
<form method="post" action="success.php"><input name="name"></form>
PHP;
        $success = '<?php echo htmlspecialchars($_POST["name"] ?? "", ENT_QUOTES, "UTF-8");';
        $template = $this->import(['website/index.php' => $source, 'website/success.php' => $success]);
        $landing = $this->publish($template);
        $root = $landing->activeRelease->storage_path;

        $this->assertSame('index.php', $landing->activeRelease->entrypoint);
        $this->assertSame($source, Storage::disk('landings')->get($root.'/index.php'));
        $this->assertSame($success, Storage::disk('landings')->get($root.'/success.php'));
        Storage::disk('landings')->assertMissing($root.'/index.html');
    }

    public function test_php_template_pages_share_types_and_blocks_and_keep_php_expressions_opaque(): void
    {
        $template = $this->import([
            'index.tpl.php' => <<<'TPL'
@template "Two pages"
@type Message
@param title String = "Shared title"
@endtype
@param message Message
@block heading(message: Message)
<h1>{{message.title}}</h1>
@endblock
@layout
<?php session_start(); ?>
@render heading(message)
<form method="post" action="success.php"><input name="name"></form>
@endlayout
TPL,
            'success.tpl.php' => <<<'TPL'
@layout
<?php
$text = '{{message.title}}';
@trigger_error('Preserved PHP statement');
?>
@render heading(message)
<p><?= htmlspecialchars($_POST['name'] ?? '', ENT_QUOTES) ?></p>
@endlayout
TPL,
        ]);
        $landing = $this->publish($template, ['message' => ['title' => '<Changed>']]);
        $root = $landing->activeRelease->storage_path;
        foreach (['index.php', 'success.php'] as $path) {
            $this->assertStringContainsString('<h1>&lt;Changed&gt;</h1>', Storage::disk('landings')->get($root.'/'.$path));
        }
        $success = Storage::disk('landings')->get($root.'/success.php');
        $this->assertStringContainsString("\$text = '{{message.title}}';", $success);
        $this->assertStringContainsString("@trigger_error('Preserved PHP statement');", $success);
    }

    public function test_legacy_template_can_compile_a_second_html_page(): void
    {
        $template = $this->import([
            'template.tpl' => "@template \"Legacy\"\n@param title String = \"Hello\"\n@layout\n<a href=\"success.html\">Continue</a>\n@endlayout",
            'success.tpl.html' => '<h1>{{title}}</h1>',
        ]);
        $landing = $this->publish($template, ['title' => 'Second page']);
        $this->assertSame("<h1>Second page</h1>\n", Storage::disk('landings')->get($landing->activeRelease->storage_path.'/success.html'));
    }

    public function test_html_and_php_template_includes_remain_fragments_instead_of_extra_pages(): void
    {
        $template = $this->import([
            'template.tpl' => "@template \"Includes\"\n@include \"parts/header.tpl.html\"\n@layout\n@render heading(title)\n@endlayout",
            'parts/header.tpl.html' => "@include \"parts/fields.tpl.php\"\n@block heading(title: String)\n<h1>{{title}}</h1>\n@endblock",
            'parts/fields.tpl.php' => '@param title String = "Shared heading"',
        ]);
        $landing = $this->publish($template);

        $this->assertSame("<h1>Shared heading</h1>\n", Storage::disk('landings')->get($landing->activeRelease->storage_path.'/index.html'));
        $this->assertSame([], $template->definition['pages']);
        $this->assertSame([], $template->asset_paths);
        $this->assertCount(1, Storage::disk('landings')->allFiles($landing->activeRelease->storage_path));
    }

    public function test_page_output_cannot_overwrite_an_asset_or_use_undefined_settings(): void
    {
        foreach ([
            ['success.tpl.php' => '@layout'."\nHello\n@endlayout", 'success.php' => '<?php echo "conflict";'],
            ['success.tpl.html' => '<h1>{{missing}}</h1>'],
            ['Success.tpl.html' => 'First', 'success.tpl.html' => 'Second'],
        ] as $companions) {
            try {
                $this->import(['index.tpl.html' => "@layout\nMain\n@endlayout", ...$companions]);
                $this->fail('Invalid page package was accepted.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('templateUpload', $exception->errors());
            }
            $this->assertDatabaseCount('landing_templates', 0);
            $this->assertSame([], Storage::disk('landings')->allFiles());
        }
    }

    public function test_php_template_file_editor_reads_and_republishes_the_correct_entrypoint(): void
    {
        $template = $this->import(['index.tpl.php' => "@layout\n<?php echo 'Hello'; ?>\n@endlayout", 'success.php' => '<?php echo "success";']);
        $service = app(FileWorkspaceService::class);
        $workspace = $service->open($template, $this->administrator);
        $snapshot = $service->snapshot($workspace, $this->administrator);

        $this->assertSame('index.tpl.php', $service->info($workspace, $this->administrator)['entrypoint']);
        $this->assertArrayHasKey('index.tpl.php', $snapshot['sources']);
        $this->assertArrayHasKey('success.php', $snapshot['sources']);
        $service->write($workspace, 'index.tpl.php', "@layout\n<?php echo 'Edited'; ?>\n@endlayout", $this->administrator);
        $updated = $service->publish($workspace, $this->administrator);
        $landing = $this->publish($updated);
        $this->assertSame('index.php', $landing->activeRelease->entrypoint);
        $this->assertStringContainsString("<?php echo 'Edited'; ?>", Storage::disk('landings')->get($landing->activeRelease->storage_path.'/index.php'));
    }

    public function test_single_php_template_upload_keeps_a_php_entrypoint(): void
    {
        $template = app(TemplateArchiveService::class)->import(UploadedFile::fake()->createWithContent('index.tpl.php', "@template \"Single PHP\"\n@layout\n<?php echo 'Hello'; ?>\n@endlayout"), $this->administrator);
        $landing = $this->publish($template);

        $this->assertSame('index.tpl.php', $template->definition['source']);
        $this->assertSame('index.php', $landing->activeRelease->entrypoint);
        Storage::disk('landings')->assertMissing($landing->activeRelease->storage_path.'/index.tpl.php');
    }

    public function test_plain_php_without_a_closing_tag_and_nested_index_are_preserved(): void
    {
        $source = '<?php echo "Hello";';
        $template = $this->import(['index.php' => $source, 'checkout/index.php' => '<?php echo "Checkout";']);
        $landing = $this->publish($template);

        $this->assertSame($source, Storage::disk('landings')->get($landing->activeRelease->storage_path.'/index.php'));
        Storage::disk('landings')->assertExists($landing->activeRelease->storage_path.'/checkout/index.php');
    }

    public function test_compiled_php_page_does_not_need_a_closing_php_tag(): void
    {
        $template = $this->import(['index.tpl.php' => "@layout\n<?php echo 'Hello';\n@endlayout"]);
        $landing = $this->publish($template);

        $this->assertSame("<?php echo 'Hello';\n", Storage::disk('landings')->get($landing->activeRelease->storage_path.'/index.php'));
    }

    public function test_php_entrypoint_preview_never_queues_static_source_capture(): void
    {
        config(['fast-landings.previews.enabled' => true]);
        $template = $this->import(['index.tpl.php' => "@template \"PHP preview\"\n@previewData\n{}\n@endpreviewData\n@layout\n<?php echo 'secret'; ?>\n@endlayout"]);

        $this->assertFalse(app(TemplatePreviewService::class)->request($template));
        Queue::assertNothingPushed();
        $this->assertNull($template->fresh()->preview_status);
    }

    public function test_php_templates_do_not_block_backfill_of_later_html_previews(): void
    {
        config(['fast-landings.previews.enabled' => false]);
        $php = $this->import(['index.tpl.php' => "@template \"PHP\"\n@previewData\n{}\n@endpreviewData\n@layout\n<?php echo 'secret';\n@endlayout"]);
        for ($index = 0; $index < 50; $index++) {
            $php->replicate()->save();
        }
        $html = $this->import(['index.tpl.html' => "@template \"HTML\"\n@previewData\n{}\n@endpreviewData\n@layout\n<h1>Public</h1>\n@endlayout"]);
        config(['fast-landings.previews.enabled' => true]);

        $this->assertSame(1, app(TemplatePreviewService::class)->requestMissing());
        $this->assertSame('queued', $html->fresh()->preview_status);
        $this->assertNull($php->fresh()->preview_status);
    }

    private function publish(LandingTemplate $template, array $values = []): Landing
    {
        return app(TemplateLandingService::class)->create($template, ['name' => 'Website', 'slug' => 'website'], $values, [], $this->administrator);
    }

    private function import(array $files): LandingTemplate
    {
        $path = tempnam(sys_get_temp_dir(), 'template-website-');
        $this->archives[] = $path;
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        foreach ($files as $name => $source) {
            $zip->addFromString($name, $source);
        }
        $zip->close();

        return app(TemplateArchiveService::class)->import(new UploadedFile($path, 'website.zip', 'application/zip', null, true), $this->administrator);
    }
}
