<?php

namespace App\Services\Templates;

use App\Models\LandingTemplate;
use App\Models\User;
use App\Services\ArchiveExtractor;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;
use TrafficOps\TemplateDsl\TemplateEngine;
use TrafficOps\TemplateDsl\TemplateSourceParser;
use ZipArchive;

class TemplateArchiveService
{
    public const ENTRYPOINTS = ['template.html', 'template.txt', 'template.tpl', 'index.tpl.html', 'index.tpl.php', 'index.html', 'index.php'];

    public function __construct(
        private TemplateEngine $engine,
        private ArchiveExtractor $extractor,
        private TemplateSourceParser $parser,
        private ImplicitTemplateLayout $implicitLayout,
    ) {}

    public function import(UploadedFile $file, User $user): LandingTemplate
    {
        abort_unless($user->is_active && $user->isAdministrator(), 403);
        $package = $this->preparePackage($file);

        try {
            $template = LandingTemplate::query()->create([...$package, 'uploaded_by' => $user->id]);
        } catch (Throwable $exception) {
            Storage::disk(config('fast-landings.storage_disk'))->deleteDirectory($package['storage_path']);
            report($exception);
            $this->invalid('The template could not be imported. Check the package and try again.');
        }

        app(TemplatePreviewService::class)->request($template);

        return $template;
    }

    public function update(LandingTemplate $template, array $attributes, ?UploadedFile $file, User $user, ?string $expectedStoragePath = null, bool $trustedWorkspaceArchive = false): LandingTemplate
    {
        abort_unless($user->is_active && $user->isAdministrator(), 403);
        $attributes['name'] = trim($attributes['name'] ?? '');
        $attributes = Validator::make($attributes, [
            'name' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:2000'],
        ])->validate();
        $attributes['description'] = ($attributes['description'] ?? '') === '' ? null : $attributes['description'];

        // Validate and publish into a new directory before touching the current package.
        $package = $file ? $this->preparePackage($file, $trustedWorkspaceArchive) : null;
        $previousPath = null;
        $previousPreview = null;
        try {
            $template = DB::transaction(function () use ($template, $attributes, $package, $user, $expectedStoragePath, &$previousPath, &$previousPreview): LandingTemplate {
                // Compilation holds this same lock while copying template assets.
                $template = LandingTemplate::query()->lockForUpdate()->findOrFail($template->id);
                if ($expectedStoragePath !== null && $template->storage_path !== $expectedStoragePath) {
                    throw ValidationException::withMessages(['files' => 'This template has changed since you opened the file editor. Open a new draft before publishing.']);
                }
                $previousPath = $template->storage_path;
                if ($package !== null) {
                    $previousPreview = $template->preview_path;
                    $template->fill([...$package, 'uploaded_by' => $user->id]);
                    $template->forceFill([
                        'preview_status' => null,
                        'preview_token' => null,
                        'preview_path' => null,
                        'preview_requested_at' => null,
                        'preview_generated_at' => null,
                    ]);
                }
                $definition = $template->definition;
                $definition['name'] = $attributes['name'];
                $definition['description'] = $attributes['description'] ?? '';
                $template->fill([...$attributes, 'definition' => $definition])->save();

                return $template;
            });
        } catch (Throwable $exception) {
            if ($package !== null) {
                Storage::disk(config('fast-landings.storage_disk'))->deleteDirectory($package['storage_path']);
            }
            throw $exception;
        }

        if ($package !== null) {
            Storage::disk(config('fast-landings.storage_disk'))->deleteDirectory($previousPath);
            if ($previousPreview) {
                Storage::disk(config('fast-landings.storage_disk'))->delete($previousPreview);
            }
            app(TemplatePreviewService::class)->request($template);
        }

        return $template;
    }

