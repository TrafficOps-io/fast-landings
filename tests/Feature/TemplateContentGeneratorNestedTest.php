<?php

namespace Tests\Feature;

use App\Models\AiIntegration;
use App\Models\Installation;
use App\Models\LandingTemplate;
use App\Models\User;
use App\Services\Ai\AiProviderClient;
use App\Services\Templates\TemplateContentGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
use TrafficOps\TemplateDsl\TemplateEngine;
use TrafficOps\TemplateDsl\TemplateSourceParser;

class TemplateContentGeneratorNestedTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private AiIntegration $integration;

    private LandingTemplate $template;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['fast-landings.templates.media_disk' => 'local']);
        $this->user = User::factory()->create();
        $installation = Installation::query()->create(['name' => 'Test', 'domain' => 'fast-landings.test', 'origin_target' => 'origin.fast-landings.test']);
        $this->integration = $installation->aiIntegrations()->create(['name' => 'AI', 'provider' => 'openai', 'api_key' => 'secret']);
        $definition = app(TemplateSourceParser::class)->parse(<<<'TPL'
@template "Nested catalogue"
@type Person
@param name String
@param avatar Image aspect_ratio="1:1" aiInstructions="Use a natural portrait."
@endtype
@type Story
@param caption String required=true
@param author Person aiInstructions="Match the person to the story."
@param details Markdown
@endtype
@type Category
@param title String required=true
@param stories Story[] min_items=0 max_items=4 aiInstructions="Give every story a distinct perspective."
@endtype
@param catalogue Category[] min_items=0 max_items=4 aiInstructions="Keep each category visually consistent."
@param editor Person
@layout
<h1>{{editor.name}}</h1>
@each category in catalogue:
<h2>{{category.title}}</h2>
@each story in category.stories:
<h3>{{story.caption}}</h3><img src="{{story.author.avatar}}">{{story.details}}
@endeach
@endeach
@endlayout
TPL);
        $this->template = LandingTemplate::query()->create([
            'name' => 'Nested catalogue', 'definition' => $definition, 'asset_paths' => [],
            'storage_path' => 'templates/nested', 'original_name' => 'nested.tpl', 'uploaded_by' => $this->user->id,
        ]);
    }

    public function test_field_picker_exposes_groups_and_nested_repeater_wildcards(): void
    {
        $fields = app(TemplateContentGenerator::class)->fields($this->template->definition);

        $this->assertSame(['catalogue', 'catalogue.*.stories'], array_column($fields['arrays'], 'path'));
        $this->assertSame(['catalogue.*.stories.*.author.avatar', 'editor.avatar'], array_column($fields['images'], 'path'));
    }

    public function test_nested_counts_and_image_generation_apply_to_each_matching_item(): void
    {
        $client = $this->mock(AiProviderClient::class);
        $client->shouldReceive('supportsImages')->once()->andReturnTrue();
        $client->shouldReceive('text')->once()->andReturn(json_encode($this->response([2, 2])));
        $image = UploadedFile::fake()->image('portrait.png', 12, 8)->getContent();
        $generatedPaths = [];
        $client->shouldReceive('generateImage')->times(4)->withArgs(function ($integration, $prompt, $references) use (&$generatedPaths): bool {
            preg_match('/for the field ([^.]+\.\d+\.stories\.\d+\.author\.avatar)\./', $prompt, $match);
            $generatedPaths[] = $match[1] ?? null;
            foreach (['Keep each category visually consistent.', 'Give every story a distinct perspective.', 'Match the person to the story.', 'Use a natural portrait.'] as $instruction) {
                $this->assertStringContainsString($instruction, $prompt);
            }
            $this->assertSame([], $references);

            return true;
        })->andReturn(['bytes' => $image, 'mime_type' => 'image/png']);

        $values = $this->generate([
            'array_counts' => ['catalogue' => 2, 'catalogue.*.stories' => 2],
            'image_fields' => ['catalogue.*.stories.*.author.avatar'],
        ]);

        $this->assertSame([
            'catalogue.0.stories.0.author.avatar', 'catalogue.0.stories.1.author.avatar',
            'catalogue.1.stories.0.author.avatar', 'catalogue.1.stories.1.author.avatar',
        ], $generatedPaths);
        $this->assertCount(2, $values['catalogue']);
        foreach ($values['catalogue'] as $category) {
            $this->assertCount(2, $category['stories']);
            foreach ($category['stories'] as $story) {
                $this->assertStringStartsWith('_media/', $story['author']['avatar']);
            }
        }
        $this->assertSame('', $values['editor']['avatar']);
        $this->assertDatabaseCount('template_media', 4);
    }

    public function test_wrong_count_in_later_nested_array_fails_before_any_image_request(): void
    {
        $client = $this->mock(AiProviderClient::class);
        $client->shouldReceive('supportsImages')->once()->andReturnTrue();
        $client->shouldReceive('text')->once()->andReturn(json_encode($this->response([2, 1])));
        $client->shouldNotReceive('generateImage');

        try {
            $this->generate([
                'array_counts' => ['catalogue' => 2, 'catalogue.*.stories' => 2],
                'image_fields' => ['catalogue.*.stories.*.author.avatar'],
            ]);
            $this->fail('A nested array with the wrong count was accepted.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('catalogue.1.stories', $exception->getMessage());
        }
        $this->assertDatabaseCount('template_media', 0);
    }

    public function test_empty_nested_arrays_do_not_generate_unrequested_placeholder_images(): void
    {
        $client = $this->mock(AiProviderClient::class);
        $client->shouldReceive('supportsImages')->once()->andReturnTrue();
        $client->shouldReceive('text')->once()->andReturn(json_encode($this->response([0, 0])));
        $client->shouldNotReceive('generateImage');

        $values = $this->generate([
            'array_counts' => ['catalogue.*.stories' => 0],
            'image_fields' => ['catalogue.*.stories.*.author.avatar'],
        ]);

        $this->assertSame([], $values['catalogue'][0]['stories']);
        $this->assertSame([], $values['catalogue'][1]['stories']);
        $this->assertDatabaseCount('template_media', 0);
    }

    public function test_model_cannot_copy_reserved_image_placeholders_into_nested_rich_text(): void
    {
        $response = $this->response([1]);
        $response['catalogue'][0]['stories'][0]['details'] = '![Avatar](_ai_generated/0.png)';
        $client = $this->mock(AiProviderClient::class);
        $client->shouldReceive('supportsImages')->once()->andReturnTrue();
        $client->shouldReceive('text')->once()->andReturn(json_encode($response));
        $client->shouldNotReceive('generateImage');

        $this->expectException(ValidationException::class);
        $this->generate(['image_fields' => ['catalogue.*.stories.*.author.avatar']]);
    }

    public function test_encoded_markdown_site_token_cannot_escape_unresolved_into_result(): void
    {
        $response = $this->response([1]);
        $response['catalogue'][0]['stories'][0]['details'] = '![Photo](_ai_site&#47;1)';
        $bytes = UploadedFile::fake()->image('site.png', 10, 10)->getContent();
        Storage::disk('local')->put('ai-inputs/site.png', $bytes);
        $client = $this->mock(AiProviderClient::class);
        $client->shouldReceive('supportsVision')->once()->andReturnTrue();
        $client->shouldReceive('text')->once()->andReturn(json_encode($response));

        try {
            $this->generate(['site_images' => [['disk' => 'local', 'path' => 'ai-inputs/site.png', 'name' => 'site.png']]]);
            $this->fail('Unresolved encoded site tokens were accepted.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('template_media', 0);
            $this->assertSame([], Storage::disk('local')->allFiles('template-media'));
        }
    }

    private function generate(array $options = []): array
    {
        return app(TemplateContentGenerator::class)->generate($this->template, $this->integration, [
            'prompt' => 'Write a catalogue with stories and matching portraits.',
            'values' => app(TemplateEngine::class)->defaults($this->template->definition),
            ...$options,
        ], $this->user);
    }

    private function response(array $storyCounts): array
    {
        return ['catalogue' => array_map(fn ($count) => [
            'title' => 'A category',
            'stories' => array_fill(0, $count, ['caption' => 'A story', 'author' => ['name' => 'Alex', 'avatar' => ''], 'details' => '']),
        ], $storyCounts), 'editor' => ['name' => 'Editor', 'avatar' => '']];
    }
}
