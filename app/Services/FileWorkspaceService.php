<?php

namespace App\Services;

use App\Models\Landing;
use App\Models\LandingRelease;
use App\Models\LandingTemplate;
use App\Models\User;
use App\Services\Templates\TemplateArchiveService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use Normalizer;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;
use ZipArchive;

/** Private, owner-scoped drafts. Published packages and releases are never edited in place. */
class FileWorkspaceService
{
    private const MAX_TEXT_BYTES = 2 * 1024 * 1024;

    public function __construct(private ArchiveExtractor $extractor) {}

    /**
     * Open a draft: a private working copy of a template's package or of a
     * landing's active release. A non-active release is refused.
     */
    public function open(LandingTemplate|LandingRelease $target, User $user): string
    {
        $user = $this->authorize($user, $target instanceof LandingTemplate);
        $id = (string) Str::ulid();
        $base = $this->base($id, $user);
        $disk = Storage::disk(config('fast-landings.storage_disk'));

        try {
            DB::transaction(function () use ($target, $user, $id, $base, $disk): void {
                if ($target instanceof LandingTemplate) {
                    $target = LandingTemplate::query()->lockForUpdate()->findOrFail($target->id);
                    $metadata = [
                        'kind' => 'template', 'target_id' => $target->id, 'name' => $target->name,
                        'revision' => $target->storage_path, 'template_linked' => false,
                        'entrypoint' => $target->definition['source'] ?? 'template.tpl',
                    ];
                } else {
                    $landing = Landing::query()->lockForUpdate()->findOrFail($target->landing_id);
                    $target = LandingRelease::query()->lockForUpdate()->findOrFail($target->id);
                    // A draft is a working copy of the active release only; editing an older
                    // release is done by activating it first.
                    if (! $target->isDraftable()) {
                        throw ValidationException::withMessages([
                            'release' => __('Activate this release first.'),
                        ]);
                    }
                    $metadata = [
                        'kind' => 'landing', 'target_id' => $target->id, 'landing_id' => $landing->id,
                        'name' => $landing->name, 'revision' => (string) $landing->activeRelease?->id,
                        'template_linked' => $target->landing_template_id !== null, 'entrypoint' => $target->entrypoint,
                    ];
                }

                $source = $this->absolute($target->storage_path);
                abort_unless(is_dir($source), 404);
                $entries = $this->inventory($source);
                $this->assertLimits($entries);
                $runtimeSources = $target instanceof LandingRelease
                    ? app(LandingRuntimeCompiler::class)->editableSources($disk, $target->storage_path) : [];
                $this->absolute($base.'/content');
                if (! $disk->makeDirectory($base.'/content')) {
                    throw new RuntimeException('Unable to create the file draft.');
                }
                foreach ($entries as $file) {
                    if ($runtimeSources !== [] && ($file['path'] === LandingRuntimeCompiler::SOURCE_MANIFEST || isset($runtimeSources[$file['path']]))) {
                        continue;
                    }
                    $destination = $base.'/content/'.$file['path'];
                    if (! $disk->makeDirectory(dirname($destination)) || ! copy($source.'/'.$file['path'], $this->absolute($destination))) {
                        throw new RuntimeException('Unable to copy a file into the draft.');
                    }
                }
                foreach ($runtimeSources as $output => $page) {
                    if (! $disk->put($base.'/content/'.$page['path'], $page['content'])) {
                        throw new RuntimeException('Unable to restore an editable runtime page.');
                    }
                    if ($output === $metadata['entrypoint']) {
                        $metadata['entrypoint'] = $page['path'];
                    }
                }
                if ($metadata['kind'] === 'template') {
                    foreach ($entries as $file) {
                        if (in_array($file['path'], TemplateArchiveService::ENTRYPOINTS, true)) {
                            $metadata['entrypoint'] = $file['path'];
                            break;
                        }
                    }
                }
                $metadata += ['id' => $id, 'user_id' => $user->id, 'created_at' => now()->toIso8601String()];
                if (! $disk->put($base.'/metadata.json', json_encode($metadata, JSON_THROW_ON_ERROR)) || ! $disk->put($base.'/lock', '')) {
                    throw new RuntimeException('Unable to store draft metadata.');
                }
            });
        } catch (Throwable $exception) {
            $disk->deleteDirectory($base);
            throw $exception;
        }

        return $id;
    }

    public function info(string $id, User $user): array
    {
        return $this->withWorkspace($id, $user, fn (string $base, array $metadata): array => $metadata);
    }

    /** @return list<array{path: string, size: int, editable: bool}> */
    public function files(string $id, User $user): array
    {
        return $this->withWorkspace($id, $user, fn (string $base): array => $this->inventory($this->absolute($base.'/content'), true));
    }

