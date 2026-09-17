<?php

namespace App\Livewire\Landings\Concerns;

use App\Jobs\GenerateTemplateContent;
use App\Models\Installation;
use App\Models\LandingTemplate;
use App\Models\TemplateGeneration;
use App\Models\User;
use App\Services\Ai\AiProviderClient;
use App\Services\Templates\TemplateContentGenerator;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Throwable;

trait InteractsWithAiGeneration
{
    public string $aiIntegrationId = '';

    public string $aiPrompt = '';

    public array $aiArrayCounts = [];

    public array $aiImageFields = [];

    public array $aiSiteImages = [];

    public array $aiReferenceImages = [];

    #[Locked]
    public ?string $generationId = null;

    #[Locked]
    public int $aiUploadVersion = 0;

    #[Locked]
    public string $generationTemplateHash = '';

    private function initializeAiGeneration(): void
    {
        $this->generationTemplateHash = TemplateGeneration::definitionHash($this->template);
        $this->generationId = TemplateGeneration::query()->where('user_id', auth()->id())
            ->where('landing_template_id', $this->template->id)->latest()->value('id');
    }

    public function removeAiImage(string $purpose, int $index): void
    {
        $this->activeUser();
        abort_unless(in_array($purpose, ['aiSiteImages', 'aiReferenceImages'], true), 422);
        $file = $this->{$purpose}[$index] ?? null;
        abort_unless($file instanceof TemporaryUploadedFile, 422);
        $file->delete();
        array_splice($this->{$purpose}, $index, 1);
        $this->aiUploadVersion++;
    }

    public function generateContent(TemplateContentGenerator $generator, AiProviderClient $client): void
    {
        $user = $this->activeUser();
        $this->resetValidation('generation');
        $this->assertNoPendingFieldUploads();
        $template = LandingTemplate::query()->findOrFail($this->template->id);
        if (TemplateGeneration::definitionHash($template) !== $this->generationTemplateHash) {
            throw ValidationException::withMessages(['generation' => 'This template changed. Reload the page before generating content.']);
        }
        $fields = $generator->fields($template->definition);
        $this->aiPrompt = trim($this->aiPrompt);
        $this->validate([
            'aiIntegrationId' => ['required', 'string', 'max:26'],
            'aiPrompt' => ['required', 'string', 'max:10000'],
            'aiArrayCounts' => ['array'],
            'aiImageFields' => ['array', 'max:100'],
            'aiImageFields.*' => ['string', 'distinct', Rule::in(array_column($fields['images'], 'path'))],
            'aiSiteImages' => ['array', 'max:8'],
            'aiSiteImages.*' => ['file', 'image', 'mimes:jpg,jpeg,png,webp,gif,avif', 'max:10240'],
            'aiReferenceImages' => ['array', 'max:8'],
            'aiReferenceImages.*' => ['file', 'image', 'mimes:jpg,jpeg,png,webp,gif,avif', 'max:10240'],
        ]);

        $installation = Installation::query()->sole();
        $integration = $installation->aiIntegrations()->find($this->aiIntegrationId);
        if (! $integration) {
            throw ValidationException::withMessages(['aiIntegrationId' => 'Choose an available AI connection.']);
        }
        if ($this->aiImageFields !== [] && ! $client->supportsImages($integration)) {
            throw ValidationException::withMessages(['aiImageFields' => 'This connection does not support image generation. Choose another connection or deselect the image fields.']);
        }
        if (($this->aiSiteImages !== [] || $this->aiReferenceImages !== []) && ! $client->supportsVision($integration)) {
            throw ValidationException::withMessages(['generation' => 'This connection does not support image input. Choose another connection to use uploaded images.']);
        }
        if (collect([...$this->aiSiteImages, ...$this->aiReferenceImages])->sum(fn ($file) => $file->getSize()) > 20 * 1024 * 1024) {
            throw ValidationException::withMessages(['generation' => 'Upload at most 20 MB of images in total.']);
        }

        $counts = [];
        foreach ($this->aiArrayCounts as $index => $count) {
            $field = $fields['arrays'][$index] ?? null;
            if (! $field) {
                throw ValidationException::withMessages(['aiArrayCounts' => 'Choose only arrays defined in the template.']);
            }
            if ($count === '' || $count === null) {
                continue;
            }
            $this->validate(['aiArrayCounts.'.$index => ['integer', 'min:'.$field['min'], 'max:'.$field['max']]]);
            $counts[$field['path']] = (int) $count;
        }

        $generation = DB::transaction(function () use ($user, $template, $integration, $counts): TemplateGeneration {
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            if (TemplateGeneration::query()->where('user_id', $user->id)->where('landing_template_id', $template->id)
                ->whereIn('status', ['pending', 'running'])->exists()) {
                throw ValidationException::withMessages(['generation' => 'Content generation is already in progress for this template.']);
            }

            return TemplateGeneration::query()->create([
                'user_id' => $user->id,
                'landing_template_id' => $template->id,
                'ai_integration_id' => $integration->id,
                'definition_hash' => TemplateGeneration::definitionHash($template),
                'status' => 'pending',
                'options' => ['prompt' => $this->aiPrompt, 'values' => $this->values, 'array_counts' => $counts, 'image_fields' => $this->aiImageFields],
            ]);
        });
        $this->generationId = $generation->id;

        try {
            $options = $generation->options;
            foreach (['site_images' => $this->aiSiteImages, 'reference_images' => $this->aiReferenceImages] as $purpose => $files) {
                $options[$purpose] = [];
                foreach ($files as $file) {
                    $path = $file->store('ai-generations/'.$generation->id.'/'.$purpose, 'local');
                    if (! is_string($path) || $path === '') {
                        throw new \RuntimeException('Unable to stage image.');
                    }
                    $options[$purpose][] = ['disk' => 'local', 'path' => $path, 'name' => mb_substr(basename($file->getClientOriginalName()), 0, 180)];
                }
            }
            $generation->update(['options' => $options]);
            GenerateTemplateContent::dispatch($generation->id)->afterCommit();
        } catch (Throwable) {
            $generation->update(['status' => 'failed', 'error' => 'Content generation could not be started. Please try again.', 'completed_at' => now()]);
            $generation->clearStagedImages();
            throw ValidationException::withMessages(['generation' => 'Content generation could not be started. Please try again.']);
        }

        // Temporary upload cleanup must not cancel work that was already dispatched.
        foreach ([...$this->aiSiteImages, ...$this->aiReferenceImages] as $file) {
            try {
                $file->delete();
            } catch (Throwable) {
                // Livewire's regular temporary-file expiration will handle this file.
            }
        }
        $this->aiSiteImages = [];
        $this->aiReferenceImages = [];
        $this->aiUploadVersion++;
    }

