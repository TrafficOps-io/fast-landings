<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Livewire\Landings\FromTemplate;
use App\Livewire\Landings\Index;
use App\Livewire\Landings\Show;
use App\Models\Landing;
use App\Models\User;
use App\Services\Templates\TemplateArchiveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Tags\Tag;
use Tests\TestCase;
use ZipArchive;

class LandingLibraryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('landings');
        $this->actingAs(User::factory()->create(['role' => UserRole::Administrator]));
    }

    public function test_cards_display_tags_and_filter_by_tag_search_and_status(): void
    {
        $summer = Landing::create(['name' => 'Summer offer', 'slug' => 'summer', 'is_active' => true]);
        $summer->syncTags(['Travel', 'Европа']);
        $winter = Landing::create(['name' => 'Winter offer', 'slug' => 'winter', 'is_active' => false]);
        $winter->syncTags(['Winter']);

        Livewire::test(Index::class)
            ->assertSeeHtml('data-testid="landing-grid"')
            ->assertSee('Summer offer')->assertSee('Winter offer')->assertSee('Европа')
            ->set('tag', (string) $summer->tags->first()->id)
            ->assertSee('Summer offer')->assertDontSee('Winter offer')
            ->set('search', 'Winter')->assertSee('No matching landings')
            ->call('clearFilters')->set('search', 'Европа')
            ->assertSee('Summer offer')->assertDontSee('Winter offer')
            ->call('clearFilters')->set('status', 'paused')
            ->assertSee('Winter offer')->assertDontSee('Summer offer');
    }

    public function test_filters_reset_pagination_and_invalid_tag_is_safe(): void
    {
        for ($i = 0; $i < 13; $i++) {
            Landing::create(['name' => 'Landing '.$i, 'slug' => 'landing-'.$i]);
        }
        Livewire::test(Index::class)->assertViewHas('landings', fn ($landings) => $landings->count() === 12)
            ->call('setPage', 2)->assertViewHas('landings', fn ($landings) => $landings->count() === 1)
            ->set('search', 'Landing 12')->assertSet('paginators.page', 1)->assertSee('Landing 12')
            ->set('tag', 'not-a-number')->assertSee('No matching landings');
    }

    public function test_settings_sync_trimmed_deduplicated_tags_and_remove_them(): void
    {
        $landing = Landing::create(['name' => 'Tagged landing', 'slug' => 'tagged', 'is_active' => true]);
        $landing->syncTags(['Original']);

        $component = Livewire::test(Show::class, ['landing' => $landing])
            ->assertSet('tags', ['Original'])
            ->set('tags', [' Travel ', 'Travel', 'Україна'])
            ->call('save')->assertHasNoErrors();

        $this->assertSame(['Travel', 'Україна'], $landing->fresh()->tags->pluck('name')->all());
        $this->assertDatabaseHas('taggables', ['taggable_id' => $landing->id, 'taggable_type' => Landing::class]);
        $component->set('tags', [str_repeat('a', 61)])->call('save')->assertHasErrors('tags.0');
        $this->assertCount(2, $landing->fresh()->tags);
        $component->set('tags', [])->call('save')->assertHasNoErrors();
        $this->assertCount(0, $landing->fresh()->tags);
    }

    public function test_tag_count_is_validated_before_changing_settings(): void
    {
        $landing = Landing::create(['name' => 'Unchanged', 'slug' => 'unchanged', 'is_active' => true]);
        Livewire::test(Show::class, ['landing' => $landing])->set('name', 'Changed')
            ->set('tags', array_map(fn ($i) => 'tag '.$i, range(1, 21)))
            ->call('save')->assertHasErrors('tags');
        $this->assertSame('Unchanged', $landing->fresh()->name);
    }

    public function test_zip_creation_and_template_creation_persist_tags(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'tagged-landing-');
        try {
            $zip = new ZipArchive;
            $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
            $zip->addFromString('index.html', '<h1>Tagged ZIP landing</h1>');
            $zip->close();
            Livewire::test(Index::class)->set('name', 'ZIP landing')->set('slug', 'zip-landing')
                ->set('tags', ['Shared', 'ZIP'])
                ->set('archive', UploadedFile::fake()->createWithContent('landing.zip', file_get_contents($path)))
                ->call('create')->assertHasNoErrors();
        } finally {
            @unlink($path);
        }

        $template = app(TemplateArchiveService::class)->import(UploadedFile::fake()->createWithContent('template.tpl', <<<'TPL'
@template "Tagged template" version=1
@param title String = "Hello"
@layout
<h1>{{title}}</h1>
@endlayout
TPL), auth()->user());

        Livewire::test(FromTemplate::class, ['template' => $template])
            ->set('name', 'Template landing')->set('slug', 'template-landing')
            ->set('tags', ['Shared', 'Template'])->call('create')->assertHasNoErrors();

        $this->assertSame(2, Landing::withAnyTags(['Shared'])->count());
        $this->assertSame(1, Tag::query()->containing('Shared')->count());
        $this->assertSame(['Shared', 'ZIP'], Landing::where('slug', 'zip-landing')->first()->tags->pluck('name')->all());
    }

    public function test_deleting_landing_detaches_tags_without_deleting_shared_tags(): void
    {
        $landing = Landing::create(['name' => 'Tagged landing', 'slug' => 'delete-tagged', 'is_active' => true]);
        $landing->syncTags(['Shared']);
        $other = Landing::create(['name' => 'Other', 'slug' => 'other']);
        $other->syncTags(['Shared']);
        Livewire::test(Show::class, ['landing' => $landing])->call('deleteLanding')->assertHasNoErrors();
        $this->assertDatabaseMissing('taggables', ['taggable_id' => $landing->id]);
        $this->assertSame(['Shared'], $other->fresh()->tags->pluck('name')->all());
    }
}
