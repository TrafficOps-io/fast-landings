<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Livewire\Landings\EditTemplate;
use App\Livewire\Landings\FromTemplate;
use App\Models\Landing;
use App\Models\LandingTemplate;
use App\Models\TemplateMedia;
use App\Models\User;
use App\Services\LandingArchiveService;
use App\Services\Templates\TemplateLandingService;
use App\Services\Templates\TemplateMediaService;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;
use TrafficOps\TemplateDsl\TemplateEngine;
use TrafficOps\TemplateDsl\TemplateSourceParser;

class TemplateEditorsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private LandingTemplate $template;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('landings');
        Storage::fake('editor-media');
        config(['fast-landings.templates.media_disk' => 'editor-media']);
        $this->user = User::factory()->create(['role' => UserRole::Administrator, 'is_active' => true]);
        $this->actingAs($this->user);
        $definition = app(TemplateEngine::class)->validateDefinition(app(TemplateSourceParser::class)->parse(<<<'TPL'
@param body Wysiwyg = "<p>Welcome</p>" required
@param notes Markdown = "**Notes**"
@param items Item[] min_items=1
@type Item
@param text Markdown
@endtype
@layout
<!doctype html><html><body>{{&body}}{{&notes}}
@each item in items
{{&item.text}}
@endeach
</body></html>
@endlayout
TPL));
        $this->template = LandingTemplate::query()->create([
            'name' => 'Editors', 'definition' => $definition, 'asset_paths' => [],
            'storage_path' => 'templates/editors', 'original_name' => 'editors.tpl', 'uploaded_by' => $this->user->id,
        ]);
    }

    public function test_both_editors_render_and_markdown_preview_matches_publication(): void
    {
        $component = Livewire::test(FromTemplate::class, ['template' => $this->template])
            ->assertSee('Rich text')->assertSee('Edit Markdown')->assertSee('Image URL')
            ->set('values.notes', '**Hello** ![Alt](https://example.com/image.png)')
            ->call('previewMarkdown', 'notes');
        $component->assertReturned(app(TemplateEngine::class)->previewRichText(
            $this->template->definition['sections'][0]['fields'][1], '**Hello** ![Alt](https://example.com/image.png)', 'values.notes',
        ));
        $component->set('values.items.0.text', 'First')->call('addItem', 'items')
            ->set('values.items.1.text', '**Second**')->call('removeItem', 'items', 0)
            ->assertSet('values.items.0.text', '**Second**')->assertSet('formVersion', 1);
    }

    public function test_native_uploads_use_the_configured_disk_and_private_preview_route(): void
    {
        Livewire::test(FromTemplate::class, ['template' => $this->template])
            ->set('editorUploads.test_image', UploadedFile::fake()->image('photo.png', 20, 20))
            ->call('uploadEditorImage', 'body', 'test_image')->assertHasNoErrors();
        $media = TemplateMedia::query()->sole();
        $this->assertSame('editor-media', $media->disk);
        Storage::disk('editor-media')->assertExists($media->path);
        $url = route('templates.media', ['filename' => $media->filename]);
        $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/png')->assertHeader('X-Content-Type-Options', 'nosniff');
        auth()->logout();
        $this->get($url)->assertRedirect(route('login'));
    }

    public function test_inline_images_are_bundled_and_retained_even_after_original_disk_files_are_removed(): void
    {
        $media = app(TemplateMediaService::class)->upload(UploadedFile::fake()->image('photo.png', 20, 20), $this->user);
        $src = '_media/'.$media->filename;
        // S3-like storage: publication may use exists/readStream, but never path().
        $disk = Storage::disk('editor-media');
        $remote = \Mockery::mock(Filesystem::class);
        $remote->shouldReceive('exists')->with($media->path)->andReturnUsing(fn () => $disk->exists($media->path));
        $remote->shouldReceive('readStream')->with($media->path)->andReturnUsing(fn () => $disk->readStream($media->path));
        Storage::set('editor-media', $remote);
        $values = ['body' => '<p><strong>Article</strong></p><img src="'.$src.'" alt="Photo">', 'notes' => '![Photo]('.$src.')'];
        $landing = app(TemplateLandingService::class)->create($this->template, ['name' => 'Editors', 'slug' => 'editors'], $values, [], $this->user);
        $first = $landing->activeRelease;
        Storage::disk('landings')->assertExists($first->storage_path.'/'.$src);
        $this->assertStringContainsString('<strong>Article</strong>', Storage::disk('landings')->get($first->storage_path.'/index.html'));
        $this->assertSame('![Photo]('.$src.')', $landing->template_values['notes']);
        $disk->delete($media->path);
        config(['fast-landings.templates.media_disk' => 'local']);
        Livewire::test(EditTemplate::class, ['landing' => $landing])->assertSet('values.notes', $values['notes'])
            ->set('values.notes', 'Updated ![Photo]('.$src.')')->call('save')->assertHasNoErrors();
        $next = $landing->refresh()->activeRelease;
        $this->assertNotSame($first->id, $next->id);
        Storage::disk('landings')->assertExists($next->storage_path.'/'.$src);
        Storage::disk('landings')->assertExists($first->storage_path.'/'.$src);
        $this->get(route('templates.media', ['filename' => $media->filename, 'release' => $next->id]))->assertOk();
        app(LandingArchiveService::class)->activate($landing, $first);
        $this->assertSame($values['notes'], $landing->refresh()->template_values['notes']);
        Livewire::test(EditTemplate::class, ['landing' => $landing])->assertSet('values.notes', $values['notes']);
    }

    public function test_non_image_uploads_and_unknown_fields_are_rejected(): void
    {
        Livewire::test(FromTemplate::class, ['template' => $this->template])
            ->set('editorUploads.test_image', UploadedFile::fake()->createWithContent('bad.svg', '<svg onload="alert(1)"></svg>'))
            ->call('uploadEditorImage', 'body', 'test_image')->assertHasErrors('editorImage');
        $this->assertDatabaseCount('template_media', 0);
        Livewire::test(FromTemplate::class, ['template' => $this->template])
            ->call('previewMarkdown', 'items.9.text')->assertStatus(422);
    }

    public function test_missing_local_editor_images_fail_without_publishing(): void
    {
        Livewire::test(FromTemplate::class, ['template' => $this->template])
            ->set('name', 'Missing')->set('slug', 'missing')->set('values.notes', '![Missing](_media/MISSING.png)')
            ->call('create')->assertHasErrors('values.notes');
        $this->assertSame(0, Landing::query()->count());
    }

    public function test_optional_select_can_be_cleared_while_required_and_declared_default_choices_are_preserved(): void
    {
        $this->template->update(['definition' => app(TemplateSourceParser::class)->parse(<<<'TPL'
@param optionalChoice Select options="wide:Wide|narrow:Narrow"
@param requiredChoice Select options="wide:Wide|narrow:Narrow" required
@param declaredChoice Select = "narrow" options="wide:Wide|narrow:Narrow"
@layout
[{{optionalChoice}}|{{requiredChoice}}|{{declaredChoice}}]
@endlayout
TPL)]);
        $component = Livewire::test(FromTemplate::class, ['template' => $this->template])
            ->assertSet('values.optionalChoice', '')->assertSet('values.declaredChoice', 'narrow');
        $document = new \DOMDocument;
        @$document->loadHTML($component->html());
        $selects = [];
        foreach ($document->getElementsByTagName('select') as $select) {
            $selects[$select->getAttribute('wire:model.live')] = $select;
        }
        $optionalEmpty = $selects['values.optionalChoice']->getElementsByTagName('option')->item(0);
        $this->assertSame('', $optionalEmpty->getAttribute('value'));
        $this->assertFalse($optionalEmpty->hasAttribute('disabled'));
        $this->assertTrue($optionalEmpty->hasAttribute('selected'));
        $requiredEmpty = $selects['values.requiredChoice']->getElementsByTagName('option')->item(0);
        $this->assertTrue($requiredEmpty->hasAttribute('disabled'));
        $this->assertTrue($selects['values.requiredChoice']->hasAttribute('required'));
        $selectedDefaults = [];
        foreach ($selects['values.declaredChoice']->getElementsByTagName('option') as $option) {
            if ($option->hasAttribute('selected')) {
                $selectedDefaults[] = $option->getAttribute('value');
            }
        }
        $this->assertSame(['narrow'], $selectedDefaults);
        $component->set('name', 'Select choices')->set('slug', 'select-choices')
            ->set('values.optionalChoice', 'wide')->set('values.optionalChoice', '')
            ->call('create')->assertHasErrors('values.requiredChoice');
        $this->assertDatabaseCount('landings', 0);
        $component->set('values.requiredChoice', 'wide')->call('create')->assertHasNoErrors();
        $this->assertSame(['optionalChoice' => '', 'requiredChoice' => 'wide', 'declaredChoice' => 'narrow'], Landing::query()->sole()->template_values);
    }

    public function test_creation_and_editing_report_removed_fields_without_persisting_unknown_values(): void
    {
        Livewire::test(FromTemplate::class, ['template' => $this->template])
            ->set('name', 'Retired fields')->set('slug', 'retired-fields')
            ->set('values.oldHeadline', 'Old headline')->set('values.items.0.oldCopy', 'Old copy')
            ->call('create')->assertHasNoErrors();
        $landing = Landing::query()->sole();
        $this->assertArrayNotHasKey('oldHeadline', $landing->template_values);
        $this->assertArrayNotHasKey('oldCopy', $landing->template_values['items'][0]);
        $this->assertStringContainsString('Old Headline', session('saved'));
        $this->assertStringContainsString('Old Copy', session('saved'));
        $this->assertStringNotContainsString('items.0.oldCopy', session('saved'));
        $this->get(route('landings.show', $landing))->assertSee('Old Headline')->assertSee('Old Copy');

        $release = $landing->activeRelease;
        $values = $release->template_values;
        $values['removedTitle'] = 'Retired title';
        $release->update(['template_values' => $values]);
        Livewire::test(EditTemplate::class, ['landing' => $landing])->call('save')->assertHasNoErrors();
        $this->assertArrayNotHasKey('removedTitle', $landing->refresh()->template_values);
        $this->assertStringContainsString('Removed Title', session('saved'));
    }
}
