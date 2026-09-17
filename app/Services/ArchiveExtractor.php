<?php

namespace App\Services;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Validation\ValidationException;
use Normalizer;
use RuntimeException;
use ZipArchive;

/** Shared path, size and stream validation for landing and template packages. */
class ArchiveExtractor
{
    /** @return array{0: list<array{name: string, size: int}>, 1: string, 2: int} */
    public function inspect(ZipArchive $zip, string|array $entrypoint = ['index.php', 'index.html']): array
    {
        $files = [];
        $canonicalPaths = [];
        $totalBytes = 0;
        $totalCompressedBytes = 0;
        $maxFiles = (int) config('fast-landings.max_files');
        $maxBytes = (int) config('fast-landings.max_extracted_bytes');

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $stat = $zip->statIndex($index);
            $name = str_replace('\\', '/', (string) ($stat['name'] ?? ''));

            if ($this->isMetadata($name) || str_ends_with($name, '/')) {
                continue;
            }

            if (! $this->isSafePath($name) || $this->isUnsafeEntryType($zip, $index)) {
                throw ValidationException::withMessages(['archive' => "The archive contains an unsafe path: {$name}"]);
            }

            $canonical = mb_strtolower(Normalizer::normalize($name, Normalizer::FORM_C) ?: $name);
            if (isset($canonicalPaths[$canonical])) {
                throw ValidationException::withMessages(['archive' => "The archive contains duplicate or colliding paths: {$name}"]);
            }
            $canonicalPaths[$canonical] = true;

            $size = (int) ($stat['size'] ?? 0);
            $files[] = ['name' => $name, 'size' => $size];
            $totalBytes += $size;
            $totalCompressedBytes += (int) ($stat['comp_size'] ?? 0);

            if (count($files) > $maxFiles) {
                throw ValidationException::withMessages(['archive' => "The archive contains more than {$maxFiles} files."]);
            }

            if ($totalBytes > $maxBytes) {
                throw ValidationException::withMessages(['archive' => 'The extracted archive is larger than the configured limit.']);
            }
        }

        $maxRatio = max(1, (int) config('fast-landings.max_expansion_ratio'));
        if ($totalBytes > 1024 * 1024 && $totalBytes / max(1, $totalCompressedBytes) > $maxRatio) {
            throw ValidationException::withMessages(['archive' => "The archive exceeds the maximum {$maxRatio}:1 expansion ratio."]);
        }

        if ($files === []) {
            throw ValidationException::withMessages(['archive' => 'The archive does not contain any files.']);
        }

        $names = array_column($files, 'name');
        $prefix = $this->entrypointPrefix($names, $entrypoint);
        $entrypointLabel = implode(' or ', (array) $entrypoint);
        if ($prefix !== '' && collect($names)->contains(fn (string $file): bool => ! str_starts_with($file, $prefix))) {
            throw ValidationException::withMessages(['archive' => "All files must be inside the same top-level folder as {$entrypointLabel}."]);
        }

        return [$files, $prefix, $totalBytes];
    }

    /** @param list<string> $files */
    private function entrypointPrefix(array $files, string|array $entrypoint): string
    {
        $entrypoints = (array) $entrypoint;
        $label = implode(' or ', $entrypoints);
        if (array_intersect($entrypoints, $files) !== []) {
            return '';
        }

        // Nested pages can have their own index. Only a direct child folder can
        // wrap a site; an index deeper in the tree is never an archive root.
        $prefixes = [];
        foreach ($files as $file) {
            if (substr_count($file, '/') === 1 && in_array(basename($file), $entrypoints, true)) {
                $prefixes[dirname($file).'/'] = true;
            }
        }
        if (count($prefixes) !== 1) {
            throw ValidationException::withMessages(['archive' => "The archive must contain {$label} at its root or inside one top-level folder."]);
        }

        return array_key_first($prefixes);
    }

    public function isSafePath(string $path): bool
    {
        if ($path === ''
            || ! mb_check_encoding($path, 'UTF-8')
            || strlen($path) > (int) config('fast-landings.max_path_bytes')
            || preg_match('/[\x00-\x1F\x7F]/u', $path)
            || str_starts_with($path, '/')
            || preg_match('/^[A-Za-z]:\//', $path)) {
            return false;
        }

        $segments = explode('/', $path);
        if (count($segments) > (int) config('fast-landings.max_path_depth')) {
            return false;
        }

        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
        }

        return true;
    }

    private function isUnsafeEntryType(ZipArchive $zip, int $index): bool
    {
        $operatingSystem = 0;
        $attributes = 0;

        if (! $zip->getExternalAttributesIndex($index, $operatingSystem, $attributes)
            || $operatingSystem !== ZipArchive::OPSYS_UNIX) {
            return false;
        }

        $type = ($attributes >> 16) & 0170000;

        // Some ZIP writers omit Unix type bits entirely. When they are present,
        // accept regular files only; links, devices, FIFOs, sockets, and directory
        // entries without a trailing slash are never landing content.
        return $type !== 0 && $type !== 0100000;
    }

    private function isMetadata(string $path): bool
    {
        return str_starts_with($path, '__MACOSX/') || str_ends_with($path, '/.DS_Store') || $path === '.DS_Store';
    }

    public function extractFile(
        ZipArchive $zip,
        FilesystemAdapter $disk,
        string $source,
        string $destination,
        int $expectedBytes,
        int $alreadyExtracted,
    ): int {
        $stream = $zip->getStream($source);
        if ($stream === false || ! $disk->makeDirectory(dirname($destination))) {
            throw new RuntimeException("Unable to extract [{$source}].");
        }

        $output = fopen($disk->path($destination), 'xb');
        if ($output === false) {
            fclose($stream);
            throw new RuntimeException("Unable to create [{$source}].");
        }

        $written = 0;
        $maxBytes = (int) config('fast-landings.max_extracted_bytes');

        try {
            while (! feof($stream)) {
                $chunk = fread($stream, 1024 * 1024);
                if ($chunk === false) {
                    throw new RuntimeException("Unable to read [{$source}].");
                }
                if ($chunk === '') {
                    continue;
                }

                $length = strlen($chunk);
                if ($alreadyExtracted + $written + $length > $maxBytes) {
                    throw ValidationException::withMessages(['archive' => 'The extracted archive is larger than the configured limit.']);
                }

                for ($offset = 0; $offset < $length;) {
                    $bytes = fwrite($output, substr($chunk, $offset));
                    if ($bytes === false || $bytes === 0) {
                        throw new RuntimeException("Unable to write [{$source}].");
                    }
                    $offset += $bytes;
                }

                $written += $length;
            }
        } finally {
            fclose($output);
            fclose($stream);
        }

        if ($written !== $expectedBytes) {
            throw ValidationException::withMessages(['archive' => "The size metadata for [{$source}] is invalid."]);
        }

        return $written;
    }
}
