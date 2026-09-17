<?php

namespace Tests\Feature;

use App\Enums\AiProvider;
use App\Models\AiIntegration;
use App\Services\Ai\AiProviderClient;
use App\Services\Ai\AiProviderException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AiProviderClientTest extends TestCase
{
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAADElEQVR4nGP4z8AAAAMBAQDJ/pLvAAAAAElFTkSuQmCC';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    #[DataProvider('chatProviders')]
    public function test_openai_compatible_text_requests_preserve_instructions_and_image_labels(AiProvider $provider, string $url): void
    {
        config(['ai.providers.'.$provider->value.'.text_model' => 'chosen-model', 'ai.providers.'.$provider->value.'.vision' => true]);
        Http::fake([$url => Http::response(['choices' => [['finish_reason' => 'stop', 'message' => ['content' => '{"headline":"Hello"}']]]])]);

        $result = app(AiProviderClient::class)->text($this->integration($provider), 'Template rules', 'Write 3 features', [$this->image()]);

        $this->assertSame('{"headline":"Hello"}', $result);
        Http::assertSent(fn (Request $request) => $request->url() === $url
            && $request->hasHeader('Authorization', 'Bearer private-api-key')
            && $request['model'] === 'chosen-model'
            && $request['messages'][0] === ['role' => 'system', 'content' => 'Template rules']
            && $request['messages'][1]['content'][0]['text'] === 'Write 3 features'
            && $request['messages'][1]['content'][1]['text'] === 'Reference only: warm colors'
            && $request['messages'][1]['content'][2]['image_url']['url'] === 'data:image/png;base64,'.self::PNG);
        Http::assertSentCount(1);
    }

    public static function chatProviders(): array
    {
        return [
            'OpenAI' => [AiProvider::OpenAi, 'https://api.openai.com/v1/chat/completions'],
            'OpenRouter' => [AiProvider::OpenRouter, 'https://openrouter.ai/api/v1/chat/completions'],
            'DeepSeek' => [AiProvider::DeepSeek, 'https://api.deepseek.com/chat/completions'],
            'Custom' => [AiProvider::Custom, 'http://localhost:11434/v1/chat/completions'],
        ];
    }

    public function test_anthropic_has_its_own_authentication_system_and_image_format(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response(['stop_reason' => 'end_turn', 'content' => [
            ['type' => 'thinking', 'thinking' => 'Internal reasoning'], ['type' => 'text', 'text' => 'Generated content'],
        ]])]);

        $this->assertSame('Generated content', app(AiProviderClient::class)->text($this->integration(AiProvider::Anthropic), 'TPL rules', 'Create content', [$this->image()]));

        Http::assertSent(fn (Request $request) => $request->url() === 'https://api.anthropic.com/v1/messages'
            && $request->hasHeader('x-api-key', 'private-api-key')
            && $request->hasHeader('anthropic-version', '2023-06-01')
            && ! $request->hasHeader('Authorization')
            && $request['system'] === 'TPL rules'
            && $request['max_tokens'] === 8192
            && $request['messages'][0]['content'][0]['text'] === 'Reference only: warm colors'
            && $request['messages'][0]['content'][1]['source'] === ['type' => 'base64', 'media_type' => 'image/png', 'data' => self::PNG]
            && $request['messages'][0]['content'][2]['text'] === 'Create content');
    }

    public function test_gemini_keeps_api_key_out_of_url_and_excludes_thought_parts(): void
    {
        config(['ai.providers.gemini.text_model' => 'configured-gemini']);
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['candidates' => [[
            'finishReason' => 'STOP', 'content' => ['parts' => [
                ['thought' => true, 'text' => 'Do not expose this'], ['text' => 'Generated '], ['text' => 'content'],
            ]],
        ]]])]);

        $this->assertSame('Generated content', app(AiProviderClient::class)->text($this->integration(AiProvider::Gemini), 'TPL rules', 'Create content', [$this->image()]));

        Http::assertSent(fn (Request $request) => $request->url() === 'https://generativelanguage.googleapis.com/v1beta/models/configured-gemini:generateContent'
            && $request->hasHeader('x-goog-api-key', 'private-api-key')
            && ! $request->hasHeader('Authorization')
            && $request['systemInstruction']['parts'][0]['text'] === 'TPL rules'
            && $request['contents'][0]['parts'][2]['inlineData'] === ['mimeType' => 'image/png', 'data' => self::PNG]);
    }

    public function test_custom_url_is_used_and_requests_have_bounded_timeouts_and_no_redirects(): void
    {
        config(['ai.timeout' => 9999, 'ai.connect_timeout' => 9999]);
        Http::fake(function (Request $request, array $options) {
            $this->assertSame(300, $options['timeout']);
            $this->assertSame(30, $options['connect_timeout']);
            $this->assertFalse($options['allow_redirects']);

            return Http::response(['choices' => [['message' => ['content' => 'Content']]]]);
        });
        $integration = $this->integration(AiProvider::OpenAi);
        $integration->api_url = 'https://proxy.example.test/v1/';

        $this->assertSame('Content', app(AiProviderClient::class)->text($integration, 'Rules', 'Prompt'));
        Http::assertSent(fn (Request $request) => $request->url() === 'https://proxy.example.test/v1/chat/completions'
            && $request['messages'][1]['content'] === 'Prompt');
    }

    public function test_custom_provider_requires_an_explicit_model_before_sending(): void
    {
        config(['ai.providers.custom.text_model' => null]);

        try {
            app(AiProviderClient::class)->text($this->integration(AiProvider::Custom), 'Rules', 'Prompt');
            $this->fail('Expected missing model to be rejected.');
        } catch (AiProviderException $exception) {
            $this->assertStringContainsString('No text model is configured', $exception->getMessage());
            Http::assertNothingSent();
        }
    }

    public function test_vision_capability_can_be_disabled_for_a_text_only_model(): void
    {
        config(['ai.providers.deepseek.vision' => false]);
        $client = app(AiProviderClient::class);
        $integration = $this->integration(AiProvider::DeepSeek);
        $this->assertFalse($client->supportsVision($integration));

        try {
            $client->text($integration, 'Rules', 'Prompt', [$this->image()]);
            $this->fail('Expected unsupported vision to be rejected.');
        } catch (AiProviderException $exception) {
            $this->assertStringContainsString('vision support', $exception->getMessage());
            Http::assertNothingSent();
        }
    }

    public function test_openai_generates_inline_images_and_uses_edits_with_multiple_references(): void
    {
        Http::fake(['api.openai.com/*' => Http::response(['data' => [['b64_json' => self::PNG]]])]);
        $client = app(AiProviderClient::class);
        $integration = $this->integration(AiProvider::OpenAi);
        $this->assertTrue($client->supportsImages($integration));

        $this->assertSame(['bytes' => base64_decode(self::PNG), 'mime_type' => 'image/png'], $client->generateImage($integration, 'Hero image'));
        Http::assertSent(fn (Request $request) => $request->url() === 'https://api.openai.com/v1/images/generations'
            && $request['prompt'] === 'Hero image' && $request['n'] === 1 && $request['output_format'] === 'png');

        $client->generateImage($integration, 'Hero image', [$this->image(), $this->image('Reference only: layout')]);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://api.openai.com/v1/images/edits'
            && $request->isMultipart()
            && $request->hasFile('image[]', base64_decode(self::PNG), 'reference-1.png')
            && $request->hasFile('image[]', base64_decode(self::PNG), 'reference-2.png')
            && str_contains($request->body(), 'Image 2: Reference only: layout'));
        Http::assertSentCount(2);
    }

    public function test_gemini_generates_from_inline_references_and_ignores_intermediate_thought_images(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['candidates' => [[
            'finishReason' => 'STOP', 'content' => ['parts' => [
                ['inlineData' => ['mimeType' => 'image/png', 'data' => self::PNG]],
                ['thought' => true, 'inlineData' => ['mimeType' => 'image/png', 'data' => base64_encode('not an image')]],
            ]],
        ]]])]);
        $result = app(AiProviderClient::class)->generateImage($this->integration(AiProvider::Gemini), 'Hero image', [$this->image()]);

        $this->assertSame(base64_decode(self::PNG), $result['bytes']);
        Http::assertSent(fn (Request $request) => $request['generationConfig']['responseModalities'] === ['TEXT', 'IMAGE']
            && $request['contents'][0]['parts'][2]['inlineData']['data'] === self::PNG);
    }

    public function test_openai_image_prompt_limit_counts_characters_and_includes_appended_reference_labels(): void
    {
        Http::fake(['api.openai.com/*' => Http::response(['data' => [['b64_json' => self::PNG]]])]);
        $client = app(AiProviderClient::class);
        $integration = $this->integration(AiProvider::OpenAi);
        $client->generateImage($integration, str_repeat('я', 32000));
        Http::assertSentCount(1);

        foreach ([[str_repeat('a', 32001), []], [str_repeat('a', 32000), [$this->image()]]] as [$prompt, $references]) {
            try {
                $client->generateImage($integration, $prompt, $references);
                $this->fail('Expected oversized image prompt to be rejected before a paid request.');
            } catch (AiProviderException $exception) {
                $this->assertStringContainsString('32,000-character limit', $exception->getMessage());
                Http::assertSentCount(1);
            }
        }
    }

    #[DataProvider('unsupportedImageProviders')]
    public function test_unsupported_image_generation_is_explicit_and_never_calls_a_provider(AiProvider $provider): void
    {
        $client = app(AiProviderClient::class);
        $integration = $this->integration($provider);
        $this->assertFalse($client->supportsImages($integration));

        try {
            $client->generateImage($integration, 'Hero image');
            $this->fail('Expected unsupported image generation to be rejected.');
        } catch (AiProviderException $exception) {
            $this->assertStringContainsString('OpenAI or Gemini', $exception->getMessage());
            Http::assertNothingSent();
        }
    }

    public static function unsupportedImageProviders(): array
    {
        return [[AiProvider::Anthropic], [AiProvider::OpenRouter], [AiProvider::DeepSeek], [AiProvider::Custom]];
    }

    #[DataProvider('providerFailures')]
    public function test_provider_errors_do_not_expose_keys_bodies_or_previous_exceptions_and_are_not_retried(int $status): void
    {
        Http::fake(['*' => Http::response(['error' => 'Sensitive provider body private-api-key'], $status)]);

        try {
            app(AiProviderClient::class)->text($this->integration(AiProvider::OpenAi), 'Rules', 'Prompt');
            $this->fail('Expected provider error.');
        } catch (AiProviderException $exception) {
            $this->assertStringNotContainsString('private-api-key', $exception->getMessage());
            $this->assertStringNotContainsString('Sensitive provider body', $exception->getMessage());
            $this->assertNull($exception->getPrevious());
            Http::assertSentCount(1);
        }
    }

    public static function providerFailures(): array
    {
        return [[302], [400], [401], [403], [429], [500]];
    }

    public function test_transport_errors_are_sanitized_without_retrying(): void
    {
        $calls = 0;
        Http::fake(function () use (&$calls) {
            $calls++;
            throw new ConnectionException('Credentials private-api-key in transport debug');
        });

        try {
            app(AiProviderClient::class)->text($this->integration(AiProvider::OpenAi), 'Rules', 'Prompt');
            $this->fail('Expected connection error.');
        } catch (AiProviderException $exception) {
            $this->assertStringContainsString('timed out', $exception->getMessage());
            $this->assertStringNotContainsString('private-api-key', $exception->getMessage());
            $this->assertNull($exception->getPrevious());
            $this->assertSame(1, $calls);
        }
    }

    public function test_truncated_content_is_not_accepted_as_a_complete_result(): void
    {
        Http::fake(['*' => Http::response(['choices' => [['finish_reason' => 'length', 'message' => ['content' => '{"partial":']]]])]);
        $this->expectException(AiProviderException::class);
        $this->expectExceptionMessage('output limit');

        app(AiProviderClient::class)->text($this->integration(AiProvider::OpenAi), 'Rules', 'Prompt');
    }

    #[DataProvider('invalidImageResponses')]
    public function test_generated_images_must_be_actual_inline_raster_bytes(array $response): void
    {
        Http::fake(['*' => Http::response($response)]);

        try {
            app(AiProviderClient::class)->generateImage($this->integration(AiProvider::OpenAi), 'Hero');
            $this->fail('Expected invalid image response to be rejected.');
        } catch (AiProviderException) {
            Http::assertSentCount(1);
        }
    }

    public static function invalidImageResponses(): array
    {
        return [
            'remote URL' => [['data' => [['url' => 'http://localhost/private']]]],
            'invalid base64' => [['data' => [['b64_json' => 'bad!base64']]]],
            'HTML' => [['data' => [['b64_json' => base64_encode('<html>bad</html>')]]]],
            'SVG' => [['data' => [['b64_json' => base64_encode('<svg onload="alert(1)"></svg>')]]]],
        ];
    }

    public function test_invalid_reference_images_are_rejected_before_any_paid_request(): void
    {
        $this->expectException(AiProviderException::class);
        $this->expectExceptionMessage('image input is invalid');

        try {
            app(AiProviderClient::class)->text($this->integration(AiProvider::OpenAi), 'Rules', 'Prompt', [
                ['mime_type' => 'image/png', 'data' => base64_encode('<svg></svg>'), 'label' => 'Reference'],
            ]);
        } finally {
            Http::assertNothingSent();
        }
    }

    private function integration(AiProvider $provider): AiIntegration
    {
        return new AiIntegration([
            'name' => 'Testing', 'provider' => $provider, 'api_key' => 'private-api-key',
            'api_url' => $provider === AiProvider::Custom ? 'http://localhost:11434/v1' : null,
        ]);
    }

    private function image(string $label = 'Reference only: warm colors'): array
    {
        return ['mime_type' => 'image/png', 'data' => self::PNG, 'label' => $label];
    }
}