    private function preparePackage(UploadedFile $file, bool $trustedWorkspaceArchive = false): array
    {
        $uploadRules = ['required', 'file'];
        // Workspace ZIPs are generated on the server without compression. Their size can
        // exceed the client upload limit; extraction and parser limits still apply below.
        if (! $trustedWorkspaceArchive) {
            $uploadRules[] = 'max:'.config('fast-landings.max_upload_kb');
        }
        Validator::make(['templateUpload' => $file], [
            'templateUpload' => $uploadRules,
        ])->validate();

        $extension = strtolower($file->getClientOriginalExtension());
        if (! in_array($extension, ['html', 'php', 'txt', 'tpl', 'zip'], true)) {
            $this->invalid('Upload an HTML, PHP, TXT or TPL template, or a ZIP containing a template or index entrypoint.');
        }

        $id = (string) Str::ulid();
        $staging = "_templates/staging/{$id}";
        $destination = "_templates/packages/{$id}";
        $disk = Storage::disk(config('fast-landings.storage_disk'));

        try {
            if ($extension === 'zip') {
                $entrypoint = $this->extract($file, $staging);
                $text = $this->readText($staging.'/'.$entrypoint);
            } else {
                if ($file->getSize() > config('fast-landings.templates.max_definition_bytes')) {
                    $this->invalid('The template source exceeds the 2 MB limit.');
                }
                $name = $file->getClientOriginalName();
                $entrypoint = in_array($name, self::ENTRYPOINTS, true) ? $name : ($extension === 'php' ? 'index.php' : 'template.'.$extension);
                $text = file_get_contents($file->getRealPath());
                if (! $disk->put($staging.'/'.$entrypoint, $text)) {
                    throw new RuntimeException('Unable to store the template.');
                }
            }

            $pageSources = [$entrypoint => $text];
            foreach ($disk->allFiles($staging) as $path) {
                $relative = substr($path, strlen($staging) + 1);
                if ($relative !== $entrypoint && preg_match('/\.tpl\.(?:html|php)$/D', $relative)) {
                    $pageSources[$relative] = $this->readText($path);
                }
            }
            if ($extension === 'zip') {
                $included = [];
                foreach ($this->parser->includedPaths($pageSources, function (string $path) use ($staging, &$included): string {
                    return $this->source($path, $staging, $included);
                }) as $fragment) {
                    if ($fragment !== $entrypoint) {
                        unset($pageSources[$fragment]);
                    }
                }
            }
            $sources = array_keys($pageSources);
            foreach ($pageSources as $page => $source) {
                $pageSources[$page] = $this->implicitLayout->wrap($source, $page);
            }
            $definition = $this->parser->parsePages(
                $pageSources,
                $entrypoint,
                $extension === 'zip' ? function (string $path) use ($staging, &$sources): string {
                    return $this->source($path, $staging, $sources);
                } : null,
            );
            $definition['source'] = $entrypoint;
            $definition['entrypoint'] = $this->outputPath($entrypoint);
            if ($entrypoint === 'index.php') {
                $opaque = [];
                $markup = TemplatePhpSource::protect($text, $opaque);
                if (! preg_match('/^[\t ]*@(template|param|layout|include|section|type|block|each|if|render)\b/m', $markup)) {
                    // An ordinary PHP website entrypoint is copied byte for byte.
                    $definition['html'] = $text;
                }
            }
            $pages = [];
            foreach ($definition['pages'] ?? [] as $source => $html) {
                $output = $this->outputPath($source);
                if (array_key_exists($output, $pages)) {
                    $this->invalid("Multiple template pages produce {$output}.");
                }
                $pages[$output] = $html;
            }
            $definition['pages'] = $pages;
            $definition = $this->engine->validateDefinition($definition);
            $outputs = array_map('strtolower', [$definition['entrypoint'], ...array_keys($pages)]);
            $assets = [];
            foreach ($disk->allFiles($staging) as $path) {
                $relative = substr($path, strlen($staging) + 1);
                if (in_array($relative, $sources, true)) {
                    continue;
                }
                // The generated entrypoint and upload namespace always belong to the compiler.
                if (in_array(strtolower($relative), [...$outputs, 'index.html', 'index.php'], true) || str_starts_with(strtolower($relative), '_uploads/') || str_starts_with(strtolower($relative), '_media/')) {
                    $this->invalid("Reserved asset path: {$relative}.");
                }
                $assets[] = $relative;
            }

            if (! $disk->makeDirectory(dirname($destination)) || ! rename($disk->path($staging), $disk->path($destination))) {
                throw new RuntimeException('Unable to publish the template package.');
            }

            return [
                'name' => $definition['name'],
                'description' => $definition['description'] ?? null,
                'definition' => $definition,
                'asset_paths' => $assets,
                'storage_path' => $destination,
                'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
            ];
        } catch (Throwable $exception) {
            $disk->deleteDirectory($staging);
            $disk->deleteDirectory($destination);
            if ($exception instanceof ValidationException) {
                throw ValidationException::withMessages(['templateUpload' => collect($exception->errors())->flatten()->all()]);
            }
            report($exception);
            $this->invalid('The template could not be imported. Check the package and try again.');
        }
    }