    public function refreshGeneration(): void
    {
        $this->activeUser();
    }

    public function applyGeneration(): void
    {
        $this->activeUser();
        $this->assertNoPendingFieldUploads();
        $generation = $this->currentGeneration();
        if (! $generation || $generation->status !== 'completed' || ! is_array($generation->result)) {
            throw ValidationException::withMessages(['generation' => 'There is no new completed generation to apply.']);
        }
        $template = LandingTemplate::query()->findOrFail($this->template->id);
        if ($generation->definition_hash !== TemplateGeneration::definitionHash($template)) {
            throw ValidationException::withMessages(['generation' => 'This template changed after generation. Reload the page and generate content again.']);
        }
        $this->values = $generation->result;
        $this->formVersion++;
        $this->resetValidation();
        $generation->update(['applied_at' => now()]);
    }

    private function currentGeneration(): ?TemplateGeneration
    {
        if ($this->generationId === null) {
            return null;
        }

        $generation = TemplateGeneration::query()->whereKey($this->generationId)->where('user_id', auth()->id())
            ->where('landing_template_id', $this->template->id)->first();
        // A killed worker may not execute failed(). Recover without repeating a paid request.
        $deadline = now()->subSeconds((new GenerateTemplateContent(''))->timeout + 60);
        if ($generation?->status === 'running' && $generation->started_at?->lt($deadline)) {
            $expired = TemplateGeneration::query()->whereKey($generation->id)->where('status', 'running')->where('started_at', '<', $deadline)
                ->update(['status' => 'failed', 'error' => 'Content generation stopped or timed out. Try again with fewer images.', 'completed_at' => now()]);
            if ($expired) {
                $generation->refresh()->clearStagedImages();
            }
        } elseif ($generation?->status === 'pending' && $generation->created_at->lt(now()->subDay())) {
            $expired = TemplateGeneration::query()->whereKey($generation->id)->where('status', 'pending')->where('created_at', '<', now()->subDay())
                ->update(['status' => 'failed', 'error' => 'Generation did not start. Check that the queue worker is running, then try again.', 'completed_at' => now()]);
            if ($expired) {
                $generation->refresh()->clearStagedImages();
            }
        }

        return $generation;
    }

    private function assertNoPendingFieldUploads(): void
    {
        if (collect(Arr::flatten([$this->uploads, $this->editorUploads]))->contains(fn ($file) => $file !== null && $file !== '')) {
            throw ValidationException::withMessages(['generation' => 'Remove field uploads or move them to Images for the site before generating or applying content.']);
        }
    }

    private function generationViewData(): array
    {
        $installations = Installation::query()->limit(2)->get();
        $connections = $installations->count() === 1 ? $installations->first()->aiIntegrations()->orderBy('name')->get(['id', 'name', 'provider']) : collect();
        $selected = $connections->firstWhere('id', $this->aiIntegrationId);
        $client = app(AiProviderClient::class);

        return [
            'aiConnections' => $connections,
            'aiSupportsImages' => $selected ? $client->supportsImages($selected) : null,
            'aiSupportsVision' => $selected ? $client->supportsVision($selected) : null,
            'generationFields' => app(TemplateContentGenerator::class)->fields($this->template->definition),
            'generation' => $this->currentGeneration(),
        ];
    }
}