    /** Read one consistent editor view without reauthorizing and relocking for every source. */
    public function snapshot(string $id, User $user): array
    {
        return $this->withWorkspace($id, $user, function (string $base, array $metadata): array {
            $files = $this->inventory($this->absolute($base.'/content'), true);
            $candidates = array_values(array_filter($files, fn (array $file): bool => $file['editable']
                && in_array(strtolower(pathinfo($file['path'], PATHINFO_EXTENSION)), ['tpl', 'html', 'php', 'txt'], true)));
            usort($candidates, fn (array $left, array $right): int => ((int) ($right['path'] === $metadata['entrypoint']) <=> (int) ($left['path'] === $metadata['entrypoint']))
                ?: strcmp($left['path'], $right['path']));

            $sources = [];
            $remaining = self::MAX_TEXT_BYTES;
            foreach ($candidates as $file) {
                if ($file['size'] > $remaining) {
                    continue;
                }
                $sources[$file['path']] = file_get_contents($this->existingFile($base, $file['path']));
                $remaining -= $file['size'];
            }

            return ['info' => $metadata, 'files' => $files, 'sources' => $sources];
        });
    }

    public function read(string $id, string $path, User $user): string
    {
        return $this->withWorkspace($id, $user, function (string $base) use ($path): string {
            $absolute = $this->existingFile($base, $path);
            if (! $this->editable($absolute)) {
                $this->invalid('This file is binary, is not UTF-8 text, or exceeds the 2 MB editor limit. Download it to view it.');
            }

            return file_get_contents($absolute);
        });
    }

    public function write(string $id, string $path, string $content, User $user, bool $create = false): void
    {
        $this->withWorkspace($id, $user, function (string $base) use ($path, $content, $create): void {
            if (strlen($content) > self::MAX_TEXT_BYTES || ! $this->isText($content)) {
                $this->invalid('Editor files must contain UTF-8 text and cannot exceed 2 MB.');
            }
            $absolute = $this->absolute($base.'/content/'.$this->safePath($path));
            if (! $create) {
                $absolute = $this->existingFile($base, $path);
                if (! $this->editable($absolute)) {
                    $this->invalid('This file cannot be edited as text.');
                }
            }
            $entries = $this->inventory($this->absolute($base.'/content'));
            $this->assertDestination($entries, $path, $create ? null : $path);
            $this->assertLimits($entries, $path, strlen($content));
            $this->atomicStore($base, $absolute, function (string $temporary) use ($content): void {
                if (file_put_contents($temporary, $content) === false) {
                    throw new RuntimeException('Unable to save the draft file.');
                }
            });
        });
    }

    public function upload(string $id, string $path, UploadedFile $file, User $user, bool $overwrite = false): void
    {
        $this->withWorkspace($id, $user, function (string $base) use ($path, $file, $overwrite): void {
            if (! $file->isValid() || $file->getSize() > (int) config('fast-landings.max_upload_kb') * 1024) {
                $this->invalid('The uploaded file is invalid or exceeds the upload limit.');
            }
            $absolute = $this->absolute($base.'/content/'.$this->safePath($path));
            $entries = $this->inventory($this->absolute($base.'/content'));
            $this->assertDestination($entries, $path, $overwrite ? $path : null);
            $this->assertLimits($entries, $path, $file->getSize());
            $this->atomicStore($base, $absolute, function (string $temporary) use ($file): void {
                if (! copy($file->getRealPath(), $temporary)) {
                    throw new RuntimeException('Unable to upload the draft file.');
                }
            });
        });
    }

    public function rename(string $id, string $from, string $to, User $user): void
    {
        $this->withWorkspace($id, $user, function (string $base) use ($from, $to): void {
            $source = $this->existingFile($base, $from);
            $destination = $this->absolute($base.'/content/'.$this->safePath($to));
            if ($from === $to) {
                return;
            }
            if (is_dir($destination)) {
                $this->invalid('A folder already exists at this path. Choose another name.');
            }
            $this->assertDestination($this->inventory($this->absolute($base.'/content')), $to, $from);
            $this->makeDirectory(dirname($destination));
            if (! rename($source, $destination)) {
                throw new RuntimeException('Unable to rename the draft file.');
            }
            $this->pruneEmptyParents(dirname($source), $this->absolute($base.'/content'));
        });
    }

    public function delete(string $id, string $path, User $user): void
    {
        $this->withWorkspace($id, $user, function (string $base) use ($path): void {
            $absolute = $this->existingFile($base, $path);
            if (! unlink($absolute)) {
                throw new RuntimeException('Unable to delete the draft file.');
            }
            $this->pruneEmptyParents(dirname($absolute), $this->absolute($base.'/content'));
        });
    }

    public function absolutePath(string $id, string $path, User $user): string
    {
        return $this->withWorkspace($id, $user, fn (string $base): string => $this->existingFile($base, $path));
    }

