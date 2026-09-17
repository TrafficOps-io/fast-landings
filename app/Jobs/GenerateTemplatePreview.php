<?php

namespace App\Jobs;

use App\Models\LandingTemplate;
use App\Services\Templates\TemplatePreviewService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class GenerateTemplatePreview implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 65;

    public int $backoff = 15;

    public bool $failOnTimeout = true;

    public function __construct(public string $templateId, public string $token) {}

    public function handle(TemplatePreviewService $previews): void
    {
        $claimed = LandingTemplate::query()->whereKey($this->templateId)->where('preview_token', $this->token)
            ->whereIn('preview_status', ['queued', 'processing'])->update(['preview_status' => 'processing']);
        if ($claimed) {
            $previews->generate($this->templateId, $this->token);
        }
    }

    public function failed(?Throwable $exception): void
    {
        app(TemplatePreviewService::class)->markFailed($this->templateId, $this->token);
    }
}
