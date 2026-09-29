<?php

namespace App\Services\Templates;

use App\Models\Landing;
use App\Models\LandingRelease;
use App\Models\LandingTemplate;
use App\Models\TemplateMedia;
use App\Models\User;
use App\Services\LandingArchiveService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;
use TrafficOps\TemplateDsl\TemplateEngine;
use TrafficOps\TemplateDsl\TemplateRichText;
use ZipArchive;

class TemplateLandingService
{
    public function __construct(
        private TemplateEngine $engine,
        private LandingArchiveService $archives,
        private TemplateRichText $richText,
        private TemplateImageUploadPolicy $imageUploads,
    ) {}

    public function create(LandingTemplate $template, array $attributes, array $values, array $uploads, User $user, ?array &$warnings = null): Landing
    {
        abort_unless($user->is_active, 403);
        $attributes['name'] = trim($attributes['name'] ?? '');
        $attributes['slug'] = Str::lower(trim($attributes['slug'] ?? ''));
        $attributes = Validator::make($attributes, [
            'name' => ['required', 'string', 'max:120'],
            'slug' => ['required', 'alpha_dash:ascii', 'max:120', Rule::unique('landings', 'slug')],
            'description' => ['nullable', 'string', 'max:2000'],
        ])->validate();

        $landing = new Landing([
            ...$attributes,
            'description' => ($attributes['description'] ?? '') ?: null,
            'is_active' => true,
        ]);
        $landing->id = (string) Str::ulid();

        return $this->publish($landing, $template, $values, $uploads, $user, warnings: $warnings);
    }

    public function update(Landing $landing, LandingTemplate $template, array $values, array $uploads, User $user, ?string $expectedReleaseId, ?array &$warnings = null): Landing
    {
        abort_unless($user->is_active && $landing->exists, 403);

        return $this->publish($landing, $template, $values, $uploads, $user, $expectedReleaseId, $warnings);
    }

