<?php

namespace App\Services\Templates;

use App\Jobs\GenerateTemplatePreview;
use App\Models\LandingTemplate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;
use TrafficOps\TemplateDsl\TemplateEngine;

class TemplatePreviewService
{
    public function request(LandingTemplate $template, bool $force = false): bool
    {
        if (! config('fast-landings.previews.enabled') || ! $template->hasPreviewSource() || ! $this->canRender($template)) {
            return false;
        }

        $token = (string) Str::ulid();
        $claimed = LandingTemplate::query()->whereKey($template->id)->where('storage_path', $template->storage_path)
            ->where(function ($query) use ($force): void {
                $query->whereNull('preview_status')
                    ->orWhere(fn ($query) => $query->whereIn('preview_status', ['queued', 'processing'])
                        ->where('preview_requested_at', '<', now()->subMinutes(5)));
                if ($force) {
                    $query->orWhereIn('preview_status', ['ready', 'failed']);
                }
            })->update([
                'preview_status' => 'queued',
                'preview_token' => $token,
                'preview_requested_at' => now(),
            ]);

        if (! $claimed) {
            return false;
        }

        DB::afterCommit(function () use ($template, $token): void {
            try {
                GenerateTemplatePreview::dispatch($template->id, $token);
            } catch (Throwable $exception) {
                $this->markFailed($template->id, $token);
                report($exception);
            }
        });

        return true;
    }

    public function requestMissing(): int
    {
        if (! config('fast-landings.previews.enabled')) {
            return 0;
        }

        $templates = LandingTemplate::query()
            ->where(fn ($query) => $query->whereNotNull('definition->previewData')->orWhereNotNull('definition->previewUrl'))
            ->where(fn ($query) => $query->whereNull('preview_status')
                ->orWhere(fn ($query) => $query->whereIn('preview_status', ['queued', 'processing'])
                    ->where('preview_requested_at', '<', now()->subMinutes(5))))
            ->lazyById(100)
            ->filter(fn (LandingTemplate $template): bool => $this->canRender($template))
            ->take(50);

        return $templates->filter(fn ($template) => $this->request($template))->count();
    }

    public function generate(string $templateId, string $token): void
    {
        $disk = Storage::disk(config('fast-landings.storage_disk'));
        $directory = "_templates/previews/{$templateId}";
        // The staging pruner also removes snapshots left behind by a killed worker.
        $source = "_templates/staging/preview-{$token}";
        $path = "{$directory}/{$token}.png";
        $temporary = $source.'/preview.png.tmp';

        try {
            // Package replacement/deletion takes the same lock. Snapshot assets
            // before rendering so a running job never reads a removed package.
            $arguments = DB::transaction(function () use ($templateId, $token, $disk, $directory, $source, $temporary): ?array {
                $template = LandingTemplate::query()->lockForUpdate()->where('preview_token', $token)->find($templateId);
                if (! $template || ! $template->hasPreviewSource()) {
                    return null;
                }
                $disk->makeDirectory($directory);
                $disk->makeDirectory($source);
                $definition = $template->definition;
                if (isset($definition['previewUrl'])) {
                    return ['--url', $definition['previewUrl'], $disk->path($temporary)];
                }
                if (! $this->canRender($template)) {
                    throw new RuntimeException('PHP template previews require a deployed previewUrl.');
                }
                foreach ($template->asset_paths ?? [] as $asset) {
                    if (preg_match('/\.(?:php[0-9]*|phtml|phar|tpl)(?:\.|$)/i', $asset)) {
                        continue;
                    }
                    if (! $disk->copy($template->storage_path.'/'.$asset, $source.'/'.$asset)) {
                        throw new RuntimeException('Unable to copy template preview assets.');
                    }
                }
                foreach (app(TemplateEngine::class)->renderPages($definition, $definition['previewData']) as $page => $html) {
                    // The screenshot worker is static: PHP sources never enter its document root.
                    if (str_ends_with($page, '.php') || preg_match('/<\?(?:php\b|=)/i', $html)) {
                        continue;
                    }
                    $html = app(TemplateRequestRuntime::class)->stripValidations($html);
                    if (! $disk->put($source.'/'.$page, $html)) {
                        throw new RuntimeException('Unable to write template preview HTML.');
                    }
                }

                return [$disk->path($source), $disk->path($temporary), 'index.html'];
            });
            if ($arguments === null) {
                return;
            }

            $result = Process::path(base_path())->timeout(55)->env([
                'FAST_LANDINGS_CHROMIUM_PATH' => config('fast-landings.previews.chromium_path') ?: false,
            ])->run([
                config('fast-landings.previews.node_binary'),
                base_path('scripts/capture-landing.mjs'),
                ...$arguments,
            ]);
            $result->throw();
            if (! $disk->exists($temporary) || @getimagesize($disk->path($temporary)) === false) {
                throw new RuntimeException('The preview renderer did not produce an image.');
            }
            if (! $disk->move($temporary, $path)) {
                throw new RuntimeException('Unable to store the generated template preview.');
            }

            $updated = DB::transaction(function () use ($templateId, $token, $path, $disk): bool {
                $template = LandingTemplate::query()->lockForUpdate()->where('preview_token', $token)->find($templateId);
                if (! $template) {
                    return false;
                }
                $previous = $template->preview_path;
                $template->forceFill([
                    'preview_status' => 'ready',
                    'preview_path' => $path,
                    'preview_generated_at' => now(),
                ])->save();
                if ($previous && $previous !== $path) {
                    $disk->delete($previous);
                }

                return true;
            });
            if (! $updated) {
                $disk->delete($path);
            }
        } finally {
            $disk->delete($temporary);
            $disk->deleteDirectory($source);
        }
    }

    public function markFailed(string $templateId, string $token): void
    {
        LandingTemplate::query()->whereKey($templateId)->where('preview_token', $token)
            ->whereIn('preview_status', ['queued', 'processing'])->update(['preview_status' => 'failed']);
    }

    private function canRender(LandingTemplate $template): bool
    {
        return isset($template->definition['previewUrl']) || $template->supportsLocalPreview();
    }
}
