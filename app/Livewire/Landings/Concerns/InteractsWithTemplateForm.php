<?php

namespace App\Livewire\Landings\Concerns;

use App\Models\User;
use App\Services\Templates\TemplateImageUploadPolicy;
use App\Services\Templates\TemplateMacroSuggestions;
use App\Services\Templates\TemplateMediaService;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Renderless;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use TrafficOps\TemplateDsl\TemplateEngine;

trait InteractsWithTemplateForm
{
    public array $values = [];

    public array $uploads = [];

    public array $editorUploads = [];

    // Re-indexed repeaters must receive fresh DOM keys, including their file inputs.
    #[Locked]
    public int $formVersion = 0;

    #[Computed]
    public function runtimeMacroSuggestions(): array
    {
        return app(TemplateMacroSuggestions::class)->forDefinition($this->template?->definition ?? []);
    }

    #[Renderless]
    public function previewMarkdown(string $path, TemplateEngine $engine): string
    {
        $this->activeUser();
        $field = $this->field($path, 'markdown');

        return $engine->previewRichText($field, data_get($this->values, $path), 'values.'.$path);
    }

    #[Renderless]
    public function uploadEditorImage(string $path, string $key, TemplateMediaService $media): array
    {
        $user = $this->activeUser();
        $type = app(TemplateEngine::class)->fieldAtPath($this->template?->definition ?? ['sections' => []], $path)['type'] ?? '';
        abort_unless(in_array($type, ['wysiwyg', 'markdown'], true), 422);
        $this->field($path, $type);
        abort_unless(preg_match('/^[a-zA-Z0-9_]{1,80}$/D', $key), 422);
        $file = $this->editorUploads[$key] ?? null;
        abort_unless($file instanceof TemporaryUploadedFile, 422);
        $image = $media->upload($file, $user);
        unset($this->editorUploads[$key]);
        $file->delete();

        return ['src' => '_media/'.$image->filename];
    }

    public function addItem(string $path, TemplateEngine $engine): void
    {
        $this->activeUser();
        $field = $this->field($path, 'repeater');
        $items = data_get($this->values, $path, []);
        abort_unless(is_array($items) && array_is_list($items), 422);

        if (count($items) >= ($field['max_items'] ?? 50)) {
            throw ValidationException::withMessages(['values.'.$path => 'This block has reached its maximum number of items.']);
        }

        $items[] = $engine->defaultsForFields($field['fields']);
        data_set($this->values, $path, $items);
        $this->clearFieldErrors($path);
    }

    public function removeItem(string $path, int $index): void
    {
        $this->activeUser();
        $field = $this->field($path, 'repeater');
        $items = data_get($this->values, $path, []);
        abort_unless(is_array($items) && array_is_list($items) && array_key_exists($index, $items), 422);

        if (count($items) <= ($field['min_items'] ?? 0)) {
            throw ValidationException::withMessages(['values.'.$path => 'This block requires at least '.($field['min_items'] ?? 0).' item(s).']);
        }

        // Upload arrays are sparse: retain each surviving file at its new row index.
        $uploads = data_get($this->uploads, $path, []);
        $remainingUploads = [];
        foreach (is_array($uploads) ? $uploads : [] as $row => $files) {
            if ((int) $row !== $index) {
                $remainingUploads[(int) $row > $index ? (int) $row - 1 : (int) $row] = $files;
            }
        }

        array_splice($items, $index, 1);
        data_set($this->values, $path, $items);
        data_set($this->uploads, $path, $remainingUploads);
        $this->formVersion++;
        $this->clearFieldErrors($path);
    }

    public function imageUploaded(string $path, TemplateImageUploadPolicy $uploads): bool
    {
        $this->activeUser();
        $field = $this->field($path, 'image');
        $file = data_get($this->uploads, $path);
        abort_unless($file instanceof TemporaryUploadedFile, 422);
        $uploads->validate($file, $field, 'uploads.'.$path);
        $this->clearFieldErrors($path);

        return true;
    }

    public function clearImage(string $path): void
    {
        $this->activeUser();
        $this->field($path, 'image');
        data_set($this->values, $path, '');
        $this->removeUpload($path);
    }

    public function removeUpload(string $path): void
    {
        $this->activeUser();
        $this->field($path, 'image');
        Arr::forget($this->uploads, $path);
        $this->formVersion++;
        $this->clearFieldErrors($path);
    }

    /** Resolve only schema fields and repeater rows that currently exist. */
    private function field(string $path, string $type): array
    {
        abort_unless($this->template, 422);
        $parts = explode('.', $path);
        $fields = array_merge(...array_column($this->template->definition['sections'], 'fields'));
        $value = $this->values;

        while ($parts !== []) {
            $name = array_shift($parts);
            $field = collect($fields)->firstWhere('name', $name);
            abort_unless(is_array($field), 422);
            $value = is_array($value) ? ($value[$name] ?? null) : null;

            if ($parts === []) {
                abort_unless($field['type'] === $type, 422);

                return $field;
            }

            abort_unless(in_array($field['type'], ['group', 'repeater'], true), 422);
            if ($field['type'] === 'repeater') {
                $row = array_shift($parts);
                abort_unless(ctype_digit($row) && (string) (int) $row === $row && is_array($value) && array_key_exists((int) $row, $value) && $parts !== [], 422);
                $value = $value[(int) $row];
            }
            $fields = $field['fields'];
        }

        abort(422);
    }

    private function clearFieldErrors(string $path): void
    {
        foreach ($this->getErrorBag()->keys() as $key) {
            if ($key === 'values.'.$path || str_starts_with($key, 'values.'.$path.'.') ||
                $key === 'uploads.'.$path || str_starts_with($key, 'uploads.'.$path.'.')) {
                $this->resetValidation($key);
            }
        }
    }

    /** Present removed field names without exposing validation paths or engine details. */
    private function savedTemplateMessage(string $message, array $warnings): string
    {
        if ($warnings === []) {
            return $message;
        }
        $fields = collect(array_keys($warnings))
            ->map(fn (string $path): string => Str::headline(Str::afterLast($path, '.')))
            ->unique()->implode(', ');

        return $message.' Values for fields no longer in this template were removed: '.$fields.'.';
    }

    private function activeUser(): User
    {
        $user = User::query()->whereKey(auth()->id())->where('is_active', true)->first();
        abort_unless($user, 403);

        return $user;
    }
}
