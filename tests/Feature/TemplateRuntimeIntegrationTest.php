<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Landing;
use App\Models\LandingTemplate;
use App\Models\User;
use App\Services\FileWorkspaceService;
use App\Services\LandingArchiveService;
use App\Services\Templates\TemplateArchiveService;
use App\Services\Templates\TemplateLandingService;
use App\Services\Templates\TemplatePreviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process as NativeProcess;
use Tests\TestCase;
use TrafficOps\TemplateDsl\TemplateEngine;
use ZipArchive;

class TemplateRuntimeIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private User $administrator;

    private array $temporaryFiles = [];

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
        foreach ($this->temporaryFiles as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    public function test_request_rules_are_per_page_and_not_editable_template_fields(): void
    {
        $template = $this->import([
            'index.tpl.php' => <<<'TPL'
@template "Request pages"
@param title String = "Hello {query.subid}"
@validation query fallback="/error"
  @param subid String required
  @param pixel String lenght=10 required
@endvalidation
@layout
<h1>{{title}}</h1>
@endlayout
TPL,
            'success.tpl.php' => <<<'TPL'
@param message Text = "Thanks {body.name}! Call {body.phone}."
@validation body fallback="submit-error"
  @param phone String required mask="+380 ... ... ..."
  @param name String required min=4
@endvalidation
@layout
<p>{{message}}</p>
@endlayout
TPL,
        ]);
        $fields = array_merge([], ...array_column($template->definition['sections'], 'fields'));
        $this->assertSame(['title', 'message'], array_column($fields, 'name'));
        $this->assertStringContainsString('@validation query', $template->definition['html']);
        $this->assertStringNotContainsString('@validation body', $template->definition['html']);
        $this->assertStringContainsString('@validation body', $template->definition['pages']['success.php']);

        $landing = $this->publish($template);
        $root = $landing->activeRelease->storage_path;
        $this->assertSame('<h1>Hello visitor-1</h1>', trim($this->execute($root.'/index.php', ['subid' => 'visitor-1', 'pixel' => '1234567890'])));
        $this->assertSame('<p>Thanks &lt;Igor&gt;! Call +380 123 456 789.</p>', trim($this->execute($root.'/success.php', [], ['name' => '<Igor>', 'phone' => '+380 123 456 789'])));
        $this->assertSame('', $this->execute($root.'/success.php', ['subid' => 'present'], ['name' => 'Igor']));
        $this->assertSame('', $this->execute($root.'/index.php', ['subid' => 'present', 'pixel' => 'short']));
    }

    public function test_request_validation_remains_top_level_on_an_implicit_layout_page(): void
    {
        $template = $this->import([
            'index.tpl.html' => "@layout\n<a href=\"success.php\">Continue</a>\n@endlayout",
            'success.tpl.php' => "@validation body\n@param name String required\n@endvalidation\n<p>{body.name}</p>",
        ]);

        $this->assertStringStartsWith('@validation body', $template->definition['pages']['success.php']);
        $landing = $this->publish($template);
        $path = $landing->activeRelease->storage_path.'/success.php';
        $this->assertSame('<p>Visitor</p>', trim($this->execute($path, [], ['name' => 'Visitor'])));
        $this->assertSame('Invalid request.', trim($this->execute($path)));
    }

    public function test_downloadable_runtime_form_example_imports_publishes_and_renders_its_saved_message(): void
    {
        $template = app(TemplateArchiveService::class)->import(
            new UploadedFile(public_path('examples/runtime-form-template.zip'), 'runtime-form-template.zip', 'application/zip', null, true),
            $this->administrator,
        );
        $landing = $this->publish($template);
        $root = $landing->activeRelease->storage_path;
        $this->assertSame('index.php', $landing->activeRelease->entrypoint);
        $main = $this->execute($root.'/index.php', ['subid' => 'visitor-42', 'pixel' => '1234567890']);
        $this->assertStringContainsString('data-pixel="1234567890"', $main);
        $this->assertStringContainsString('value="visitor-42"', $main);
        $success = $this->execute($root.'/success.php', [], ['name' => 'Игорь', 'phone' => '+380 123 456 789']);
        $this->assertStringContainsString('Спасибо за заказ, Игорь!', $success);
        $this->assertStringContainsString('на номер +380 123 456 789.', $success);
        $this->assertStringContainsString('{body.name}', $landing->template_values['message']);
        Storage::disk('landings')->assertExists($root.'/error.html');
        Storage::disk('landings')->assertExists($root.'/submit-error.html');
    }

    public function test_macros_in_saved_landing_settings_compile_and_update_without_freezing_request_values(): void
    {
        $template = $this->import(['index.tpl.html' => <<<'TPL'
@template "Settings macros"
@param title String = "Default"
@param link Url = "https://example.test/"
@layout
<h1>{{title}}</h1><a href="{{link}}">Continue</a>
@endlayout
TPL]);
        $values = ['title' => 'Thanks {body.name} — {headers.x-campaign}', 'link' => 'https://example.test/?subid={query.subid}'];
        $landing = $this->publish($template, $values);
        $release = $landing->activeRelease;
        $this->assertSame($values, $release->template_values);
        $this->assertSame('index.php', $release->entrypoint);
        Storage::disk('landings')->assertMissing($release->storage_path.'/index.html');
        $first = $this->execute($release->storage_path.'/index.php', ['subid' => 'one'], ['name' => '<First>'], ['HTTP_X_CAMPAIGN' => 'A']);
        $second = $this->execute($release->storage_path.'/index.php', ['subid' => 'two'], ['name' => 'Second'], ['HTTP_X_CAMPAIGN' => 'B']);
        $this->assertStringContainsString('Thanks &lt;First&gt; — A', $first);
        $this->assertStringContainsString('https://example.test/?subid=one', $first);
        $this->assertStringContainsString('Thanks Second — B', $second);
        $updated = app(TemplateLandingService::class)->update($landing, $template, [...$values, 'title' => 'Updated {query.subid}'], [], $this->administrator, $release->id);
        $this->assertNotSame($release->id, $updated->activeRelease->id);
        $this->assertStringContainsString('Updated latest', $this->execute($updated->activeRelease->storage_path.'/index.php', ['subid' => 'latest']));
        $this->assertFalse($release->fresh()->is_active);
    }

    public function test_settings_cannot_inject_php_or_request_validation_directives(): void
    {
        $template = $this->import(['index.tpl.html' => "@param title Text\n@layout\n{{title}}\n@endlayout"]);
        $value = "<?php throw new RuntimeException('injected'); ?>\n@validation body fallback=\"/injected\"\n@param name String required\n@endvalidation\nName: {body.name}";
        $landing = $this->publish($template, ['title' => $value]);
        $html = $this->execute($landing->activeRelease->storage_path.'/index.php');
        $this->assertStringContainsString('&lt;?php throw new RuntimeException', $html);
        $this->assertStringContainsString('&#64;validation body', $html);
        $this->assertStringContainsString('Name: ', $html);
    }

    public function test_combined_settings_cannot_synthesize_trusted_validation_declarations(): void
    {
        $template = $this->import(['index.tpl.html' => <<<'TPL'
@param start String
@param finish Text
@layout
@{{start}}{{finish}}
<p>{query.name}</p>
@endlayout
TPL]);
        $landing = $this->publish($template, ['start' => 'valid', 'finish' => "ation body\n@param phone String required\n@endvalidation"]);
        $html = $this->execute($landing->activeRelease->storage_path.'/index.php', ['name' => 'Allowed']);
        $this->assertStringContainsString('&#64;validation body', $html);
        $this->assertStringContainsString('<p>Allowed</p>', $html);
    }

    public function test_plain_landing_archive_and_file_editor_publish_compile_request_macros(): void
    {
        $landing = Landing::query()->create(['name' => 'Plain', 'slug' => 'plain', 'is_active' => true]);
        $release = app(LandingArchiveService::class)->deploy($landing, $this->zip([
            'site/index.tpl.html' => '<h1>{query.title}</h1>',
            'site/success.html' => "@validation body fallback=\"/error\"\n@param name String required\n@endvalidation\n<p>{body.name}</p>",
            'site/assets/site.css' => 'body { color: teal; }',
        ]), $this->administrator);
        $disk = Storage::disk('landings');
        $this->assertSame('index.php', $release->entrypoint);
        $disk->assertMissing($release->storage_path.'/index.tpl.html');
        $disk->assertMissing($release->storage_path.'/success.html');
        $this->assertSame('<h1>Hello</h1>', $this->execute($release->storage_path.'/index.php', ['title' => 'Hello']));
        $this->assertStringContainsString('<p>Visitor</p>', $this->execute($release->storage_path.'/success.php', [], ['name' => 'Visitor']));

        $service = app(FileWorkspaceService::class);
        $workspace = $service->open($release, $this->administrator);
        $snapshot = $service->snapshot($workspace, $this->administrator);
        $this->assertSame('index.tpl.html', $snapshot['info']['entrypoint']);
        $this->assertSame('<h1>{query.title}</h1>', $snapshot['sources']['index.tpl.html']);
        $this->assertStringContainsString('@validation body', $snapshot['sources']['success.html']);
        $this->assertArrayNotHasKey('index.php', $snapshot['sources']);
        $this->assertNotContains('.runtime-sources.json', array_column($snapshot['files'], 'path'));
        $service->write($workspace, 'index.tpl.html', '<h1>Edited {query.title}</h1>', $this->administrator);
        $updated = $service->publish($workspace, $this->administrator);
        $this->assertStringContainsString('<h1>Edited Again</h1>', $this->execute($updated->storage_path.'/index.php', ['title' => 'Again']));
        $this->assertSame('body { color: teal; }', $disk->get($updated->storage_path.'/assets/site.css'));
        $secondWorkspace = $service->open($updated, $this->administrator);
        $this->assertSame('<h1>Edited {query.title}</h1>', $service->read($secondWorkspace, 'index.tpl.html', $this->administrator));
        $this->assertStringNotContainsString('<?php', $service->read($secondWorkspace, 'success.html', $this->administrator));
    }

    public function test_compiled_output_collision_rejects_release_without_replacing_active_content(): void
    {
        $landing = Landing::query()->create(['name' => 'Collision', 'slug' => 'collision', 'is_active' => true]);
        $service = app(LandingArchiveService::class);
        $previous = $service->deploy($landing, $this->zip(['index.html' => 'Previous']), $this->administrator);
        try {
            $service->deploy($landing, $this->zip(['index.html' => '{query.title}', 'index.php' => '<?php echo "Original";']), $this->administrator);
            $this->fail('A compiled page must not overwrite an existing script.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('conflicts', $exception->errors()['archive'][0]);
        }
        $this->assertSame($previous->id, $landing->fresh()->activeRelease->id);
        $this->assertDatabaseCount('landing_releases', 1);
        $this->assertSame('Previous', Storage::disk('landings')->get($previous->storage_path.'/index.html'));
    }

    public function test_upload_cannot_supply_or_override_the_private_source_manifest(): void
    {
        $landing = Landing::query()->create(['name' => 'Reserved', 'slug' => 'reserved', 'is_active' => true]);
        $this->expectException(ValidationException::class);
        app(LandingArchiveService::class)->deploy($landing, $this->zip([
            'index.html' => 'Home', '.runtime-sources.json' => '{"version":1,"pages":{}}',
        ]), $this->administrator);
    }

    public function test_static_template_preview_strips_validation_without_executing_request_php(): void
    {
        config(['fast-landings.previews.enabled' => true]);
        $template = $this->import(['index.tpl.html' => <<<'TPL'
@template "Runtime preview"
@previewData
{}
@endpreviewData
@validation query fallback="/error"
@param subid String required
@endvalidation
@layout
<h1>Demo {query.subid}</h1>
@endlayout
TPL])->fresh();
        $this->assertSame('queued', $template->preview_status);
        Process::fake(function ($process) {
            $html = file_get_contents($process->command[2].'/index.html');
            $this->assertStringContainsString('<h1>Demo {query.subid}</h1>', $html);
            $this->assertStringNotContainsString('@validation', $html);
            $this->assertStringNotContainsString('<?php', $html);
            file_put_contents($process->command[3], base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wl6Vh8AAAAASUVORK5CYII='));

            return Process::result();
        });
        app(TemplatePreviewService::class)->generate($template->id, $template->preview_token);
        $this->assertSame('ready', $template->fresh()->preview_status);
    }

    public function test_wholly_dynamic_url_settings_do_not_bypass_url_validation(): void
    {
        $template = $this->import(['index.tpl.html' => "@param link Url\n@layout\n<a href=\"{{link}}\">Continue</a>\n@endlayout"]);
        $this->expectException(ValidationException::class);
        app(TemplateEngine::class)->validateValues($template->definition, ['link' => '{query.url}']);
    }

    public function test_whole_email_setting_macros_preserve_runtime_values_and_escape_html(): void
    {
        $template = $this->import(['index.tpl.html' => "@param email Email\n@layout\n<p>{{email}}</p>\n@endlayout"]);
        $landing = $this->publish($template, ['email' => '{body.email}']);
        $this->assertSame('{body.email}', $landing->template_values['email']);
        $this->assertSame('<p>visitor@example.test</p>', trim($this->execute($landing->activeRelease->storage_path.'/index.php', [], ['email' => 'visitor@example.test'])));
        $this->assertSame('<p>&lt;script&gt;alert(1)&lt;/script&gt;</p>', trim($this->execute($landing->activeRelease->storage_path.'/index.php', [], ['email' => '<script>alert(1)</script>'])));
        $this->expectException(ValidationException::class);
        app(TemplateEngine::class)->validateValues($template->definition, ['email' => 'invalid-email']);
    }

    private function execute(string $path, array $query = [], array $body = [], array $server = []): string
    {
        $code = '$_GET = '.var_export($query, true).'; $_POST = '.var_export($body, true).'; $_SERVER = '.var_export($server, true).'; require '.var_export(Storage::disk('landings')->path($path), true).';';
        $process = new NativeProcess([PHP_BINARY, '-r', $code]);
        $process->mustRun();

        return $process->getOutput();
    }

    private function publish(LandingTemplate $template, array $values = []): Landing
    {
        return app(TemplateLandingService::class)->create($template, ['name' => 'Runtime', 'slug' => 'runtime'], $values, [], $this->administrator);
    }

    private function import(array $files): LandingTemplate
    {
        return app(TemplateArchiveService::class)->import($this->zip($files), $this->administrator);
    }

    private function zip(array $files): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'request-integration-');
        $this->temporaryFiles[] = $path;
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        foreach ($files as $name => $contents) {
            $zip->addFromString($name, $contents);
        }
        $zip->close();

        return new UploadedFile($path, 'runtime.zip', 'application/zip', null, true);
    }
}
