<?php

namespace App\Console\Commands;

use App\Models\LandingRelease;
use App\Models\LandingTemplate;
use App\Services\LandingPreviewService;
use App\Services\Templates\TemplatePreviewService;
use Illuminate\Console\Command;

class QueueLandingPreviews extends Command
{
    protected $signature = 'fast-landings:previews {--force : Refresh all active release and template screenshots, including failed ones}';

    protected $description = 'Queue missing screenshots for landing releases and templates';

    public function handle(LandingPreviewService $previews, TemplatePreviewService $templatePreviews): int
    {
        $count = 0;
        if ($this->option('force')) {
            LandingRelease::query()->where('is_active', true)->each(function ($release) use ($previews, &$count): void {
                $count += (int) $previews->request($release, force: true);
            });
        } else {
            $count = $previews->requestMissing();
        }
        $this->info("Queued {$count} landing preview(s).");

        $templateCount = 0;
        if ($this->option('force')) {
            LandingTemplate::query()->each(function ($template) use ($templatePreviews, &$templateCount): void {
                $templateCount += (int) $templatePreviews->request($template, force: true);
            });
        } else {
            $templateCount = $templatePreviews->requestMissing();
        }
        $this->info("Queued {$templateCount} template preview(s).");

        return self::SUCCESS;
    }
}