    private function publish(Landing $landing, LandingTemplate $template, array $values, array $uploads, User $user, ?string $expectedReleaseId = null, ?array &$warnings = null): Landing
    {
        $disk = Storage::disk(config('fast-landings.storage_disk'));
        $release = null;
        $mediaFiles = [];
        $temporary = tmpfile();
        if ($temporary === false) {
            throw new RuntimeException('Unable to create the compiled archive.');
        }
        $archivePath = stream_get_meta_data($temporary)['uri'];

        try {
            // Keep deletion from removing source assets during compilation.
            return DB::transaction(function () use ($template, $values, $uploads, $user, $landing, $disk, $archivePath, $expectedReleaseId, &$release, &$mediaFiles, &$warnings): Landing {
                $template = LandingTemplate::query()->lockForUpdate()->findOrFail($template->id);
                $previous = null;
                if ($landing->exists) {
                    $landing = Landing::query()->lockForUpdate()->findOrFail($landing->id);
                    $previous = $landing->activeRelease;
                    if ($previous?->id !== $expectedReleaseId) {
                        throw ValidationException::withMessages(['template' => 'This landing has changed since you opened the editor. Reload the page before saving.']);
                    }
                }
                $definition = $template->definition;
                $images = [];
                foreach (Arr::dot($uploads) as $path => $upload) {
                    if ($upload === null || $upload === '' || $upload === []) {
                        continue;
                    }
                    $field = $this->engine->fieldAtPath($definition, $path);
                    if (($field['type'] ?? null) !== 'image' || ! $upload instanceof UploadedFile || ! Arr::has($values, $path)) {
                        throw ValidationException::withMessages(['uploads.'.$path => 'Choose an image field in this template.']);
                    }
                    $this->imageUploads->validate($upload, $field, 'uploads.'.$path);
                    $filename = '_uploads/'.Str::ulid().'.'.$upload->guessExtension();
                    $images[$filename] = $upload->getRealPath();
                    Arr::set($values, $path, $filename);
                }

                $values = $this->engine->validateValues($definition, $values, $warnings);
                $pages = $this->engine->renderPages($definition, $values);
                if ($previous?->landing_template_id === $template->id) {
                    $images += $this->retainedImages($definition, $values, $previous);
                }
                foreach ($this->imageReferences($definition, $values) as $path => $references) {
                    foreach ($references as $reference) {
                        if (! str_starts_with($reference, '_media/') || isset($images[$reference])) {
                            continue;
                        }
                        $media = TemplateMedia::query()->where('filename', substr($reference, 7))->first();
                        if ($media === null || ! Storage::disk($media->disk)->exists($media->path)) {
                            throw ValidationException::withMessages(['values.'.$path => 'This editor image is missing. Upload it again or choose another image.']);
                        }
                        // Filesystem streams work with local, S3 and custom Laravel disks.
                        $stream = Storage::disk($media->disk)->readStream($media->path);
                        $copy = tmpfile();
                        if (! is_resource($stream) || $copy === false) {
                            if (is_resource($stream)) {
                                fclose($stream);
                            }
                            if (is_resource($copy)) {
                                fclose($copy);
                            }
                            throw new RuntimeException('Unable to read the editor image.');
                        }
                        $mediaFiles[] = $copy;
                        try {
                            $limit = config('fast-landings.templates.max_image_kb') * 1024;
                            $bytes = stream_copy_to_stream($stream, $copy, $limit + 1);
                            if ($bytes === false || $bytes > $limit) {
                                throw ValidationException::withMessages(['values.'.$path => 'The editor image exceeds the allowed size.']);
                            }
                        } finally {
                            fclose($stream);
                        }
                        $images[$reference] = stream_get_meta_data($copy)['uri'];
                    }
                }
                $this->assertImageAssetsExist($definition, $values, array_merge($template->asset_paths, array_keys($images)));
                $zip = new ZipArchive;
                if ($zip->open($archivePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                    throw new RuntimeException('Unable to open the compiled archive.');
                }
                try {
                    foreach ($pages as $path => $html) {
                        if (! $zip->addFromString($path, $html)) {
                            throw new RuntimeException('Unable to write a landing page.');
                        }
                        $zip->setCompressionName($path, ZipArchive::CM_STORE);
                    }
                    foreach ($template->asset_paths as $asset) {
                        $source = $template->storage_path.'/'.$asset;
                        if (! $disk->fileExists($source)) {
                            throw ValidationException::withMessages(['template' => 'A template asset is missing. Import the template again.']);
                        }
                        $this->addFile($zip, $disk->path($source), $asset);
                    }
                    foreach ($images as $name => $source) {
                        $this->addFile($zip, $source, $name);
                    }
                } catch (Throwable $exception) {
                    $zip->close();
                    throw $exception;
                }
                if (! $zip->close()) {
                    throw new RuntimeException('Unable to finish the compiled archive.');
                }

                if (! $landing->exists) {
                    $landing->save();
                }
                $release = $this->archives->deploy(
                    $landing,
                    new UploadedFile($archivePath, $landing->slug.'.zip', 'application/zip', null, true),
                    $user,
                    $template,
                    $values,
                );

                return $landing->refresh();
            });
        } catch (Throwable $exception) {
            // Never remove existing releases if compilation or publication fails.
            if ($release !== null) {
                $disk->deleteDirectory($release->storage_path);
            }
            if ($exception instanceof ValidationException) {
                // A compiler error belongs to the form, which has no archive upload field.
                $errors = $exception->errors();
                if (isset($errors['archive'])) {
                    $errors['template'] = $errors['archive'];
                    unset($errors['archive']);
                }
                throw ValidationException::withMessages($errors);
            }
            throw $exception;
        } finally {
            foreach ($mediaFiles as $file) {
                fclose($file);
            }
            fclose($temporary);
        }
    }

    private function addFile(ZipArchive $zip, string $source, string $name): void
    {
        if (! $zip->addFile($source, $name) || ! $zip->setCompressionName($name, ZipArchive::CM_STORE)) {
            throw new RuntimeException('Unable to copy a landing asset.');
        }
    }

    /** Copy only referenced uploads belonging to the release being edited. */
    private function retainedImages(array $definition, array $values, LandingRelease $previous): array
    {
        $disk = Storage::disk(config('fast-landings.storage_disk'));
        $existing = array_merge([], ...array_values($this->imageReferences($definition, $previous->template_values ?? [])));
        $images = [];
        foreach ($this->imageReferences($definition, $values) as $path => $references) {
            foreach ($references as $value) {
                if (! preg_match('~^_(?:uploads|media)/[a-zA-Z0-9]+\.(?:jpe?g|png|gif|webp|avif)$~', $value)
                    || ! in_array($value, $existing, true)) {
                    continue;
                }
                $source = $previous->storage_path.'/'.$value;
                if (! $disk->fileExists($source)) {
                    throw ValidationException::withMessages(['values.'.$path => 'The saved image is missing. Upload it again or choose another image.']);
                }
                $images[$value] = $disk->path($source);
            }
        }

        return $images;
    }

    private function assertImageAssetsExist(array $definition, array $values, array $assets): void
    {
        foreach ($this->imageReferences($definition, $values) as $path => $references) {
            foreach ($references as $value) {
                if ($value === '' || preg_match('~^https?://~i', $value)) {
                    continue;
                }
                if (! in_array($value, $assets, true)) {
                    throw ValidationException::withMessages(['values.'.$path => 'This image is not included in the template. Enter an HTTPS URL or upload an image.']);
                }
            }
        }
    }

    private function imageReferences(array $definition, array $values): array
    {
        $references = [];
        foreach (Arr::dot($values) as $path => $value) {
            $type = $this->engine->fieldAtPath($definition, $path)['type'] ?? null;
            if (! is_string($value) || $value === '') {
                continue;
            }
            if ($type === 'image') {
                $references[$path] = [$value];
            } elseif (in_array($type, TemplateRichText::TYPES, true)) {
                $references[$path] = $this->richText->imageSources($this->richText->render($type, $value));
            }
        }

        return $references;
    }
}
