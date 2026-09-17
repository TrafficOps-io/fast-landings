<?php

namespace App\Services\Ai;

use App\Enums\AiProvider;
use App\Models\AiIntegration;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

class AiProviderClient
{
    private const IMAGE_MIME_TYPES = ['image/png', 'image/jpeg', 'image/webp'];

    /** @param array<int, array{mime_type: string, data: string, label: string}> $images */
    public function text(AiIntegration $integration, string $system, string $prompt, array $images = []): string
    {
        if ($images !== [] && ! $this->supportsVision($integration)) {
            throw new AiProviderException('This AI connection does not support image references. Choose a connection with vision support.');
        }

        $images = $this->validateImages($images);
        $model = $this->model($integration, 'text');
        $tokens = max(256, min(32768, (int) config('ai.max_output_tokens', 8192)));

        if ($integration->provider === AiProvider::Anthropic) {
            $content = [];
            foreach ($images as $image) {
                $content[] = ['type' => 'text', 'text' => $image['label']];
                $content[] = ['type' => 'image', 'source' => [
                    'type' => 'base64', 'media_type' => $image['mime_type'], 'data' => $image['data'],
                ]];
            }
            $content[] = ['type' => 'text', 'text' => $prompt];
            $response = $this->send($this->request($integration), $this->url($integration, 'messages'), [
                'model' => $model, 'system' => $system, 'max_tokens' => $tokens,
                'messages' => [['role' => 'user', 'content' => $content]],
            ]);
            $this->assertComplete($response->json('stop_reason'));

            return $this->textParts($response->json('content'));
        }

        if ($integration->provider === AiProvider::Gemini) {
            $response = $this->send($this->request($integration), $this->url($integration, 'models/'.rawurlencode($model).':generateContent'), [
                'systemInstruction' => ['parts' => [['text' => $system]]],
                'contents' => [['role' => 'user', 'parts' => $this->geminiParts($prompt, $images)]],
                'generationConfig' => ['maxOutputTokens' => $tokens],
            ]);
            $this->assertComplete($response->json('candidates.0.finishReason'));

            return $this->textParts($response->json('candidates.0.content.parts'));
        }

        $content = $prompt;
        if ($images !== []) {
            $content = [['type' => 'text', 'text' => $prompt]];
            foreach ($images as $image) {
                $content[] = ['type' => 'text', 'text' => $image['label']];
                $content[] = ['type' => 'image_url', 'image_url' => [
                    'url' => 'data:'.$image['mime_type'].';base64,'.$image['data'],
                ]];
            }
        }

        $response = $this->send($this->request($integration), $this->url($integration, 'chat/completions'), [
            'model' => $model,
            'messages' => [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $content]],
            $integration->provider === AiProvider::OpenAi ? 'max_completion_tokens' : 'max_tokens' => $tokens,
            'stream' => false,
        ]);
        $this->assertComplete($response->json('choices.0.finish_reason'));
        $content = $response->json('choices.0.message.content');