    /** The caller must delete this temporary ZIP after sending it to the browser. */
    public function archive(string $id, User $user): string
    {
        return $this->withWorkspace($id, $user, fn (string $base): string => $this->zip($base));
    }

    /**
     * Publish a draft as a new release (landing) or a replaced package (template).
     *
     * A draft of a template landing is refused here: publishing it would turn the
     * landing into a file landing, which is the explicit operation
     * publishDetachingFromTemplate().
     */
    public function publish(string $id, User $user): LandingTemplate|LandingRelease
    {
        return $this->publishDraft($id, $user, detachFromTemplate: false);
    }

    /** Detach from template: publish a template landing's draft, making it a file landing. */
    public function publishDetachingFromTemplate(string $id, User $user): LandingRelease
    {
        $result = $this->publishDraft($id, $user, detachFromTemplate: true);
        if (! $result instanceof LandingRelease) {
            throw new LogicException('Only a landing draft can detach from a template.');
        }

        return $result;
    }

    private function publishDraft(string $id, User $user, bool $detachFromTemplate): LandingTemplate|LandingRelease
    {
        return $this->withWorkspace($id, $user, function (string $base, array $metadata) use ($user, $detachFromTemplate): LandingTemplate|LandingRelease {
            $archive = $this->zip($base);
            try {
                $file = new UploadedFile($archive, $metadata['kind'].'-files.zip', 'application/zip', null, true);
                if ($metadata['kind'] === 'template') {
                    $template = LandingTemplate::query()->findOrFail($metadata['target_id']);
                    $result = app(TemplateArchiveService::class)->update($template, [
                        'name' => $template->name, 'description' => $template->description,
                    ], $file, $user, $metadata['revision'], trustedWorkspaceArchive: true);
                } else {
                    // Edited files become a release without a template snapshot. LandingArchiveService
                    // refuses that for a template landing unless the operator detaches explicitly.
                    $landing = Landing::query()->findOrFail($metadata['landing_id']);
                    $archives = app(LandingArchiveService::class);
                    $result = $detachFromTemplate
                        ? $archives->deployDetachingFromTemplate($landing, $file, $user, expectedActiveReleaseId: $metadata['revision'])
                        : $archives->deploy($landing, $file, $user, expectedActiveReleaseId: $metadata['revision']);
                }
                Storage::disk(config('fast-landings.storage_disk'))->deleteDirectory($base);

                return $result;
            } finally {
                @unlink($archive);
            }
        });
    }

    public function discard(string $id, User $user): void
    {
        $this->withWorkspace($id, $user, function (string $base): void {
            Storage::disk(config('fast-landings.storage_disk'))->deleteDirectory($base);
        });
    }

    private function withWorkspace(string $id, User $user, callable $callback): mixed
    {
        $user = $this->authorize($user);
        $base = $this->base($id, $user);
        $metadataPath = $this->absolute($base.'/metadata.json');
        abort_unless(is_file($metadataPath), 404);
        $lockPath = $this->absolute($base.'/lock');
        $lock = @fopen($lockPath, 'r+');
        abort_unless($lock !== false, 404);

        try {
            if (! flock($lock, LOCK_EX)) {
                throw new RuntimeException('Unable to lock the file draft.');
            }
            clearstatcache(true, $metadataPath);
            abort_unless(is_file($metadataPath), 404);
            $metadata = json_decode(file_get_contents($metadataPath), true, flags: JSON_THROW_ON_ERROR);
            abort_unless((string) ($metadata['user_id'] ?? '') === (string) $user->id, 404);
            $this->authorize($user, $metadata['kind'] === 'template');
            touch($lockPath);
            touch($this->absolute($base));

            return $callback($base, $metadata);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function authorize(User $user, bool $template = false): User
    {
        $user = $user->fresh();
        abort_unless($user?->is_active && (! $template || $user->isAdministrator()), 403);

        return $user;
    }

    private function base(string $id, User $user): string
    {
        abort_unless(Str::isUlid($id), 404);

        return '_file_editor/'.$user->id.'/'.$id;
    }

    private function safePath(string $path): string
    {
        if (str_contains($path, '\\') || ! $this->extractor->isSafePath($path)) {
            $this->invalid('Use a safe relative file path without empty segments, backslashes, or traversal.');
        }

        return $path;
    }

    private function absolute(string $path): string
    {
        // Validate the user-controlled portion independently: the workspace prefix must not
        // reduce the configured maximum relative path length or depth for existing packages.
        $root = rtrim(Storage::disk(config('fast-landings.storage_disk'))->path(''), DIRECTORY_SEPARATOR);
        $absolute = $root;
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..' || str_contains($segment, '\\')) {
                $this->invalid('The file path is unsafe.');
            }
            $absolute .= '/'.$segment;
            if (is_link($absolute)) {
                $this->invalid('Symbolic links are not allowed in file drafts.');
            }
        }

        return $absolute;
    }

