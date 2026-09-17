<?php

namespace Tests\Feature;

use App\Enums\AiProvider;
use App\Enums\UserRole;
use App\Jobs\GenerateTemplateContent;
use App\Livewire\Landings\FromTemplate;
use App\Models\AiIntegration;
use App\Models\Installation;
use App\Models\LandingTemplate;
use App\Models\TemplateGeneration;
use App\Models\User;
use App\Services\Ai\AiProviderException;
use App\Services\Templates\TemplateContentGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;
use TrafficOps\TemplateDsl\TemplateEngine;
use TrafficOps\TemplateDsl\TemplateSourceParser;

class TemplateGenerationWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private LandingTemplate $template;

    private AiIntegration $integration;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::preventStrayRequests();
        Storage::fake('local');
        Storage::fake('landings');
        $this->user = User::factory()->create(['role' => UserRole::Editor]);
        $this->actingAs($this->user);
        $installation = Installation::query()->create(['name' => 'Test', 'domain' => 'fast-landings.test', 'origin_target' => 'origin.fast-landings.test']);
        $this->integration = $installation->aiIntegrations()->create(['name' => 'Creative AI', 'provider' => AiProvider::OpenAi, 'api_key' => 'never-render-this-key']);
        $definition = app(TemplateEngine::class)->validateDefinition(app(TemplateSourceParser::class)->parse(<<<'TPL'
@param title String required aiInstructions="Write a heading"
@param hero Image
@param comments Comment[] min_items=0 max_items=5
@type Comment
@param text String
@param avatar Image
@endtype
@layout
<h1>{{title}}</h1><img src="{{hero}}">
@each comment in comments
<p>{{comment.text}}</p>
@endeach
@endlayout
TPL));
        $this->template = LandingTemplate::query()->create(['name' => 'Article', 'definition' => $definition, 'asset_paths' => [], 'storage_path' => 'templates/article', 'original_name' => 'article.tpl', 'uploaded_by' => $this->user->id]);
    }

    public function test_editor_can_queue_with_separate_private_uploads_without_changing_or_publishing_values(): void
    {
        $component = $this->workflowComponent()->assertSee('Images for the site')->assertSee('Reference images')->assertDontSee('never-render-this-key')
            ->set('aiArrayCounts.0', 3)->set('aiImageFields', ['comments.*.avatar'])
            ->set('aiSiteImages', [UploadedFile::fake()->image('product.png')])
            ->set('aiReferenceImages', [UploadedFile::fake()->image('inspiration.png')])
            ->call('generateContent')->assertHasNoErrors()->assertSet('values.title', '');
        $generation = TemplateGeneration::query()->sole();
        $this->assertSame(['comments' => 3], $generation->options['array_counts']);
        $this->assertSame(['comments.*.avatar'], $generation->options['image_fields']);
        $this->assertSame('product.png', $generation->options['site_images'][0]['name']);
        $this->assertSame('inspiration.png', $generation->options['reference_images'][0]['name']);
        Storage::disk('local')->assertExists($generation->options['site_images'][0]['path']);
        Storage::disk('local')->assertExists($generation->options['reference_images'][0]['path']);
        $this->assertDatabaseCount('landings', 0);
        Queue::assertPushed(GenerateTemplateContent::class, fn ($job) => $job->generationId === $generation->id && $job->tries === 1);
        $this->assertStringNotContainsString('never-render-this-key', json_encode($component->snapshot, JSON_THROW_ON_ERROR));
    }

    public function test_completed_result_needs_explicit_apply_and_keeps_edits_until_then(): void
    {
        $component = $this->workflowComponent()->call('generateContent')->set('values.title', 'Manual edit');
        $generation = TemplateGeneration::query()->sole();
        $result = ['title' => 'Generated heading', 'hero' => '', 'comments' => [['text' => 'Hello', 'avatar' => '']]];
        $generator = Mockery::mock(TemplateContentGenerator::class);
        $generator->shouldReceive('generate')->once()->withArgs(fn ($template, $integration, $options, $user) => $template->is($this->template) && $integration->is($this->integration) && $user->is($this->user))->andReturn($result);
        (new GenerateTemplateContent($generation->id))->handle($generator);
        (new GenerateTemplateContent($generation->id))->handle($generator);
        $component->call('refreshGeneration')->assertSet('values.title', 'Manual edit')->assertSee('Generated content is ready')
            ->call('applyGeneration')->assertHasNoErrors()->assertSet('values.title', 'Generated heading')->assertSet('formVersion', 1);
        $this->assertNotNull($generation->fresh()->applied_at);
        $this->workflowComponent()->assertSet('values.title', '')->assertSee('Apply again')
            ->call('applyGeneration')->assertHasNoErrors()->assertSet('values.title', 'Generated heading');
        $this->assertDatabaseCount('landings', 0);
    }

    public function test_duplicate_submission_and_second_open_page_cannot_queue_duplicate_paid_work(): void
    {
        $component = $this->workflowComponent()->call('generateContent')->assertHasNoErrors();
        $component->call('generateContent')->assertHasErrors('generation');
        $this->workflowComponent()->assertSet('generationId', TemplateGeneration::query()->sole()->id)
            ->call('generateContent')->assertHasErrors('generation');
        Queue::assertPushed(GenerateTemplateContent::class, 1);
    }

    public function test_existing_field_uploads_are_preserved_when_generation_or_apply_is_attempted(): void
    {
        $component = $this->workflowComponent()->set('uploads.hero', UploadedFile::fake()->image('hero.png'))
            ->call('generateContent')->assertHasErrors('generation');
        Queue::assertNothingPushed();
        $component->call('removeUpload', 'hero')->call('generateContent')->assertHasNoErrors();
        TemplateGeneration::query()->sole()->update(['status' => 'completed', 'result' => ['title' => 'Generated']]);
        $component->set('uploads.hero', UploadedFile::fake()->image('new-hero.png'))->call('applyGeneration')->assertHasErrors('generation')
            ->assertSet('values.title', '');
        $this->assertNull(TemplateGeneration::query()->sole()->applied_at);
    }

    public function test_schema_counts_unknown_image_fields_and_invalid_uploads_are_rejected_before_dispatch(): void
    {
        $this->workflowComponent()->set('aiArrayCounts.0', 6)->call('generateContent')->assertHasErrors('aiArrayCounts.0');
        $this->workflowComponent()->set('aiImageFields', ['title'])->call('generateContent')->assertHasErrors('aiImageFields.0');
        $this->workflowComponent()->set('aiSiteImages', [UploadedFile::fake()->createWithContent('bad.svg', '<svg/>')])
            ->call('generateContent')->assertHasErrors('aiSiteImages.0');
        $this->workflowComponent()->set('aiReferenceImages', array_map(fn () => UploadedFile::fake()->image('many.png'), range(1, 9)))
            ->call('generateContent')->assertHasErrors('aiReferenceImages');
        Queue::assertNothingPushed();
    }

    public function test_provider_capabilities_are_checked_before_queuing(): void
    {
        $this->integration->update(['provider' => AiProvider::Anthropic]);
        $this->workflowComponent()->set('aiImageFields', ['hero'])->call('generateContent')->assertHasErrors('aiImageFields');
        $this->integration->update(['provider' => AiProvider::Custom]);
        $this->workflowComponent()->set('aiReferenceImages', [UploadedFile::fake()->image('reference.png')])->call('generateContent')->assertHasErrors('generation');
        Queue::assertNothingPushed();
    }

    public function test_failures_do_not_expose_provider_payloads_and_always_remove_staged_images(): void
    {
        $component = $this->workflowComponent()->set('aiReferenceImages', [UploadedFile::fake()->image('private.png')])->call('generateContent');
        $generation = TemplateGeneration::query()->sole();
        $path = $generation->options['reference_images'][0]['path'];
        $generator = Mockery::mock(TemplateContentGenerator::class);
        $generator->shouldReceive('generate')->once()->andThrow(new \RuntimeException('Secret provider payload never-render-this-key'));
        (new GenerateTemplateContent($generation->id))->handle($generator);
        $component->call('refreshGeneration')->assertSee('Content generation failed')->assertDontSee('Secret provider payload')->assertDontSee('never-render-this-key');
        Storage::disk('local')->assertMissing($path);
        $this->assertArrayNotHasKey('reference_images', $generation->fresh()->options);
        $this->assertSame('failed', $generation->fresh()->status);
        $this->assertStringNotContainsString('never-render-this-key', $generation->fresh()->error);
    }

    public function test_safe_provider_error_is_shown_and_timeout_cleanup_marks_failed(): void
    {
        $this->workflowComponent()->call('generateContent');
        $generation = TemplateGeneration::query()->sole();
        $generator = Mockery::mock(TemplateContentGenerator::class);
        $generator->shouldReceive('generate')->once()->andThrow(new AiProviderException('The AI connection rejected its credentials.'));
        (new GenerateTemplateContent($generation->id))->handle($generator);
        $this->assertSame('The AI connection rejected its credentials.', $generation->fresh()->error);
        $this->workflowComponent()->set('aiReferenceImages', [UploadedFile::fake()->image('private.png')])->call('generateContent');
        $pending = TemplateGeneration::query()->where('status', 'pending')->sole();
        $path = $pending->options['reference_images'][0]['path'];
        (new GenerateTemplateContent($pending->id))->failed(new \RuntimeException('sensitive'));
        $this->assertSame('failed', $pending->fresh()->status);
        Storage::disk('local')->assertMissing($path);
        $this->assertLessThan(config('queue.connections.database.retry_after'), (new GenerateTemplateContent($pending->id))->timeout);
    }

    public function test_revoked_access_and_deleted_integrations_prevent_paid_calls(): void
    {
        $component = $this->workflowComponent()->call('generateContent');
        $generation = TemplateGeneration::query()->sole();
        $this->user->update(['is_active' => false]);
        $generator = Mockery::mock(TemplateContentGenerator::class);
        $generator->shouldNotReceive('generate');
        (new GenerateTemplateContent($generation->id))->handle($generator);
        $this->assertSame('failed', $generation->fresh()->status);
        $component->call('applyGeneration')->assertForbidden();
        $this->user->update(['is_active' => true]);
        $this->workflowComponent()->call('generateContent');
        $pending = TemplateGeneration::query()->where('status', 'pending')->sole();
        $this->integration->delete();
        (new GenerateTemplateContent($pending->id))->handle($generator);
        $this->assertSame('failed', $pending->fresh()->status);
    }

    public function test_template_changes_reject_stale_queued_and_completed_results(): void
    {
        $component = $this->workflowComponent()->call('generateContent');
        $generation = TemplateGeneration::query()->sole();
        $definition = $this->template->definition;
        $definition['sections'][0]['fields'][0]['aiInstructions'] = 'Changed instructions';
        $this->template->update(['definition' => $definition]);
        $generator = Mockery::mock(TemplateContentGenerator::class);
        $generator->shouldNotReceive('generate');
        (new GenerateTemplateContent($generation->id))->handle($generator);
        $this->assertSame('failed', $generation->fresh()->status);
        $generation->update(['status' => 'completed', 'result' => ['title' => 'Stale result']]);
        $component->call('applyGeneration')->assertHasErrors('generation')->assertSet('values.title', '');
    }

    public function test_template_changed_since_page_load_cannot_start_generation_with_stale_controls(): void
    {
        $component = $this->workflowComponent();
        $definition = $this->template->definition;
        $definition['sections'][0]['fields'][0]['aiInstructions'] = 'Updated instructions';
        $this->template->update(['definition' => $definition]);
        $component->call('generateContent')->assertHasErrors('generation')->assertSee('Reload the page before generating');
        Queue::assertNothingPushed();
    }

    public function test_generation_identifier_is_locked_and_results_are_scoped_to_the_current_user(): void
    {
        $this->workflowComponent()->call('generateContent');
        $generation = TemplateGeneration::query()->sole();
        $other = User::factory()->create();
        Livewire::actingAs($other)->test(FromTemplate::class, ['template' => $this->template])
            ->assertSet('generationId', null)->call('applyGeneration')->assertHasErrors('generation');
        $this->expectException(CannotUpdateLockedPropertyException::class);
        Livewire::test(FromTemplate::class, ['template' => $this->template])->set('generationId', $generation->id);
    }

    public function test_lost_workers_and_orphaned_pending_requests_can_be_recovered_without_duplicate_calls(): void
    {
        $component = $this->workflowComponent()->set('aiReferenceImages', [UploadedFile::fake()->image('private.png')])->call('generateContent');
        $generation = TemplateGeneration::query()->sole();
        $path = $generation->options['reference_images'][0]['path'];
        $generation->update(['status' => 'running', 'started_at' => now()->subMinutes(32)]);
        $component->call('refreshGeneration')->assertSee('stopped or timed out');
        $this->assertSame('failed', $generation->fresh()->status);
        Storage::disk('local')->assertMissing($path);
        $component->call('generateContent')->assertHasNoErrors();
        $pending = TemplateGeneration::query()->where('status', 'pending')->sole();
        $pending->update(['created_at' => now()->subDays(2)]);
        $component->call('refreshGeneration')->assertSee('Check that the queue worker is running');
        $this->assertSame('failed', $pending->fresh()->status);
        $generator = Mockery::mock(TemplateContentGenerator::class);
        $generator->shouldNotReceive('generate');
        (new GenerateTemplateContent($generation->id))->handle($generator);
        (new GenerateTemplateContent($pending->id))->handle($generator);
    }

    public function test_missing_connection_and_inactive_user_cannot_start_generation(): void
    {
        $this->workflowComponent()->set('aiIntegrationId', '01K00000000000000000000000')->call('generateContent')->assertHasErrors('aiIntegrationId');
        $component = $this->workflowComponent();
        $this->user->update(['is_active' => false]);
        $component->call('generateContent')->assertForbidden();
        Queue::assertNothingPushed();
    }

    private function workflowComponent()
    {
        return Livewire::test(FromTemplate::class, ['template' => $this->template])
            ->set('aiIntegrationId', $this->integration->id)->set('aiPrompt', 'Write an engaging landing with three comments.');
    }
}
