<?php

namespace Tests\Feature;

use App\Enums\DomainKind;
use App\Enums\DomainProvider;
use App\Enums\DomainStatus;
use App\Enums\UserRole;
use App\Livewire\Landings\EditTemplate;
use App\Livewire\Landings\FromTemplate;
use App\Livewire\Landings\Show;
use App\Livewire\Templates\Index;
use App\Models\Landing;
use App\Models\LandingTemplate;
use App\Models\User;
use App\Services\LandingArchiveService;
use App\Services\Templates\TemplateArchiveService;
use App\Services\Templates\TemplateEditorPreview;
use App\Services\Templates\TemplateLandingService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;
use TrafficOps\TemplateDsl\TemplateEngine;
use ZipArchive;

class LandingTemplatesTest extends TestCase
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
            'fast-landings.max_expansion_ratio' => 200,
        ]);
        $this->administrator = User::factory()->create([
            'role' => UserRole::Administrator,
            'is_active' => true,
        ]);
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

    public function test_administrator_can_import_one_html_text_or_tpl_definition(): void
    {
        foreach (['html', 'txt', 'tpl'] as $extension) {
            Livewire::test(Index::class)
                ->set('templateUpload', $this->definitionFile(extension: $extension))
                ->call('importTemplate')
                ->assertHasNoErrors()
                ->assertSet('templateUpload', null)
                ->assertSee('Test article');
        }

        $this->assertDatabaseCount('landing_templates', 3);
        foreach (LandingTemplate::all() as $template) {
            $this->assertSame($this->administrator->id, $template->uploaded_by);
            $this->assertSame(1, $template->definition['version']);
            Storage::disk('landings')->assertExists($template->storage_path.'/'.$template->original_name);
        }
    }

    public function test_import_and_template_edits_preserve_ai_instructions(): void
    {
        $source = <<<'TPL'
@template "Annotated template"
@param title String = "Hello" aiInstructions="Write a concise heading."
@block heading(title: String) aiInstructions="Introduce the article (briefly)."
<h1>{{title}}</h1>
@endblock
@layout
@render heading(title)
@endlayout
TPL;
        $service = app(TemplateArchiveService::class);
        $template = $service->import(UploadedFile::fake()->createWithContent('template.tpl', $source), $this->administrator);
        $template = $service->update($template, ['name' => 'Renamed template'], null, $this->administrator)->fresh();

        $this->assertSame('Write a concise heading.', $template->definition['sections'][0]['fields'][0]['aiInstructions']);
        $this->assertSame('Introduce the article (briefly).', $template->definition['blocks']['heading']['aiInstructions']);
        $this->assertSame("<h1>Hello</h1>\n", app(TemplateEngine::class)->render($template->definition, []));
    }

    public function test_wrapped_zip_resolves_page_and_partial_files_and_preserves_relative_assets(): void
    {
        $source = str_replace(
            '<h1>{{title}}</h1>',
            '<link rel="stylesheet" href="assets/theme.css"><h1>{{title}}</h1>',
            $this->definition(),
        );
        $blockStart = strpos($source, '@block commentItem');
        $blockEnd = strpos($source, '@endblock', $blockStart) + strlen('@endblock');
        $block = substr($source, $blockStart, $blockEnd - $blockStart);
        $source = substr_replace($source, '@include "blocks/comment.tpl"', $blockStart, $blockEnd - $blockStart);
        $template = $this->import($this->zip([
            'package/template.html' => $source,
            'package/blocks/comment.tpl' => $block,
            'package/assets/theme.css' => 'body { color: teal; }',
            'package/assets/app.js' => 'document.title += "!";',
            '__MACOSX/package/._template.html' => 'metadata',
        ]));

        $this->assertStringNotContainsString('@layout', $template->definition['html']);

        $landing = $this->createLanding($template);
        $release = $landing->activeRelease;
        $html = Storage::disk('landings')->get($release->storage_path.'/index.html');

        $this->assertStringContainsString('href="assets/theme.css"', $html);
        $this->assertStringContainsString('<h1>Default title</h1>', $html);
        $this->assertStringContainsString('First comment', $html);
        $this->assertSame('body { color: teal; }', Storage::disk('landings')->get($release->storage_path.'/assets/theme.css'));
        Storage::disk('landings')->assertExists($release->storage_path.'/assets/app.js');
        Storage::disk('landings')->assertMissing($release->storage_path.'/template.html');
        Storage::disk('landings')->assertMissing($release->storage_path.'/blocks/comment.tpl');
        $this->assertCount(3, Storage::disk('landings')->allFiles($release->storage_path));
    }

    public function test_administrator_can_edit_template_details_without_replacing_its_content(): void
    {
        $template = $this->import($this->definitionFile());
        $original = $template->refresh()->getAttributes();
        $files = Storage::disk('landings')->allFiles();

        Livewire::test(Index::class)
            ->call('editTemplate', $template->id)
            ->assertSet('name', 'Test article')
            ->assertSet('description', '')
            ->set('name', '  Updated article  ')
            ->set('description', "A new description.\nWith two lines.")
            ->call('saveTemplate')
            ->assertHasNoErrors()
            ->assertSet('editingTemplateId', null)
            ->assertSee('Updated article');

        $template->refresh();
        $this->assertSame('Updated article', $template->name);
        $this->assertSame("A new description.\nWith two lines.", $template->description);
        $this->assertSame($template->name, $template->definition['name']);
        $this->assertSame($template->description, $template->definition['description']);
        foreach (['storage_path', 'asset_paths', 'original_name', 'uploaded_by'] as $attribute) {
            $this->assertSame($original[$attribute], $template->getAttributes()[$attribute]);
        }
        $this->assertSame($files, Storage::disk('landings')->allFiles());

        Livewire::test(Index::class)->call('editTemplate', $template->id)
            ->set('description', '')->call('saveTemplate')->assertHasNoErrors();
        $this->assertNull($template->refresh()->description);
    }

    public function test_template_editor_validates_details_and_cancels_without_saving(): void
    {
        $template = $this->import($this->definitionFile());
        $original = $template->refresh()->getAttributes();

        Livewire::test(Index::class)
            ->call('editTemplate', $template->id)
            ->set('name', '   ')->call('saveTemplate')->assertHasErrors(['name' => 'required'])
            ->set('name', str_repeat('n', 201))
            ->set('description', str_repeat('d', 2001))
            ->call('saveTemplate')->assertHasErrors(['name' => 'max', 'description' => 'max'])
            ->set('replacementUpload', $this->definitionFile(extension: 'tpl'))
            ->call('cancelEdit')
            ->assertSet('editingTemplateId', null)
            ->assertSet('replacementUpload', null)
            ->assertHasNoErrors();

        $this->assertSame($original, $template->refresh()->getAttributes());
    }

    public function test_replacing_template_with_tpl_or_zip_keeps_identity_and_existing_releases(): void
    {
        $template = $this->import($this->zip([
            'template.html' => $this->definition(),
            'old.css' => 'body { color: red; }',
        ]));
        $landing = $this->createLanding($template);
        $landingAttributes = $landing->getAttributes();
        $release = $landing->activeRelease;
        $releaseAttributes = $release->getAttributes();
        $releaseFiles = [];
        foreach (Storage::disk('landings')->allFiles($release->storage_path) as $path) {
            $releaseFiles[$path] = Storage::disk('landings')->get($path);
        }
        $source = str_replace('Default title', 'Replacement title', $this->definition());

        foreach (['tpl', 'zip'] as $extension) {
            $oldPath = $template->storage_path;
            $file = $extension === 'zip'
                ? $this->zip(['package/template.tpl' => $source, 'package/assets/new.css' => 'body { color: blue; }'])
                : $this->definitionFile($source, 'tpl');

            Livewire::test(Index::class)
                ->call('editTemplate', $template->id)
                ->set('name', 'My template')
                ->set('description', 'My description')
                ->set('replacementUpload', UploadedFile::fake()->createWithContent($file->getClientOriginalName(), $file->getContent()))
                ->call('saveTemplate')
                ->assertHasNoErrors()
                ->assertSet('replacementUpload', null)
                ->assertSet('editingTemplateId', null);

            $template->refresh();
            $this->assertDatabaseCount('landing_templates', 1);
            $this->assertSame('My template', $template->name);
            $this->assertSame('My description', $template->definition['description']);
            $this->assertSame('template.'.$extension, $template->original_name);
            $this->assertNotSame($oldPath, $template->storage_path);
            $this->assertFalse(Storage::disk('landings')->directoryExists($oldPath));
            Storage::disk('landings')->assertExists($template->storage_path.'/template.tpl');
            $this->assertSame($extension === 'zip' ? ['assets/new.css'] : [], $template->asset_paths);
            $this->assertSame($landingAttributes, $landing->refresh()->getAttributes());
            $this->assertSame($releaseAttributes, $release->refresh()->getAttributes());
            foreach ($releaseFiles as $path => $contents) {
                $this->assertSame($contents, Storage::disk('landings')->get($path));
            }

            $newLanding = $this->createLanding($template, 'replacement-'.$extension);
            $this->assertStringContainsString('<h1>Replacement title</h1>', Storage::disk('landings')->get($newLanding->activeRelease->storage_path.'/index.html'));
            if ($extension === 'zip') {
                Storage::disk('landings')->assertExists($newLanding->activeRelease->storage_path.'/assets/new.css');
            }
        }
    }

    public function test_invalid_replacement_preserves_template_details_package_and_releases(): void
    {
        $template = $this->import($this->definitionFile());
        $landing = $this->createLanding($template);
        $original = $template->refresh()->getAttributes();
        $files = Storage::disk('landings')->allFiles();
        $releaseId = $landing->activeRelease->id;

        foreach ([
            $this->definitionFile('not a template', 'tpl'),
            $this->zip(['template.tpl' => $this->definition(), '../escape.txt' => 'unsafe']),
            $this->zip(['landing.html' => 'missing an entrypoint']),
            $this->definitionFile(extension: 'js'),
        ] as $file) {
            Livewire::test(Index::class)->call('editTemplate', $template->id)
                ->set('name', 'Do not save this')
                ->set('replacementUpload', UploadedFile::fake()->createWithContent($file->getClientOriginalName(), $file->getContent()))
                ->call('saveTemplate')->assertHasErrors('replacementUpload');

            $this->assertSame($original, $template->refresh()->getAttributes());
            $this->assertSame($files, Storage::disk('landings')->allFiles());
            $this->assertSame($releaseId, $landing->refresh()->activeRelease->id);
            $this->assertDatabaseCount('landing_templates', 1);
        }
    }

    public function test_deleting_a_replaced_template_removes_its_current_package_even_with_a_stale_model(): void
    {
        $template = $this->import($this->definitionFile());
        app(TemplateArchiveService::class)->update($template, ['name' => 'Replacement'], $this->definitionFile(extension: 'tpl'), $this->administrator);
        app(TemplateArchiveService::class)->delete($template);

        $this->assertDatabaseCount('landing_templates', 0);
        $this->assertSame([], Storage::disk('landings')->allFiles());
    }

    public function test_failed_database_update_discards_replacement_package_and_keeps_original(): void
    {
        $template = $this->import($this->definitionFile());
        $original = $template->refresh()->getAttributes();
        $files = Storage::disk('landings')->allFiles();
        DB::unprepared("CREATE TRIGGER reject_template_update BEFORE UPDATE ON landing_templates BEGIN SELECT RAISE(ABORT, 'Simulated database failure'); END");

        try {
            app(TemplateArchiveService::class)->update($template, ['name' => 'Replacement'], $this->definitionFile(extension: 'tpl'), $this->administrator);
            $this->fail('The database failure must be reported.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('Simulated database failure', $exception->getMessage());
        } finally {
            DB::unprepared('DROP TRIGGER reject_template_update');
        }

        $this->assertSame($original, $template->refresh()->getAttributes());
        $this->assertSame($files, Storage::disk('landings')->allFiles());
    }

    public function test_template_save_rechecks_administrator_role_and_active_status(): void
    {
        $template = $this->import($this->definitionFile());
        $original = $template->refresh()->getAttributes();
        foreach ([['role' => UserRole::Editor], ['is_active' => false]] as $change) {
            $this->administrator->refresh()->update(['role' => UserRole::Administrator, 'is_active' => true]);
            $component = Livewire::test(Index::class)->call('editTemplate', $template->id)->set('name', 'Unauthorized change');
            User::query()->whereKey($this->administrator->id)->update($change);
            $component->call('saveTemplate')->assertForbidden();
            $this->assertSame($original, $template->refresh()->getAttributes());
        }
    }

    public function test_editor_creates_active_landing_with_escaped_values_and_nested_image_upload(): void
    {
        $template = $this->import($this->definitionFile());
        $editor = User::factory()->create(['role' => UserRole::Editor, 'is_active' => true]);

        Livewire::actingAs($editor)
            ->test(FromTemplate::class, ['template' => $template])
            ->set('name', 'My generated article')
            ->set('slug', 'generated-article')
            ->set('description', 'Configured from the editorial template.')
            ->set('values.title', '<script>alert("unsafe")</script>')
            ->set('values.comments.items.0.name', 'Jamie')
            ->set('uploads.comments.items.0.avatar', $this->image())
            ->call('create')
            ->assertHasNoErrors();

        $landing = Landing::query()->where('slug', 'generated-article')->sole();
        $release = $landing->activeRelease;
        $this->assertSame($template->id, $landing->landing_template_id);
        $this->assertSame('<script>alert("unsafe")</script>', $landing->template_values['title']);
        $this->assertTrue($landing->is_active);
        $this->assertTrue($release->is_active);
        $this->assertNotNull($release->activated_at);
        $this->assertSame($editor->id, $release->uploaded_by);

        $html = Storage::disk('landings')->get($release->storage_path.'/index.html');
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringNotContainsString('<script>alert', $html);
        $this->assertStringContainsString('Jamie', $html);
        $imagePath = $landing->template_values['comments']['items'][0]['avatar'];
        $this->assertStringContainsString('src="'.$imagePath.'"', $html);
        Storage::disk('landings')->assertExists($release->storage_path.'/'.$imagePath);
        $this->assertSame($this->image()->getContent(), Storage::disk('landings')->get($release->storage_path.'/'.$imagePath));
    }

    public function test_template_form_renders_controls_and_manages_repeatable_rows(): void
    {
        $template = $this->import($this->definitionFile());

        Livewire::test(FromTemplate::class, ['template' => $template])
            ->assertSee('Appearance')
            ->assertSee('Comments')
            ->assertSee('type="color"', false)
            ->assertSee('type="range"', false)
            ->assertSee('<textarea', false)
            ->assertSee('<select', false)
            ->assertSee('type="file"', false)
            ->assertSet('values.title', 'Default title')
            ->assertSet('values.comments.items.0.name', 'Reader')
            ->call('addItem', 'comments.items')
            ->assertSet('values.comments.items.1.name', 'Reader')
            ->set('values.comments.items.1.name', 'Second reader')
            ->call('removeItem', 'comments.items', 0)
            ->assertSet('values.comments.items.0.name', 'Second reader')
            ->assertHasNoErrors();
    }

    public function test_editor_ai_capability_can_be_removed_without_disabling_the_editor(): void
    {
        $template = $this->import($this->definitionFile());
        config(['fast-landings.editor.ai' => false]);

        Livewire::test(FromTemplate::class, ['template' => $template])
            ->assertSee('LIVE PREVIEW')
            ->assertDontSee('Generate content with AI')
            ->call('generateContent')
            ->assertNotFound();
    }

    public function test_editor_preview_renders_unsaved_values_and_only_serves_declared_assets(): void
    {
        $template = $this->import($this->zip([
            'index.tpl.html' => <<<'TPL'
@template "Live editor"
@param title String = "Default" required
@layout
<!doctype html><html><head><link rel="stylesheet" href="assets/site.css"></head><body><h1>{{title}}</h1></body></html>
@endlayout
TPL,
            'assets/site.css' => 'body { color: teal; }',
        ]));

        $html = app(TemplateEditorPreview::class)->render($template, ['title' => 'Unsaved title']);
        $assetBase = str_replace('__PATH__', '', route('templates.asset', [
            'template' => $template,
            'path' => '__PATH__',
        ]));

        $this->assertNotNull($html);
        $this->assertStringContainsString('Unsaved title', $html);
        $this->assertStringContainsString('<base href="'.$assetBase.'">', $html);
        $this->get(route('templates.asset', ['template' => $template, 'path' => 'assets/site.css']))
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->get(route('templates.asset', ['template' => $template, 'path' => 'assets/missing.css']))
            ->assertNotFound();
    }

    public function test_deleting_repeater_row_keeps_surviving_upload_with_its_comment(): void
    {
        $template = $this->import($this->definitionFile());

        Livewire::test(FromTemplate::class, ['template' => $template])
            ->call('addItem', 'comments.items')
            ->set('values.comments.items.1.name', 'Remaining reader')
            ->set('values.comments.items.1.body', 'Retained comment')
            ->set('uploads.comments.items.1.avatar', $this->image())
            ->call('removeItem', 'comments.items', 0)
            ->set('name', 'Reindexed comments')
            ->set('slug', 'reindexed-comments')
            ->call('create')
            ->assertHasNoErrors();

        $landing = Landing::query()->where('slug', 'reindexed-comments')->sole();
        $comments = $landing->template_values['comments']['items'];
        $this->assertCount(1, $comments);
        $this->assertSame('Remaining reader', $comments[0]['name']);
        $this->assertNotEmpty($comments[0]['avatar']);
        Storage::disk('landings')->assertExists($landing->activeRelease->storage_path.'/'.$comments[0]['avatar']);
    }

    public function test_invalid_values_cannot_leave_a_partial_landing_or_release(): void
    {
        $template = $this->import($this->definitionFile());
        $before = Storage::disk('landings')->allFiles();

        Livewire::test(FromTemplate::class, ['template' => $template])
            ->set('name', 'Invalid settings')
            ->set('slug', 'invalid-settings')
            ->set('values.category', 'undeclared-option')
            ->set('values.font_size', 500)
            ->call('create')
            ->assertHasErrors('values.font_size')
            ->set('values.font_size', 18)
            ->call('create')
            ->assertHasErrors('values.category');

        $this->assertDatabaseCount('landings', 0);
        $this->assertDatabaseCount('landing_releases', 0);
        $this->assertSame($before, Storage::disk('landings')->allFiles());
    }

    public function test_editors_can_browse_templates_but_cannot_import_or_delete_them(): void
    {
        $template = $this->import($this->definitionFile());
        $editor = User::factory()->create(['role' => UserRole::Editor, 'is_active' => true]);

        Livewire::actingAs($editor)->test(Index::class)
            ->assertSee($template->name)
            ->call('importTemplate')
            ->assertForbidden();

        Livewire::actingAs($editor)->test(Index::class)
            ->call('deleteTemplate', $template->id)
            ->assertForbidden();

        Livewire::actingAs($editor)->test(Index::class)
            ->assertDontSee('wire:click="editTemplate(', false)
            ->call('editTemplate', $template->id)->assertForbidden();

        Livewire::actingAs($editor)->test(Index::class)
            ->call('saveTemplate')->assertForbidden();

        $this->assertDatabaseHas('landing_templates', ['id' => $template->id]);
        $this->assertDatabaseCount('landing_templates', 1);
    }

    public function test_revoked_administrator_cannot_mutate_an_already_open_template_page(): void
    {
        $template = $this->import($this->definitionFile());
        $component = Livewire::test(Index::class);
        User::query()->whereKey($this->administrator->id)->update(['role' => UserRole::Editor]);

        $component->call('deleteTemplate', $template->id)->assertForbidden();
        $this->assertDatabaseHas('landing_templates', ['id' => $template->id]);
    }

    public function test_template_used_by_landings_cannot_be_deleted_and_the_message_names_them(): void
    {
        $template = $this->import($this->definitionFile());
        $first = $this->createLanding($template, 'first-article');
        $second = $this->createLanding($template, 'second-article');
        $second->update(['name' => 'Second article']);
        $release = $first->activeRelease;

        try {
            app(TemplateArchiveService::class)->delete($template);
            $this->fail('A template used by landings was deleted.');
        } catch (ValidationException $exception) {
            $message = $exception->errors()['template'][0];
            $this->assertStringContainsString('Generated article', $message);
            $this->assertStringContainsString('Second article', $message);
        }

        Livewire::test(Index::class)
            ->call('deleteTemplate', $template->id)
            ->assertHasErrors('template')
            ->assertSee('cannot be deleted')
            ->assertSee('Generated article')
            ->assertSee('Second article');

        $this->assertDatabaseHas('landing_templates', ['id' => $template->id]);
        $this->assertSame($template->id, $first->fresh()->landing_template_id);
        $this->assertTrue($release->fresh()->is_active);
        $this->assertNotSame([], Storage::disk('landings')->allFiles($template->storage_path));
    }

    public function test_template_can_be_deleted_once_no_landing_uses_it(): void
    {
        $template = $this->import($this->definitionFile());
        $landing = $this->createLanding($template);
        $release = $landing->activeRelease;
        // Replacing the template landing's content with a ZIP makes it a file landing.
        app(LandingArchiveService::class)->deploy($landing, $this->zip(['index.html' => '<h1>File landing</h1>']), $this->administrator);
        $this->assertNull($landing->fresh()->landing_template_id);

        Livewire::test(Index::class)
            ->call('deleteTemplate', $template->id)
            ->assertHasNoErrors();

        $this->assertDatabaseMissing('landing_templates', ['id' => $template->id]);
        $this->assertNull($release->fresh()->landing_template_id);
        $this->assertSame([], Storage::disk('landings')->allFiles($template->storage_path));
    }

    public function test_traversal_and_symbolic_links_are_rejected_without_stored_files(): void
    {
        $definition = $this->definition();
        $this->assertImportRejected($this->zip([
            'template.html' => $definition,
            '../outside.txt' => 'unsafe',
        ]));
        $this->assertImportRejected($this->zip([
            'template.html' => $definition,
            'assets/escape' => '../../outside.txt',
        ], function (ZipArchive $zip): void {
            $this->assertTrue($zip->setExternalAttributesName('assets/escape', ZipArchive::OPSYS_UNIX, (0120000 | 0777) << 16));
        }));
    }

    public function test_invalid_schema_and_external_template_file_references_are_rejected(): void
    {
        $invalid = str_replace('@param title String', '@param title UnknownType', $this->definition());
        $this->assertImportRejected($this->definitionFile($invalid));

        $invalid = "@include \"../outside.tpl\"\n".$this->definition();
        $this->assertImportRejected($this->zip([
            'template.html' => $invalid,
            'blocks/comment.tpl' => 'Safe file',
        ]));
    }

    public function test_downloadable_examples_import_and_compile_successfully(): void
    {
        foreach (['html', 'zip'] as $extension) {
            $template = $this->import(new UploadedFile(
                public_path('examples/article-template.'.$extension),
                'article-template.'.$extension,
                $extension === 'zip' ? 'application/zip' : 'text/html',
                null,
                true,
            ));
            $landing = $this->createLanding($template, slug: 'example-'.$extension);
            $html = Storage::disk('landings')->get($landing->activeRelease->storage_path.'/index.html');

            $this->assertStringContainsString('Small changes. A brighter everyday.', $html);
            $this->assertStringContainsString('The suggestion to start small really resonated with me.', $html);
            $this->assertStringNotContainsString('{{', $html);
            if ($extension === 'zip') {
                Storage::disk('landings')->assertExists($landing->activeRelease->storage_path.'/assets/logo.svg');
            }
        }
    }

    public function test_editing_template_data_keeps_identity_domains_settings_and_uploaded_images(): void
    {
        $template = $this->import($this->definitionFile());
        $values = app(TemplateEngine::class)->defaults($template->definition);
        $values['title'] = 'Saved title';
        $landing = app(TemplateLandingService::class)->create($template,
            ['name' => 'Keep this name', 'slug' => 'keep-this-slug', 'description' => 'Keep this description'],
            $values, ['comments' => ['items' => [['avatar' => $this->image()]]]], $this->administrator,
        );
        $landing->update(['is_active' => false]);
        $domain = $landing->domains()->create([
            'hostname' => 'keep.example.test', 'kind' => DomainKind::Custom,
            'provider' => DomainProvider::Dns, 'status' => DomainStatus::Active, 'is_primary' => true,
        ]);
        $previous = $landing->activeRelease;
        $originalHtml = Storage::disk('landings')->get($previous->storage_path.'/index.html');
        $image = $landing->template_values['comments']['items'][0]['avatar'];
        $editor = User::factory()->create(['role' => UserRole::Editor, 'is_active' => true]);

        Livewire::actingAs($editor)->test(EditTemplate::class, ['landing' => $landing])
            ->assertSet('templateId', $template->id)
            ->assertSet('values.title', 'Saved title')
            ->assertSet('values.comments.items.0.avatar', $image)
            ->set('values.title', 'Updated title')
            ->call('save')->assertHasNoErrors()->assertRedirect(route('landings.show', $landing));

        $landing->refresh();
        $release = $landing->activeRelease;
        $this->assertNotSame($previous->id, $release->id);
        $this->assertSame('Keep this name', $landing->name);
        $this->assertSame('keep-this-slug', $landing->slug);
        $this->assertSame('Keep this description', $landing->description);
        $this->assertFalse($landing->is_active);
        $this->assertSame($landing->id, $domain->fresh()->landing_id);
        $this->assertTrue($domain->fresh()->is_primary);
        $this->assertSame($editor->id, $release->uploaded_by);
        $this->assertSame($landing->template_values, $release->template_values);
        $this->assertSame('Saved title', $previous->fresh()->template_values['title']);
        $this->assertSame($originalHtml, Storage::disk('landings')->get($previous->storage_path.'/index.html'));
        $this->assertStringContainsString('<h1>Updated title</h1>', Storage::disk('landings')->get($release->storage_path.'/index.html'));
        app(LandingArchiveService::class)->delete($previous);
        $this->assertSame($this->image()->getContent(), Storage::disk('landings')->get($release->storage_path.'/'.$image));
    }

    public function test_changing_template_resets_form_and_uploads_and_creates_a_new_release(): void
    {
        $template = $this->import($this->definitionFile());
        $replacement = $this->import($this->definitionFile(str_replace(
            ['Test article', 'Default title', '{{title}}</h1>'], ['Another template', 'Fresh defaults', '{{title}}</h1><hr>'], $this->definition(),
        )));
        $landing = $this->createLanding($template);
        $previous = $landing->activeRelease;

        Livewire::test(EditTemplate::class, ['landing' => $landing])
            ->set('values.title', 'Unsaved edits')
            ->set('uploads.comments.items.0.avatar', $this->image())
            ->set('values.category', 'invalid')->call('save')->assertHasErrors('values.category')
            ->set('templateId', $replacement->id)
            ->assertHasNoErrors()->assertSet('uploads', [])
            ->assertSet('values.title', 'Fresh defaults')
            ->set('values.title', 'New template title')
            ->call('save')->assertHasNoErrors();

        $landing->refresh();
        $this->assertSame($replacement->id, $landing->landing_template_id);
        $this->assertSame('New template title', $landing->template_values['title']);
        $this->assertStringContainsString('<h1>New template title</h1><hr>', Storage::disk('landings')->get($landing->activeRelease->storage_path.'/index.html'));
        $this->assertFalse($previous->fresh()->is_active);
        $this->assertDatabaseCount('landings', 1);
        $this->assertDatabaseCount('landing_releases', 2);

        Livewire::test(Show::class, ['landing' => $landing])->call('activate', $previous->id)->assertHasNoErrors();
        Livewire::test(EditTemplate::class, ['landing' => $landing->fresh()])
            ->assertSet('templateId', $template->id)->assertSet('values.title', 'Default title');
    }

    public function test_template_can_be_replaced_with_zip_and_zip_can_be_replaced_with_template(): void
    {
        $template = $this->import($this->definitionFile());
        $landing = $this->createLanding($template);
        $generated = $landing->activeRelease;

        Livewire::test(Show::class, ['landing' => $landing])
            ->assertSee('Edit template data')->assertSee('Change template')->assertSee('Replace with ZIP archive')
            ->set('archive', UploadedFile::fake()->createWithContent('landing.zip', $this->zip(['index.html' => '<h1>ZIP content</h1>'])->getContent()))
            ->call('deploy')->assertHasNoErrors()->assertSee('Use a template')->assertDontSee('Edit template data');

        $landing->refresh();
        $zip = $landing->activeRelease;
        $this->assertNull($landing->landing_template_id);
        $this->assertNull($landing->template_values);
        $this->assertNull($zip->landing_template_id);
        $this->assertNull($zip->template_values);

        Livewire::test(EditTemplate::class, ['landing' => $landing])
            ->assertSet('templateId', '')->assertSet('values', [])
            ->set('templateId', $template->id)->assertSet('values.title', 'Default title')
            ->set('values.title', 'Template after ZIP')->call('save')->assertHasNoErrors();
        $landing->refresh();
        $this->assertSame($template->id, $landing->landing_template_id);
        $this->assertSame('Template after ZIP', $landing->template_values['title']);
        $this->assertDatabaseCount('landings', 1);
        $this->assertDatabaseCount('landing_releases', 3);

        $archives = app(LandingArchiveService::class);
        $archives->activate($landing, $zip);
        $this->assertNull($landing->fresh()->template_values);
        $archives->activate($landing, $generated);
        $this->assertSame('Default title', $landing->fresh()->template_values['title']);
        Livewire::test(EditTemplate::class, ['landing' => $landing->fresh()])->call('save')->assertHasNoErrors();
    }

    public function test_reindexed_saved_images_and_new_uploads_survive_editing(): void
    {
        $template = $this->import($this->definitionFile());
        Livewire::test(FromTemplate::class, ['template' => $template])
            ->set('name', 'Images')->set('slug', 'images')->call('addItem', 'comments.items')
            ->set('values.comments.items.1.name', 'Keep reader')
            ->set('uploads.comments.items.1.avatar', $this->image())->call('create')->assertHasNoErrors();
        $landing = Landing::query()->where('slug', 'images')->sole();
        $image = $landing->template_values['comments']['items'][1]['avatar'];

        Livewire::test(EditTemplate::class, ['landing' => $landing])
            ->call('removeItem', 'comments.items', 0)
            ->assertSet('values.comments.items.0.avatar', $image)
            ->call('addItem', 'comments.items')
            ->set('uploads.comments.items.1.avatar', $this->image())
            ->call('save')->assertHasNoErrors();
        $landing->refresh();
        $items = $landing->template_values['comments']['items'];
        $this->assertSame('Keep reader', $items[0]['name']);
        $this->assertSame($image, $items[0]['avatar']);
        $this->assertNotSame($image, $items[1]['avatar']);
        foreach ($items as $item) {
            Storage::disk('landings')->assertExists($landing->activeRelease->storage_path.'/'.$item['avatar']);
        }

        Livewire::test(EditTemplate::class, ['landing' => $landing])
            ->set('values.comments.items.0.avatar', '')
            ->set('uploads.comments.items.1.avatar', $this->image())->call('save')->assertHasNoErrors();
        $landing->refresh();
        $this->assertSame('', $landing->template_values['comments']['items'][0]['avatar']);
        $this->assertNotSame($items[1]['avatar'], $landing->template_values['comments']['items'][1]['avatar']);
        Storage::disk('landings')->assertMissing($landing->activeRelease->storage_path.'/'.$image);
        Storage::disk('landings')->assertMissing($landing->activeRelease->storage_path.'/'.$items[1]['avatar']);
    }

    public function test_failed_template_and_zip_updates_leave_current_release_and_files_untouched(): void
    {
        $template = $this->import($this->definitionFile());
        $landing = $this->createLanding($template);
        $previous = $landing->activeRelease;
        $files = Storage::disk('landings')->allFiles();
        $component = Livewire::test(EditTemplate::class, ['landing' => $landing]);
        $component->set('values.category', 'invalid')->call('save')->assertHasErrors('values.category');
        $component->set('values.category', 'article')->set('values.comments.items.0.avatar', '../private.png')
            ->call('save')->assertHasErrors('values.comments.items.0.avatar');
        $component->set('values.comments.items.0.avatar', '');
        config(['fast-landings.max_extracted_bytes' => 5]);
        $component->call('save')->assertHasErrors('template');
        Livewire::test(Show::class, ['landing' => $landing])
            ->set('archive', UploadedFile::fake()->createWithContent('landing.zip', $this->zip(['index.html' => 'Too large'])->getContent()))
            ->call('deploy')->assertHasErrors('archive');

        $this->assertSame($previous->id, $landing->fresh()->activeRelease->id);
        $this->assertSame($template->id, $landing->fresh()->landing_template_id);
        $this->assertSame($previous->template_values, $landing->fresh()->template_values);
        $this->assertSame($files, Storage::disk('landings')->allFiles());
        $this->assertDatabaseCount('landing_releases', 1);
    }

    public function test_saved_uploads_cannot_be_copied_from_another_landing(): void
    {
        $template = $this->import($this->definitionFile());
        Livewire::test(FromTemplate::class, ['template' => $template])
            ->set('name', 'Other landing')->set('slug', 'other')
            ->set('uploads.comments.items.0.avatar', $this->image())->call('create')->assertHasNoErrors();
        $other = Landing::query()->where('slug', 'other')->sole();
        $landing = $this->createLanding($template);
        Livewire::test(EditTemplate::class, ['landing' => $landing])
            ->set('values.comments.items.0.avatar', $other->template_values['comments']['items'][0]['avatar'])
            ->call('save')->assertHasErrors('values.comments.items.0.avatar');
        $this->assertDatabaseCount('landing_releases', 2);
    }

    public function test_stale_template_editor_cannot_overwrite_a_new_release(): void
    {
        $template = $this->import($this->definitionFile());
        $landing = $this->createLanding($template);
        $component = Livewire::test(EditTemplate::class, ['landing' => $landing]);
        $zip = app(LandingArchiveService::class)->deploy($landing, $this->zip(['index.html' => 'New version']), $this->administrator);

        $component->set('values.title', 'Stale edit')->call('save')->assertHasErrors('template');
        $this->assertSame($zip->id, $landing->fresh()->activeRelease->id);
        $this->assertNull($landing->fresh()->landing_template_id);
        $this->assertDatabaseCount('landing_releases', 2);
    }

    public function test_deleted_template_can_be_replaced_and_its_release_still_activated(): void
    {
        $template = $this->import($this->definitionFile());
        $replacement = $this->import($this->definitionFile());
        $landing = $this->createLanding($template);
        $previous = $landing->activeRelease;
        // The landing must stop using the template before the template can be deleted;
        // activating the older release afterwards restores a snapshot of a deleted template.
        app(LandingArchiveService::class)->deploy($landing, $this->zip(['index.html' => '<h1>File landing</h1>']), $this->administrator);
        app(TemplateArchiveService::class)->delete($template);
        app(LandingArchiveService::class)->activate($landing->fresh(), $previous);
        $landing->refresh();

        Livewire::test(Show::class, ['landing' => $landing])->assertSee('Template no longer available')->assertSee('Use a template');
        Livewire::test(EditTemplate::class, ['landing' => $landing])
            ->assertSet('templateId', '')->set('templateId', $replacement->id)->call('save')->assertHasNoErrors();
        app(LandingArchiveService::class)->activate($landing, $previous);
        $this->assertNull($landing->fresh()->landing_template_id);
        $this->assertSame($previous->template_values, $landing->fresh()->template_values);
        Storage::disk('landings')->assertExists($previous->storage_path.'/index.html');
    }

    public function test_editor_requires_template_selection_and_rechecks_active_user(): void
    {
        $template = $this->import($this->definitionFile());
        $landing = $this->createLanding($template);
        Livewire::test(EditTemplate::class, ['landing' => $landing])
            ->set('templateId', '')->call('save')->assertHasErrors('templateId')
            ->set('templateId', 'missing')->assertHasErrors('templateId');

        $editor = User::factory()->create(['role' => UserRole::Editor, 'is_active' => true]);
        $component = Livewire::actingAs($editor)->test(EditTemplate::class, ['landing' => $landing]);
        $editor->update(['is_active' => false]);
        $component->call('save')->assertForbidden();
        $this->assertDatabaseCount('landing_releases', 1);
    }

    public function test_edit_route_loads_current_values_and_change_template_starts_with_selection(): void
    {
        $template = $this->import($this->definitionFile());
        $landing = $this->createLanding($template);
        $this->get(route('landings.edit-template', $landing))
            ->assertOk()->assertSee('Edit landing content')->assertSee('Save and activate')->assertSee('Appearance');
        $this->get(route('landings.edit-template', ['landing' => $landing, 'change' => 1]))
            ->assertOk()->assertSee('Choose a template')->assertDontSee('Save and activate');
    }

    public function test_snapshot_migration_backfills_only_current_release_and_preserves_partial_index(): void
    {
        $template = $this->import($this->definitionFile());
        $landing = $this->createLanding($template);
        $previous = $landing->activeRelease;
        Livewire::test(EditTemplate::class, ['landing' => $landing])
            ->set('values.title', 'Current title')->call('save')->assertHasNoErrors();
        $current = $landing->fresh()->activeRelease;

        $migration = require database_path('migrations/2026_09_16_120000_add_template_snapshots_to_landing_releases.php');
        $migration->down();
        $migration->up();

        $this->assertSame('Current title', $current->fresh()->template_values['title']);
        $this->assertSame($template->id, $current->fresh()->landing_template_id);
        $this->assertNull($previous->fresh()->template_values);
        Livewire::test(EditTemplate::class, ['landing' => $landing->fresh()])->call('save')->assertHasNoErrors();
        $this->assertDatabaseCount('landing_releases', 3);
    }

    private function createLanding(LandingTemplate $template, string $slug = 'test-article'): Landing
    {
        return app(TemplateLandingService::class)->create(
            $template,
            ['name' => 'Generated article', 'slug' => $slug, 'description' => 'A generated landing.'],
            app(TemplateEngine::class)->defaults($template->definition),
            [],
            $this->administrator,
        );
    }

    private function import(UploadedFile $file): LandingTemplate
    {
        return app(TemplateArchiveService::class)->import($file, $this->administrator);
    }

    private function definitionFile(?string $definition = null, string $extension = 'html'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('template.'.$extension, $definition ?? $this->definition());
    }

    private function definition(): string
    {
        return <<<'TEMPLATE'
@template "Test article" version=1

@type Comment
@param name String = "Reader" label="Reader name" required=true
@param body Text = "First comment" label="Comment text" required=true
@param avatar Image label="Reader avatar"
@endtype

@type Comments
@param items Comment[] label="Reader comments" min_items=1 max_items=3
@endtype

@section appearance "Appearance"
@param title String = "Default title" label="Title" required=true
@param primary Color = "#0f766e" label="Primary"
@param font_size Range = 18 label="Font size" min=12 max=24 step=1
@param category Select = "article" label="Category" options="article:Article|story:Story"
@endsection

@section comments "Comments"
@param comments Comments label="Comments block"
@endsection

@block commentItem(comment: Comment)
<article>
@if comment.avatar
<img src="{{comment.avatar}}" alt="{{comment.name}}">
@endif
<h2>{{comment.name}}</h2><p>{{comment.body}}</p>
</article>
@endblock

@layout
<!doctype html><html><head><title>{{title}}</title></head><body>
<h1>{{title}}</h1>
@each comment in comments.items:
@render commentItem(comment)
@endeach
</body></html>
@endlayout
TEMPLATE;
    }

    private function image(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('avatar.png', base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+j4i8AAAAASUVORK5CYII=',
        ));
    }

    /** @param array<string, string> $files */
    private function zip(array $files, ?callable $configure = null): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'fast-landings-template-');
        $this->assertNotFalse($path);
        $this->temporaryArchives[] = $path;
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        foreach ($files as $name => $contents) {
            $this->assertTrue($zip->addFromString($name, $contents));
        }
        $configure?->__invoke($zip);
        $this->assertTrue($zip->close());

        return new UploadedFile($path, 'template.zip', 'application/zip', null, true);
    }

    private function assertImportRejected(UploadedFile $file): void
    {
        try {
            $this->import($file);
            $this->fail('The invalid template was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('templateUpload', $exception->errors());
        }

        $this->assertDatabaseCount('landing_templates', 0);
        $this->assertSame([], Storage::disk('landings')->allFiles());
    }
}
