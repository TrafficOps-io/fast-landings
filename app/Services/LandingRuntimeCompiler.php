<?php

namespace App\Services;

use App\Services\Templates\TemplateRequestRuntime;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Validation\ValidationException;
use Normalizer;
use RuntimeException;

/** Turn request-dependent pages into scripts only inside an unpublished release. */
class LandingRuntimeCompiler
{
    public const SOURCE_MANIFEST = '.runtime-sources.json';

    public function __construct(private TemplateRequestRuntime $runtime) {}

    /** @return array{0: int, 1: int} Final size and file count after compilation. */
    public function compileDirectory(FilesystemAdapter $disk, string $directory): array
    {
        $files = $disk->allFiles($directory);
        $outputs = [];
        $changes = [];
        $sources = [];
        $total = 0;
        foreach ($files as $file) {
            $path = substr($file, strlen($directory) + 1);
            if (strtolower(explode('/', $path)[0]) === self::SOURCE_MANIFEST) {
                throw ValidationException::withMessages(['archive' => 'The .runtime-sources.json path is reserved for original runtime page sources.']);
            }
            $output = $path;
            $contents = null;
            if (preg_match('/\.(?:html|php)$/D', $path)) {
                $source = $disk->get($file);
                $output = preg_replace('/\.tpl\.(html|php)$/D', '.$1', $path);
                if ($this->runtime->hasRuntime($source)) {
                    $contents = $this->runtime->compile($source);
                    $output = preg_replace('/\.html$/D', '.php', $output);
                } elseif ($output !== $path) {
                    $contents = $source;
                }
            }
            $canonical = mb_strtolower(Normalizer::normalize($output, Normalizer::FORM_C) ?: $output);
            if (isset($outputs[$canonical])) {
                throw ValidationException::withMessages(['archive' => "Runtime page {$path} conflicts with {$outputs[$canonical]} at {$output}. Rename one of the pages."]);
            }
            $outputs[$canonical] = $path;
            $total += $contents !== null ? strlen($contents) : $disk->size($file);
            if ($total > (int) config('fast-landings.max_extracted_bytes')) {
                throw ValidationException::withMessages(['archive' => 'The compiled landing exceeds the configured extracted size limit.']);
            }
            if ($contents !== null) {
                $changes[] = [$file, $directory.'/'.$output, $contents];
                $sources[$output] = ['path' => $path, 'content' => $source];
            }
        }
        $manifest = $sources === [] ? null : json_encode(['version' => 1, 'pages' => $sources], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($manifest !== null) {
            $total += strlen($manifest);
        }
        $count = count($outputs) + (int) ($manifest !== null);
        if ($total > (int) config('fast-landings.max_extracted_bytes') || $count > (int) config('fast-landings.max_files')) {
            throw ValidationException::withMessages(['archive' => 'The compiled landing and its editable sources exceed the configured archive limits.']);
        }
        // Check every collision and size before replacing any source file.
        foreach ($changes as [$source, $destination, $contents]) {
            if (! $disk->put($destination, $contents) || ($source !== $destination && ! $disk->delete($source))) {
                throw new RuntimeException('Unable to compile a landing request page.');
            }
        }
        if ($manifest !== null && ! $disk->put($directory.'/'.self::SOURCE_MANIFEST, $manifest)) {
            throw new RuntimeException('Unable to preserve the editable landing sources.');
        }

        return [$total, $count];
    }

    /** Sources are private to the release; the public routers reject dotfiles. */
    public function editableSources(FilesystemAdapter $disk, string $directory): array
    {
        $manifest = $directory.'/'.self::SOURCE_MANIFEST;
        if (! $disk->fileExists($manifest)) {
            return [];
        }
        $data = json_decode($disk->get($manifest), true);
        if (! is_array($data) || ($data['version'] ?? null) !== 1 || ! is_array($data['pages'] ?? null)) {
            throw ValidationException::withMessages(['files' => 'The saved runtime page sources are invalid.']);
        }
        $paths = [];
        foreach ($data['pages'] as $output => $page) {
            $source = $page['path'] ?? null;
            if (! is_string($output) || ! is_string($source) || ! is_string($page['content'] ?? null)
                || ! app(ArchiveExtractor::class)->isSafePath($output) || ! app(ArchiveExtractor::class)->isSafePath($source)
                || ! preg_match('/\.(?:html|php)$/D', $output) || ! preg_match('/\.(?:html|php)$/D', $source)
                || str_contains($output, '\\') || str_contains($source, '\\') || isset($paths[$source])) {
                throw ValidationException::withMessages(['files' => 'The saved runtime page source paths are invalid.']);
            }
            $paths[$source] = true;
        }

        return $data['pages'];
    }
}
