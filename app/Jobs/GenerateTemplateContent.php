<?php

namespace App\Jobs;

use App\Models\Installation;
use App\Models\LandingTemplate;
use App\Models\TemplateGeneration;
use App\Models\User;
use App\Services\Ai\AiProviderException;
use App\Services\Templates\TemplateContentGenerator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

class GenerateTemplateContent implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 1800;

    public bool $failOnTimeout = true;

    public function __construct(public string $generationId) {}

    public function handle(TemplateContentGenerator $generator): void
    {
        // A duplicate delivery must never make a second paid provider request.
        $claimed = TemplateGeneration::query()->whereKey($this->generationId)->where('status', 'pending')
            ->update(['status' => 'running', 'started_at' => now()]);
        if (! $claimed) {
            if (! TemplateGeneration::query()->whereKey($this->generationId)->exists()) {
                Storage::disk('local')->deleteDirectory('ai-generations/'.$this->generationId);
            }

            return;
        }

        $generation = TemplateGeneration::query()->findOrFail($this->generationId);
        try {
            $user = User::query()->whereKey($generation->user_id)->where('is_active', true)->first();
            $template = LandingTemplate::query()->find($generation->landing_template_id);
            $integration = Installation::singleton()->aiIntegrations()->find($generation->ai_integration_id);
            if (! $user || ! $template || ! $integration || $generation->definition_hash !== TemplateGeneration::definitionHash($template)) {
                $generation->update(['status' => 'failed', 'error' => 'The template, AI connection, or your access changed. Reload the page and try again.', 'completed_at' => now()]);

                return;
            }

            $result = $generator->generate($template, $integration, $generation->options, $user);
            TemplateGeneration::query()->whereKey($generation->id)->where('status', 'running')
                ->update(['status' => 'completed', 'result' => json_encode($result, JSON_THROW_ON_ERROR), 'completed_at' => now()]);
        } catch (Throwable $exception) {
            // Provider responses can contain credentials or submitted content. Never persist them as errors.
            $error = 'Content generation failed. Check the AI connection and generation settings, then try again.';
            if ($exception instanceof AiProviderException) {
                $error = $exception->getMessage();
            } elseif ($exception instanceof ValidationException && isset($exception->errors()['generation'][0])) {
                $error = $exception->errors()['generation'][0];
            }
            $generation->update(['status' => 'failed', 'error' => $error, 'completed_at' => now()]);
        } finally {
            $generation->clearStagedImages();
        }
    }

    public function failed(?Throwable $exception): void
    {
        $generation = TemplateGeneration::query()->find($this->generationId);
        if ($generation?->isRunning()) {
            $generation->update(['status' => 'failed', 'error' => 'Content generation stopped or timed out. Try again with fewer images.', 'completed_at' => now()]);
        }
        if ($generation) {
            $generation->clearStagedImages();
        } else {
            Storage::disk('local')->deleteDirectory('ai-generations/'.$this->generationId);
        }
    }
}
