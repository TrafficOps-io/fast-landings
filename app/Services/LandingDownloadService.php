<?php

namespace App\Services;

use App\Models\Landing;
use App\Models\LandingRelease;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;
use ZipArchive;

class LandingDownloadService
{
    /** The caller must delete the temporary ZIP after sending it to the browser. */
    public function archive(LandingRelease $release): string
    {
        // Use the same lock order as release deletion, keeping the files available
        // until ZipArchive has finished reading them.
        return DB::transaction(function () use ($release): string {
            Landing::query()->whereKey($release->landing_id)->lockForUpdate()->firstOrFail();
            $release = LandingRelease::query()->lockForUpdate()->findOrFail($release->id);
            $directory = $this->directory($release->storage_path);
            abort_unless(in_array($release->entrypoint, ['index.html', 'index.php'], true)
                && is_file($directory.'/'.$release->entrypoint), 404);

            $temporary = tempnam(sys_get_temp_dir(), 'fast-landings-download-');
            if ($temporary === false) {
                throw new RuntimeException('Unable to create the landing archive.');
            }

            $zip = new ZipArchive;
            try {
                if ($zip->open($temporary, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                    throw new RuntimeException('Unable to open the landing archive.');
                }
                try {
                    $files = new RecursiveIteratorIterator(
                        new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
                        RecursiveIteratorIterator::SELF_FIRST,
                    );
                    foreach ($files as $file) {
                        abort_if($file->isLink(), 404);
                        if ($file->isDir()) {
                            continue;
                        }
                        abort_unless($file->isFile() && $file->isReadable(), 404);
                        $relative = substr($file->getPathname(), strlen($directory) + 1);
                        // Export the ready-to-run pages, without private editor metadata.
                        if ($relative === LandingRuntimeCompiler::SOURCE_MANIFEST) {
                            continue;
                        }
                        if (! $zip->addFile($file->getPathname(), $relative)) {
                            throw new RuntimeException('Unable to write the landing archive.');
                        }
                    }
                } finally {
                    if (! $zip->close()) {
                        throw new RuntimeException('Unable to finish the landing archive.');
                    }
                }
                clearstatcache(true, $temporary);

                return $temporary;
            } catch (Throwable $exception) {
                @unlink($temporary);
                throw $exception;
            }
        });
    }

    private function directory(string $path): string
    {
        $directory = rtrim(Storage::disk(config('fast-landings.storage_disk'))->path(''), DIRECTORY_SEPARATOR);
        foreach (explode('/', $path) as $segment) {
            abort_if(in_array($segment, ['', '.', '..'], true) || str_contains($segment, '\\'), 404);
            $directory .= '/'.$segment;
            abort_if(is_link($directory), 404);
        }
        abort_unless(is_dir($directory), 404);

        return $directory;
    }
}