        return is_array($content) ? $this->textParts($content) : $this->nonEmptyText($content);
    }

    /**
     * @param  array<int, array{mime_type: string, data: string, label: string}>  $references
     * @return array{bytes: string, mime_type: string}
     */
    public function generateImage(AiIntegration $integration, string $prompt, array $references = []): array
    {
        if (! $this->supportsImages($integration)) {
            throw new AiProviderException('Image generation is available with OpenAI or Gemini connections. Choose one of these connections or disable image generation.');
        }

        $references = $this->validateImages($references);
        $model = $this->model($integration, 'image');
        $request = $this->request($integration, true);

        if ($integration->provider === AiProvider::Gemini) {
            $response = $this->send($request, $this->url($integration, 'models/'.rawurlencode($model).':generateContent'), [
                'contents' => [['role' => 'user', 'parts' => $this->geminiParts($prompt, $references)]],
                'generationConfig' => ['responseModalities' => ['TEXT', 'IMAGE']],
            ]);
            $this->assertComplete($response->json('candidates.0.finishReason'));
            $parts = $response->json('candidates.0.content.parts');
            if (is_array($parts)) {
                foreach (array_reverse($parts) as $part) {
                    // Thinking images are intermediate work, not the requested final asset.
                    if (is_array($part) && empty($part['thought']) && isset($part['inlineData']['data'])) {
                        return $this->decodeImage($part['inlineData']['data']);
                    }
                }
            }

            throw new AiProviderException('The AI provider did not return an image. Try a different image prompt.');
        }

        $endpoint = 'images/generations';
        if ($references !== []) {
            $endpoint = 'images/edits';
            $labels = [];
            foreach ($references as $index => $image) {
                $extension = match ($image['mime_type']) {
                    'image/jpeg' => 'jpg', 'image/webp' => 'webp', default => 'png',
                };
                $request->attach('image[]', base64_decode($image['data'], true), 'reference-'.($index + 1).'.'.$extension, ['Content-Type' => $image['mime_type']]);
                $labels[] = 'Image '.($index + 1).': '.$image['label'];
            }
            $prompt .= "\n\nReference images, in attachment order:\n".implode("\n", $labels);
        }

        if (mb_strlen($prompt) > 32000) {
            throw new AiProviderException('Image instructions exceed the provider\'s 32,000-character limit. Shorten the brief or template image instructions.');
        }

        $response = $this->send($request, $this->url($integration, $endpoint), [
            'model' => $model, 'prompt' => $prompt, 'n' => 1,
            'output_format' => 'png', 'quality' => 'medium', 'size' => 'auto',
        ]);

        // Never fetch a provider-supplied URL: outputs must be inline image bytes.
        return $this->decodeImage($response->json('data.0.b64_json'));
    }

    public function supportsImages(AiIntegration $integration): bool
    {
        return in_array($integration->provider, [AiProvider::OpenAi, AiProvider::Gemini], true)
            && trim((string) config('ai.providers.'.$integration->provider->value.'.image_model')) !== '';
    }

    public function supportsVision(AiIntegration $integration): bool
    {
        return (bool) config('ai.providers.'.$integration->provider->value.'.vision', false);
    }

    private function request(AiIntegration $integration, bool $image = false): PendingRequest
    {
        try {
            $key = $integration->api_key;
        } catch (Throwable) {
            throw new AiProviderException('The AI connection credentials could not be read. Ask an administrator to update the connection.');
        }

        if (! is_string($key) || $key === '' || preg_match('/\s/', $key)) {
            throw new AiProviderException('The AI connection credentials are invalid. Ask an administrator to update the connection.');
        }

        $request = Http::acceptJson()
            ->connectTimeout(max(1, min(30, (int) config('ai.connect_timeout', 10))))
            ->timeout(max(1, min(300, (int) config($image ? 'ai.image_timeout' : 'ai.timeout', $image ? 180 : 120))))
            ->withoutRedirecting();

        return match ($integration->provider) {
            AiProvider::Anthropic => $request->withHeaders(['x-api-key' => $key, 'anthropic-version' => '2023-06-01']),
            AiProvider::Gemini => $request->withHeaders(['x-goog-api-key' => $key]),
            default => $request->withToken($key),
        };
    }

    private function send(PendingRequest $request, string $url, array $payload): Response
    {
        try {
            // No automatic retries: a timed-out generation may still be billed.
            $response = $request->post($url, $payload);
        } catch (Throwable) {
            throw new AiProviderException('The AI provider could not be reached or timed out. Please try again.');
        }

        if (! $response->successful()) {
            throw new AiProviderException(match ($response->status()) {
                401, 403 => 'The AI provider rejected this connection. Check its API key and model access.',
                429 => 'The AI provider has reached its rate or usage limit. Please try again later.',
                default => 'The AI provider could not complete generation (HTTP '.$response->status().'). Check the connection and configured model.',
            });
        }

        if (strlen($response->body()) > 32 * 1024 * 1024 || ! is_array($response->json())) {
            throw new AiProviderException('The AI provider returned an invalid response.');
        }

        return $response;
    }

    private function url(AiIntegration $integration, string $endpoint): string
    {
        $base = $integration->api_url ?: $integration->provider->defaultApiUrl();
        $parts = is_string($base) ? parse_url($base) : false;
        if (! is_array($parts) || ! in_array($parts['scheme'] ?? '', ['http', 'https'], true)
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])) {
            throw new AiProviderException('The AI connection needs a valid API base URL.');
        }

        return rtrim($base, '/').'/'.$endpoint;
    }

    private function model(AiIntegration $integration, string $type): string
    {
        $model = trim((string) config('ai.providers.'.$integration->provider->value.'.'.$type.'_model'));
        if ($model === '') {
            throw new AiProviderException('No '.$type.' model is configured for this AI provider. Ask an administrator to configure it.');
        }

        return $model;
    }

    private function geminiParts(string $prompt, array $images): array
    {
        $parts = [['text' => $prompt]];
        foreach ($images as $image) {
            $parts[] = ['text' => $image['label']];
            $parts[] = ['inlineData' => ['mimeType' => $image['mime_type'], 'data' => $image['data']]];
        }

        return $parts;
    }

    private function validateImages(array $images): array
    {
        if (count($images) > 16) {
            throw new AiProviderException('Use at most 16 images per generation.');
        }

        $totalBytes = 0;
        foreach ($images as &$image) {
            if (! is_array($image) || ! in_array($image['mime_type'] ?? null, self::IMAGE_MIME_TYPES, true)
                || ! is_string($image['data'] ?? null) || strlen($image['data']) > 7 * 1024 * 1024) {
                throw new AiProviderException('Image inputs must be PNG, JPEG or WebP files up to 5 MB each.');
            }
            $bytes = base64_decode($image['data'], true);
            $info = $bytes !== false ? @getimagesizefromstring($bytes) : false;
            if ($bytes === false || strlen($bytes) > 5 * 1024 * 1024 || ! $info || ($info['mime'] ?? null) !== $image['mime_type']) {
                throw new AiProviderException('An image input is invalid. Upload a PNG, JPEG or WebP file up to 5 MB.');
            }
            $totalBytes += strlen($bytes);
            $image['label'] = is_string($image['label'] ?? null) && trim($image['label']) !== ''
                ? mb_substr($image['label'], 0, 1000) : 'Uploaded image';
        }

        if ($totalBytes > 15 * 1024 * 1024) {
            throw new AiProviderException('The combined image inputs must be no larger than 15 MB.');
        }

        return $images;
    }

    private function textParts(mixed $parts): string
    {
        $text = '';
        if (is_array($parts)) {
            foreach ($parts as $part) {
                if (is_array($part) && empty($part['thought']) && ($part['type'] ?? 'text') === 'text' && is_string($part['text'] ?? null)) {
                    $text .= $part['text'];
                }
            }
        }

        return $this->nonEmptyText($text);
    }

    private function nonEmptyText(mixed $text): string
    {
        if (! is_string($text) || trim($text) === '') {
            throw new AiProviderException('The AI provider did not return content. Try adjusting your prompt.');
        }

        return trim($text);
    }

    private function assertComplete(mixed $reason): void
    {
        if (in_array($reason, ['length', 'max_tokens', 'MAX_TOKENS'], true)) {
            throw new AiProviderException('Generated content exceeded the output limit. Request fewer items or shorter content.');
        }
        if (in_array($reason, ['content_filter', 'SAFETY', 'RECITATION', 'IMAGE_SAFETY', 'BLOCKLIST', 'PROHIBITED_CONTENT', 'refusal'], true)) {
            throw new AiProviderException('The AI provider declined this generation. Try adjusting your prompt.');
        }
    }

    private function decodeImage(mixed $data): array
    {
        if (! is_string($data) || strlen($data) > 28 * 1024 * 1024) {
            throw new AiProviderException('The AI provider did not return a valid inline image.');
        }
        $bytes = base64_decode($data, true);
        $info = $bytes !== false ? @getimagesizefromstring($bytes) : false;
        if ($bytes === false || strlen($bytes) > 20 * 1024 * 1024 || ! $info
            || ! in_array($info['mime'] ?? null, self::IMAGE_MIME_TYPES, true)
            || $info[0] * $info[1] > 40000000) {
            throw new AiProviderException('The AI provider returned an invalid or oversized image.');
        }

        return ['bytes' => $bytes, 'mime_type' => $info['mime']];
    }
}