    public function delete(LandingTemplate $template): void
    {
        abort_unless(auth()->user()?->is_active && auth()->user()?->isAdministrator(), 403);
        $storagePath = DB::transaction(function () use ($template): string {
            $template = LandingTemplate::query()->lockForUpdate()->findOrFail($template->id);
            // A template used by at least one landing cannot be deleted: the landing would
            // lose its template editor. Detach or delete those landings first.
            $landingNames = $template->landings()->orderBy('name')->pluck('name');
            if ($landingNames->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'template' => __('This template is used by :count landing(s) and cannot be deleted: :names. Detach them from the template or delete them first.', [
                        'count' => $landingNames->count(),
                        'names' => $landingNames->implode(', '),
                    ]),
                ]);
            }
            $template->delete();

            return $template->storage_path;
        });
        Storage::disk(config('fast-landings.storage_disk'))->deleteDirectory($storagePath);
        Storage::disk(config('fast-landings.storage_disk'))->deleteDirectory($template->previewDirectory());
    }

    private function extract(UploadedFile $file, string $staging): string
    {
        $zip = new ZipArchive;
        if ($zip->open($file->getRealPath()) !== true) {
            $this->invalid('The uploaded file is not a readable ZIP archive.');
        }
        try {
            $rootCandidates = [];
            $wrappedCandidates = [];
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $name = str_replace('\\', '/', (string) $zip->getNameIndex($index));
                if (substr_count($name, '/') <= 1 && in_array(basename($name), self::ENTRYPOINTS, true)) {
                    if (str_contains($name, '/')) {
                        $wrappedCandidates[] = basename($name);
                    } else {
                        $rootCandidates[] = $name;
                    }
                }
            }
            $candidates = $rootCandidates !== [] ? $rootCandidates : $wrappedCandidates;
            if (count($candidates) !== 1) {
                $this->invalid('The ZIP must contain exactly one template.html, template.txt, template.tpl, index.html, index.php, index.tpl.html or index.tpl.php at its root or in one wrapper folder.');
            }
            $entrypoint = $candidates[0];
            [$files, $prefix, $totalBytes] = $this->extractor->inspect($zip, $entrypoint);
            $disk = Storage::disk(config('fast-landings.storage_disk'));
            $actualBytes = 0;
            foreach ($files as $file) {
                $relative = substr($file['name'], strlen($prefix));
                $actualBytes += $this->extractor->extractFile($zip, $disk, $file['name'], "{$staging}/{$relative}", $file['size'], $actualBytes);
            }
            if ($actualBytes !== $totalBytes) {
                $this->invalid('The archive size metadata does not match its content.');
            }

            return $entrypoint;
        } finally {
            $zip->close();
        }
    }

    private function source(mixed $path, string $staging, array &$sources): string
    {
        if (! is_string($path) || ! $this->extractor->isSafePath($path) || str_contains($path, '\\')) {
            $this->invalid('Template file references must be safe paths within the package.');
        }
        $sources[] = $path;

        return $this->readText($staging.'/'.$path);
    }

    private function outputPath(string $source): string
    {
        if (in_array($source, ['template.html', 'template.txt', 'template.tpl'], true)) {
            return 'index.html';
        }

        return preg_replace('/\.tpl\.(html|php)$/D', '.$1', $source);
    }

    private function readText(string $path): string
    {
        $disk = Storage::disk(config('fast-landings.storage_disk'));
        if (! $disk->fileExists($path) || $disk->size($path) > config('fast-landings.templates.max_definition_bytes')) {
            $this->invalid('A template file is missing or exceeds the 2 MB limit.');
        }
        $text = $disk->get($path);
        if (! mb_check_encoding($text, 'UTF-8') || str_contains($text, "\0")) {
            $this->invalid('Template files must contain UTF-8 text.');
        }

        return $text;
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['templateUpload' => $message]);
    }
}
