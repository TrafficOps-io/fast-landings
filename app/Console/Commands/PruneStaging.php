<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class PruneStaging extends Command
{
    protected $signature = 'fast-landings:prune-staging {--hours=24 : Remove staging directories older than this many hours}';

    protected $description = 'Remove abandoned extraction directories and file editor drafts';

    public function handle(): int
    {
        $hours = filter_var($this->option('hours'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 720],
        ]);
        if ($hours === false) {
            $this->error('The --hours value must be an integer between 1 and 720.');

            return self::FAILURE;
        }

        $disk = Storage::disk(config('fast-landings.storage_disk'));
        $cutoff = now()->subHours($hours)->getTimestamp();
        $removed = 0;

        foreach ($disk->directories() as $landingDirectory) {
            foreach ($disk->directories($landingDirectory.'/staging') as $stagingDirectory) {
                $modifiedAt = @filemtime($disk->path($stagingDirectory));
                if ($modifiedAt !== false && $modifiedAt <= $cutoff && $disk->deleteDirectory($stagingDirectory)) {
                    $removed++;
                }
            }
        }

        foreach ($disk->directories('_file_editor') as $userDirectory) {
            foreach ($disk->directories($userDirectory) as $workspace) {
                $lockPath = $disk->path($workspace.'/lock');
                if (is_link($lockPath)) {
                    continue;
                }
                $lock = @fopen($lockPath, 'r+');
                if ($lock === false) {
                    // A process can exit while initially copying files, before it has written
                    // the lock/metadata. No browser can open that incomplete workspace.
                    $modifiedAt = @filemtime($disk->path($workspace));
                    if (! file_exists($lockPath) && $modifiedAt !== false && $modifiedAt <= $cutoff && $disk->deleteDirectory($workspace)) {
                        $removed++;
                    }

                    continue;
                }
                try {
                    if (! flock($lock, LOCK_EX | LOCK_NB)) {
                        continue;
                    }
                    clearstatcache(true, $lockPath);
                    $modifiedAt = @filemtime($lockPath);
                    if ($modifiedAt !== false && $modifiedAt <= $cutoff && $disk->deleteDirectory($workspace)) {
                        $removed++;
                    }
                } finally {
                    flock($lock, LOCK_UN);
                    fclose($lock);
                }
            }
        }

        $this->info("Removed {$removed} abandoned staging director".($removed === 1 ? 'y.' : 'ies.'));

        return self::SUCCESS;
    }
}
