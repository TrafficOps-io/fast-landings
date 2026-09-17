<?php

namespace App\Services;

use App\Jobs\GenerateLandingPreview;
use App\Models\LandingRelease;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class LandingPreviewService
{
    public function request(LandingRelease $release, bool $force = false): bool
    {
        if (! config('fast-landings.previews.enabled') || $release->entrypoint !== 'index.html') {
            return false;
        }

        $token = (string) Str::ulid();
        $claimed = LandingRelease::query()->whereKey($release->id)
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

        // Failure to enqueue a preview must never roll back a deployed release.
        DB::afterCommit(function () use ($release, $token): void {
            try {
                GenerateLandingPreview::dispatch($release->id, $token);
            } catch (Throwable $exception) {
                $this->markFailed($release->id, $token);
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

        $releases = LandingRelease::query()->where('is_active', true)->where('entrypoint', 'index.html')
            ->where(fn ($query) => $query->whereNull('preview_status')
                ->orWhere(fn ($query) => $query->whereIn('preview_status', ['queued', 'processing'])
                    ->where('preview_requested_at', '<', now()->subMinutes(5))))
            ->limit(50)->get();

        return $releases->filter(fn ($release) => $this->request($release))->count();
    }

    public function generate(LandingRelease $release, string $token): void
    {
        $disk = Storage::disk(config('fast-landings.storage_disk'));
        $path = $release->previewDirectory().'/'.$token.'.png';
        $temporary = $path.'.tmp';
        $disk->makeDirectory($release->previewDirectory());

        try {
            $result = Process::path(base_path())->timeout(55)->env([
                'FAST_LANDINGS_CHROMIUM_PATH' => config('fast-landings.previews.chromium_path') ?: false,
            ])->run([
                config('fast-landings.previews.node_binary'),
                base_path('scripts/capture-landing.mjs'),
                $disk->path($release->storage_path),
                $disk->path($temporary),
                $release->entrypoint,
            ]);

            $result->throw();
            if (! $disk->exists($temporary) || @getimagesize($disk->path($temporary)) === false) {
                throw new RuntimeException('The preview renderer did not produce an image.');
            }

            $disk->move($temporary, $path);
            $updated = LandingRelease::query()->whereKey($release->id)->where('preview_token', $token)->update([
                'preview_status' => 'ready',
                'preview_path' => $path,
                'preview_generated_at' => now(),
            ]);

            if (! $updated) {
                $disk->delete($path);
            } elseif ($release->preview_path && $release->preview_path !== $path) {
                $disk->delete($release->preview_path);
            }
        } finally {
            $disk->delete($temporary);
        }
    }

    public function markFailed(string $releaseId, string $token): void
    {
        LandingRelease::query()->whereKey($releaseId)->where('preview_token', $token)
            ->update(['preview_status' => 'failed']);
    }
}
