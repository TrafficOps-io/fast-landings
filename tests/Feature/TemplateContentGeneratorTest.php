<?php

namespace Tests\Feature;

use App\Models\AiIntegration;
use App\Models\Installation;
use App\Models\LandingTemplate;
use App\Models\TemplateMedia;
use App\Models\User;
use App\Services\Ai\AiProviderClient;
use App\Services\Templates\TemplateContentGenerator;
use App\Services\Templates\TemplateLandingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Mockery;
use Tests\TestCase;
use TrafficOps\TemplateDsl\TemplateEngine;
use TrafficOps\TemplateDsl\TemplateSourceParser;

class TemplateContentGeneratorTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private AiIntegration $integration;

    private LandingTemplate $template;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('landings');
        $this->user = User::factory()->create();
        $installation = Installation::query()->create(['name' => 'Test', 'domain' => 'fast-landings.test', 'origin_target' => 'origin.fast-landings.test']);
        $this->integration = $installation->aiIntegrations()->create(['name' => 'AI', 'provider' => 'openai', 'api_key' => 'secret']);
        $definition = app(TemplateSourceParser::class)->parse(<<<'TPL'
@template "AI article"
@type Comment
@param name String required=true aiInstructions="Invent a distinct first name."
@param avatar Image aspect_ratio="1:1" aiInstructions="A natural portrait."
@endtype
@param title String required=true aiInstructions="Write a concise title."
@param body Markdown
@param cover Image
@param comments Comment[] min_items=0 max_items=10 aiInstructions="Use varied voices."
@block heading(title: String) aiInstructions="Introduce the article briefly."
<h1>{{title}}</h1>
@endblock
@layout
@render heading(title)
{{body}}
<img src="{{cover}}">
@each comment in comments:
<p>{{comment.name}}</p><img src="{{comment.avatar}}">
@endeach
@endlayout
TPL);
        $this->template = LandingTemplate::query()->create(['name' => 'AI article', 'definition' => $definition,
            'asset_paths' => [], 'storage_path' => 'templates/ai', 'original_name' => 'ai.tpl', 'uploaded_by' => $this->user->id]);
    }

    public function test_schema_lists_nested_arrays_and_image_fields(): void
    {
        $fields = app(TemplateContentGenerator::class)->fields($this->template->definition);
        $this->assertSame(['comments'], array_column($fields['arrays'], 'path'));
        $this->assertSame(['cover', 'comments.*.avatar'], array_column($fields['images'], 'path'));
    }

    public function test_prompt_includes_tpl_rules_field_and_block_guidance_and_exact_counts(): void
    {
        $client = $this->mock(AiProviderClient::class);
        $client->shouldReceive('text')->once()->withArgs(function ($integration, $system, $prompt, $images) {
            $this->assertStringContainsString('@section', $system);
            $this->assertStringContainsString('REFERENCE_ONLY', $system);
            $this->assertStringContainsString('Write a concise title.', $prompt);
            $this->assertStringContainsString('Use varied voices.', $prompt);
            $this->assertStringContainsString('Introduce the article briefly.', $prompt);
            $this->assertSame(['comments' => 2], json_decode($prompt, true)['array_counts']);
            $this->assertSame([], $images);

            return true;
        })->andReturn($this->response(2));
        $values = $this->generate(['array_counts' => ['comments' => 2]]);
        $this->assertSame('Generated title', $values['title']);
        $this->assertCount(2, $values['comments']);
        $this->assertDatabaseCount('template_media', 0);
        $this->assertDatabaseCount('landings', 0);
    }

    public function test_site_images_are_packaged_but_references_stay_private(): void
    {
        $site = $this->image('product.png', 400, 200);
        $reference = $this->image('reference.png', 100, 100);
        $client = $this->mock(AiProviderClient::class);
        $client->shouldReceive('supportsVision')->once()->andReturnTrue();
        $client->shouldReceive('text')->once()->withArgs(function ($integration, $system, $prompt, $images) {
            $this->assertCount(2, $images);
            $this->assertStringStartsWith('SITE_IMAGE', $images[0]['label']);
            $this->assertStringStartsWith('REFERENCE_ONLY', $images[1]['label']);
            $context = json_decode($prompt, true);
            $this->assertSame(['_ai_site/1'], $context['available_image_sources']);
            $this->assertStringNotContainsString('ai-inputs/', $prompt);

            return true;
        })->andReturn(json_encode(['title' => 'Product', 'body' => '![Product](_ai_site/1)', 'cover' => '_ai_site/1', 'comments' => []]));
        $values = $this->generate(['site_images' => [$site], 'reference_images' => [$reference]]);
        $this->assertStringStartsWith('_media/', $values['cover']);
        $this->assertStringNotContainsString('_ai_site', $values['body']);
        $this->assertDatabaseCount('template_media', 2);
        $landing = app(TemplateLandingService::class)->create($this->template, ['name' => 'Product', 'slug' => 'product'], $values, [], $this->user);
        $disk = Storage::disk('landings');
        $html = $disk->get($landing->activeRelease->storage_path.'/index.html');
        $this->assertStringNotContainsString('reference', $html);
        $this->assertCount(3, $disk->allFiles($landing->activeRelease->storage_path));
    }

    public function test_only_selected_images_are_generated_after_array_expansion_with_parent_guidance(): void
    {
        $reference = $this->image('mood.png', 100, 100);
        $generated = UploadedFile::fake()->image('generated.png', 400, 200);
        $image = file_get_contents($generated->getRealPath());
        $client = $this->mock(AiProviderClient::class);
        $client->shouldReceive('supportsImages')->once()->andReturnTrue();
        $client->shouldReceive('supportsVision')->once()->andReturnTrue();
        $client->shouldReceive('text')->once()->andReturn($this->response(2));
        $client->shouldReceive('generateImage')->twice()->withArgs(function ($integration, $prompt, $references) {
            $this->assertStringContainsString('Use varied voices.', $prompt);
            $this->assertStringContainsString('A natural portrait.', $prompt);
            $this->assertStringContainsString('comments.', $prompt);
            $this->assertCount(1, $references);

            return true;
        })->andReturn(['bytes' => $image, 'mime_type' => 'image/png']);
        $values = $this->generate(['array_counts' => ['comments' => 2], 'image_fields' => ['comments.*.avatar'], 'reference_images' => [$reference]]);
        $this->assertSame('', $values['cover']);
        foreach ($values['comments'] as $comment) {
            $this->assertStringStartsWith('_media/', $comment['avatar']);
            $media = TemplateMedia::query()->where('filename', substr($comment['avatar'], 7))->sole();
            [$width, $height] = getimagesizefromstring(Storage::disk($media->disk)->get($media->path));
            $this->assertSame($width, $height);
        }
    }

    public function test_failed_image_generation_rolls_back_previously_stored_media(): void
    {
        $site = $this->image('site.png', 10, 10);
        $client = $this->mock(AiProviderClient::class);
        $client->shouldReceive('supportsImages')->andReturnTrue();
        $client->shouldReceive('supportsVision')->andReturnTrue();
        $client->shouldReceive('text')->once()->andReturn(json_encode(['title' => 'Title', 'body' => '![Site](_ai_site/1)', 'cover' => '', 'comments' => []]));
        $client->shouldReceive('generateImage')->once()->andThrow(ValidationException::withMessages(['generation' => 'Provider unavailable.']));
        try {
            $this->generate(['site_images' => [$site], 'image_fields' => ['cover']]);
            $this->fail('Expected an image generation failure.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('template_media', 0);
            $this->assertSame([], Storage::disk('local')->allFiles('template-media'));
        }
    }

    public function test_wrong_array_count_and_invalid_output_fail_without_image_requests(): void
    {
        foreach ([$this->response(1), '{"title": "Title", "unknown": "unexpected"}', '<html>not JSON</html>', '[]', '{"title":"Title","cover":"https://invented.test/secret.png"}'] as $response) {
            $client = Mockery::mock(AiProviderClient::class);
            $client->shouldReceive('supportsImages')->andReturnTrue();
            $client->shouldReceive('text')->once()->andReturn($response);
            $this->app->instance(AiProviderClient::class, $client);
            try {
                $this->generate(['array_counts' => ['comments' => 2], 'image_fields' => ['cover']]);
                $this->fail('Expected invalid generated content to fail.');
            } catch (ValidationException) {
                $this->assertDatabaseCount('template_media', 0);
            }
        }
    }

    public function test_unapproved_image_source_is_rejected_in_rich_text(): void
    {
        $client = $this->mock(AiProviderClient::class);
        $client->shouldReceive('text')->once()->andReturn('{"title":"Title","body":"![Reference](reference.png)"}');
        $this->expectException(ValidationException::class);
        $this->generate();
    }

    public function test_image_count_limit_is_checked_before_image_generation(): void
    {
        $client = $this->mock(AiProviderClient::class);
        $client->shouldReceive('supportsImages')->once()->andReturnTrue();
        $client->shouldReceive('text')->once()->andReturn($this->response(9));
        $this->expectException(ValidationException::class);
        $this->generate(['image_fields' => ['comments.*.avatar']]);
    }

    public function test_unknown_fields_counts_and_unsupported_capabilities_fail_before_requests(): void
    {
        foreach ([['array_counts' => ['title' => 2]], ['array_counts' => ['comments' => 11]], ['image_fields' => ['title']]] as $options) {
            $this->app->instance(AiProviderClient::class, Mockery::mock(AiProviderClient::class));
            try {
                $this->generate($options);
                $this->fail('Expected invalid generation options.');
            } catch (ValidationException) {
                $this->assertDatabaseCount('template_media', 0);
            }
        }
    }

    private function generate(array $options = []): array
    {
        return app(TemplateContentGenerator::class)->generate($this->template, $this->integration, [
            'prompt' => 'Create a product article with varied comments.',
            'values' => app(TemplateEngine::class)->defaults($this->template->definition),
            ...$options,
        ], $this->user);
    }

    private function response(int $comments): string
    {
        return json_encode(['title' => 'Generated title', 'body' => 'Useful article.', 'cover' => '',
            'comments' => array_fill(0, $comments, ['name' => 'Alex', 'avatar' => ''])]);
    }

    private function image(string $name, int $width, int $height): array
    {
        $file = UploadedFile::fake()->image($name, $width, $height);
        $path = 'ai-inputs/'.$name;
        Storage::disk('local')->put($path, file_get_contents($file->getRealPath()));

        return ['disk' => 'local', 'path' => $path, 'name' => $name];
    }
}