    private function existingFile(string $base, string $path): string
    {
        $absolute = $this->absolute($base.'/content/'.$this->safePath($path));
        abort_unless(is_file($absolute), 404);

        return $absolute;
    }

    private function inventory(string $directory, bool $withEditable = false): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($iterator as $entry) {
            if ($entry->isLink() || ! $entry->isFile()) {
                $this->invalid('Only regular files are allowed in file drafts.');
            }
            $path = $this->safePath(substr($entry->getPathname(), strlen($directory) + 1));
            $file = ['path' => $path, 'size' => $entry->getSize()];
            if ($withEditable) {
                $file['editable'] = $this->editable($entry->getPathname());
            }
            $files[] = $file;
        }
        usort($files, fn (array $left, array $right): int => strcmp($left['path'], $right['path']));

        return $files;
    }

    private function editable(string $path): bool
    {
        return filesize($path) <= self::MAX_TEXT_BYTES && $this->isText(file_get_contents($path));
    }

    private function isText(string $content): bool
    {
        return mb_check_encoding($content, 'UTF-8') && ! preg_match('/[\x00-\x08\x0B\x0E-\x1F\x7F]/', $content);
    }

    private function assertDestination(array $entries, string $path, ?string $ignore): void
    {
        $canonical = $this->canonical($path);
        foreach ($entries as $entry) {
            if ($entry['path'] === $ignore) {
                continue;
            }
            $existing = $this->canonical($entry['path']);
            if ($canonical === $existing || str_starts_with($canonical, $existing.'/') || str_starts_with($existing, $canonical.'/')) {
                $this->invalid('A file or folder already exists at this path. Choose another name.');
            }
            $segments = explode('/', $path);
            $existingSegments = explode('/', $entry['path']);
            for ($index = 0; $index < min(count($segments), count($existingSegments)) - 1; $index++) {
                if ($this->canonical($segments[$index]) !== $this->canonical($existingSegments[$index])) {
                    break;
                }
                if ($segments[$index] !== $existingSegments[$index]) {
                    $this->invalid('Folder names cannot differ only by capitalization or Unicode normalization.');
                }
            }
        }
    }

    private function canonical(string $path): string
    {
        return mb_strtolower(Normalizer::normalize($path, Normalizer::FORM_C) ?: $path);
    }

    private function assertLimits(array $entries, ?string $replacement = null, int $bytes = 0): void
    {
        $count = $replacement === null ? 0 : 1;
        foreach ($entries as $entry) {
            if ($entry['path'] !== $replacement) {
                $count++;
                $bytes += $entry['size'];
            }
        }
        if ($count > (int) config('fast-landings.max_files') || $bytes > (int) config('fast-landings.max_extracted_bytes')) {
            $this->invalid('The draft exceeds the configured file count or total size limit.');
        }
    }

    private function atomicStore(string $base, string $destination, callable $writer): void
    {
        if (is_dir($destination)) {
            $this->invalid('A folder already exists at this path. Choose another name.');
        }
        $temporary = $this->absolute($base.'/'.Str::ulid().'.tmp');
        try {
            $writer($temporary);
            $this->makeDirectory(dirname($destination));
            if (! rename($temporary, $destination)) {
                throw new RuntimeException('Unable to save the draft file.');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    private function makeDirectory(string $path): void
    {
        if (! is_dir($path) && ! mkdir($path, 0755, true) && ! is_dir($path)) {
            throw new RuntimeException('Unable to create the draft folder.');
        }
    }

    private function pruneEmptyParents(string $directory, string $root): void
    {
        while ($directory !== $root && str_starts_with($directory, $root.'/') && @rmdir($directory)) {
            $directory = dirname($directory);
        }
    }

    private function zip(string $base): string
    {
        $entries = $this->inventory($this->absolute($base.'/content'));
        $this->assertLimits($entries);
        if ($entries === []) {
            $this->invalid('The draft does not contain any files.');
        }
        $temporary = tempnam(sys_get_temp_dir(), 'fast-landings-files-');
        if ($temporary === false) {
            throw new RuntimeException('Unable to create the archive.');
        }
        $zip = new ZipArchive;
        try {
            if ($zip->open($temporary, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Unable to open the archive.');
            }
            try {
                foreach ($entries as $file) {
                    if (! $zip->addFile($this->existingFile($base, $file['path']), $file['path'])
                        || ! $zip->setCompressionName($file['path'], ZipArchive::CM_STORE)) {
                        throw new RuntimeException('Unable to write the archive.');
                    }
                }
            } finally {
                if (! $zip->close()) {
                    throw new RuntimeException('Unable to finish the archive.');
                }
            }

            return $temporary;
        } catch (Throwable $exception) {
            @unlink($temporary);
            throw $exception;
        }
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['files' => $message]);
    }
}
