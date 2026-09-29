<?php

namespace App\Services;

use App\Models\Landing;
use App\Models\LandingRelease;
use App\Models\LandingTemplate;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;
use ZipArchive;

class LandingArchiveService
{
    /**
     * Deploy: create a release from an archive and make it the active release.
     *
     * Without a template this deploys raw files, which for a template landing
     * would silently turn it into a file landing. That is the explicit operation
     * "Detach from template" (see deployDetachingFromTemplate); here it is refused.
     */
    public function deploy(Landing $landing, UploadedFile $archive, User $uploader, ?LandingTemplate $template = null, ?array $templateValues = null, ?string $expectedActiveReleaseId = null): LandingRelease
    {
        return $this->store($landing, $archive, $uploader, $template, $templateValues, $expectedActiveReleaseId, detachFromTemplate: false);
    }

    /**
     * Detach from template: deploy raw files onto a template landing, making it a
     * file landing. The previous release keeps its template snapshot, so activating
     * it returns to the template.
     */
    public function deployDetachingFromTemplate(Landing $landing, UploadedFile $archive, User $uploader, ?string $expectedActiveReleaseId = null): LandingRelease
    {
        return $this->store($landing, $archive, $uploader, null, null, $expectedActiveReleaseId, detachFromTemplate: true);
    }

    private function store(Landing $landing, UploadedFile $archive, User $uploader, ?LandingTemplate $template, ?array $templateValues, ?string $expectedActiveReleaseId, bool $detachFromTemplate): LandingRelease
    {
        $zip = new ZipArchive;
        $opened = $zip->open($archive->getRealPath());

        if ($opened !== true) {
            throw ValidationException::withMessages(['archive' => 'The uploaded file is not a readable ZIP archive.']);
        }

        $release = new LandingRelease;
        $release->id = (string) Str::ulid();
        $stagingPath = "{$landing->id}/staging/{$release->id}";
        $storagePath = "{$landing->id}/releases/{$release->id}";

        try {
            $extractor = app(ArchiveExtractor::class);
            [$files, $prefix, $totalBytes] = $extractor->inspect($zip, ['index.php', 'index.html', 'index.tpl.php', 'index.tpl.html']);
            $disk = Storage::disk(config('fast-landings.storage_disk'));
            $actualBytes = 0;

            foreach ($files as $file) {
                $relative = $prefix === '' ? $file['name'] : substr($file['name'], strlen($prefix));
                $actualBytes += $extractor->extractFile(
                    $zip,
                    $disk,
                    $file['name'],
                    "{$stagingPath}/{$relative}",
                    $file['size'],
                    $actualBytes,
                );
            }

            if ($actualBytes !== $totalBytes) {
                throw ValidationException::withMessages(['archive' => 'The archive size metadata does not match its extracted content.']);
            }

            // Compile after template settings have been rendered, so macros in
            // editable landing values resolve afresh for each visitor request.
            [$totalBytes, $fileCount] = app(LandingRuntimeCompiler::class)->compileDirectory($disk, $stagingPath);

            if (! $disk->makeDirectory(dirname($storagePath))
                || ! rename($disk->path($stagingPath), $disk->path($storagePath))) {
                throw new RuntimeException('Unable to publish the extracted release atomically.');
            }

            $release->fill([
                'landing_id' => $landing->id,
                'uploaded_by' => $uploader->id,
                'original_name' => $archive->getClientOriginalName(),
                'storage_path' => $storagePath,
                'entrypoint' => $disk->exists($storagePath.'/index.php') ? 'index.php' : 'index.html',
                'size_bytes' => $totalBytes,
                'file_count' => $fileCount,
                'checksum' => hash_file('sha256', $archive->getRealPath()),
                'is_active' => true,
                'activated_at' => now(),
                'landing_template_id' => $template?->id,
                'template_values' => $template ? $templateValues : null,
            ]);

            DB::transaction(function () use ($landing, $release, $expectedActiveReleaseId, $template, $detachFromTemplate): void {
                $locked = Landing::query()->whereKey($landing->id)->lockForUpdate()->firstOrFail();
                if ($expectedActiveReleaseId !== null && (string) $locked->activeRelease?->id !== $expectedActiveReleaseId) {
                    throw ValidationException::withMessages(['files' => 'This landing has changed since you opened the file editor. Open a new draft before publishing.']);
                }
                if ($template === null && $locked->landing_template_id !== null && ! $detachFromTemplate) {
                    throw ValidationException::withMessages([
                        'detachFromTemplate' => __('This is a template landing. Deploying files detaches it from its template; confirm "Detach from template" to continue.'),
                    ]);
                }
                LandingRelease::query()->where('landing_id', $landing->id)->update([
                    'is_active' => false,
                    'activated_at' => null,
                ]);
                $release->save();
                $locked->update([
                    'landing_template_id' => $release->landing_template_id,
                    'template_values' => $release->template_values,
                ]);
            });
        } catch (Throwable $exception) {
            Storage::disk(config('fast-landings.storage_disk'))->deleteDirectory($stagingPath);
            Storage::disk(config('fast-landings.storage_disk'))->deleteDirectory($storagePath);

            if ($exception instanceof ValidationException) {
                throw $exception;
            }

            report($exception);
            throw ValidationException::withMessages(['archive' => 'The archive could not be deployed safely.']);
        } finally {
            $zip->close();
        }

        app(LandingPreviewService::class)->request($release);

        return $release->refresh();
    }

    public function activate(Landing $landing, LandingRelease $release): void
    {
        abort_unless($release->landing_id === $landing->id, 404);

        DB::transaction(function () use ($landing, $release): void {
            $lockedLanding = Landing::query()->whereKey($landing->id)->lockForUpdate()->firstOrFail();
            $locked = LandingRelease::query()
                ->where('landing_id', $landing->id)
                ->lockForUpdate()
                ->findOrFail($release->id);

            $entrypoint = "{$locked->storage_path}/{$locked->entrypoint}";
            if (! Storage::disk(config('fast-landings.storage_disk'))->exists($entrypoint)) {
                throw ValidationException::withMessages(['release' => 'This release is missing its entrypoint on disk.']);
            }

            LandingRelease::query()->where('landing_id', $landing->id)->update([
                'is_active' => false,
                'activated_at' => null,
            ]);
            $locked->forceFill(['is_active' => true, 'activated_at' => now()])->save();
            $lockedLanding->update([
                'landing_template_id' => $locked->landing_template_id,
                'template_values' => $locked->template_values,
            ]);
        });

        app(LandingPreviewService::class)->request($release);
    }

    public function delete(LandingRelease $release): void
    {
        $path = $release->storage_path;

        DB::transaction(function () use ($release): void {
            Landing::query()->whereKey($release->landing_id)->lockForUpdate()->firstOrFail();
            $locked = LandingRelease::query()->lockForUpdate()->findOrFail($release->id);

            if ($locked->is_active) {
                throw ValidationException::withMessages(['release' => 'Activate another release before deleting this one.']);
            }

            $locked->delete();
        });

        Storage::disk(config('fast-landings.storage_disk'))->deleteDirectory($path);
        Storage::disk(config('fast-landings.storage_disk'))->deleteDirectory($release->previewDirectory());
    }
}
