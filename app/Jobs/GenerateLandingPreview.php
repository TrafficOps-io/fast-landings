<?php

namespace App\Jobs;

use App\Models\LandingRelease;
use App\Services\LandingPreviewService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class GenerateLandingPreview implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 65;

    public int $backoff = 15;

    public bool $failOnTimeout = true;

    public function __construct(public string $releaseId, public string $token) {}

    public function handle(LandingPreviewService $previews): void
    {
        $release = LandingRelease::query()->where('preview_token', $this->token)->find($this->releaseId);
        if (! $release || $release->preview_status === 'ready') {
            return;
        }

        $release->forceFill(['preview_status' => 'processing'])->save();
        $previews->generate($release, $this->token);
    }

    public function failed(?Throwable $exception): void
    {
        app(LandingPreviewService::class)->markFailed($this->releaseId, $this->token);
    }
}
