@php
    $uploadedImage = data_get($uploads, $fieldPath);
    $preview = '';
    if ($uploadedImage instanceof \Livewire\Features\SupportFileUploads\TemporaryUploadedFile) {
        try { $preview = $uploadedImage->temporaryUrl(); } catch (\Throwable) { /* Unsupported temporary preview format. */ }
    } elseif (is_string($fieldValue) && $fieldValue !== '') {
        if (preg_match('~^https?://~i', $fieldValue)) {
            $preview = $fieldValue;
        } elseif (str_starts_with($fieldValue, '_media/')) {
            $preview = route('templates.media', ['filename' => substr($fieldValue, 7)]);
        } elseif (isset($baseReleaseId) && str_starts_with($fieldValue, '_uploads/')) {
            $preview = route('landings.release-image', ['release' => $baseReleaseId, 'path' => $fieldValue]);
        } elseif (in_array($fieldValue, $template->asset_paths, true)) {
            $preview = route('templates.image', ['template' => $template, 'path' => $fieldValue]);
        }
    }
    $imageConfig = [
        'path' => $fieldPath, 'valuePath' => $valuePath, 'uploadPath' => $uploadPath,
        'value' => $fieldValue, 'preview' => $preview, 'hasUpload' => (bool) $uploadedImage,
        'sizes' => $field['sizes'] ?? [], 'aspectRatio' => $field['aspect_ratio'] ?? null,
        'maxBytes' => config('fast-landings.templates.max_image_kb', 10240) * 1024,
        'mimeTypes' => ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif'],
    ];
@endphp
<div class="fl-image-field" x-data="templateImageEditor(@js($imageConfig))" data-remaining="1">
    <div class="fl-image-requirements">
        @if (!empty($field['sizes']))
            @foreach ($field['sizes'] as $size)<span>{{ $size['label'] ?: 'Output' }} <strong>{{ $size['width'] }} × {{ $size['height'] }} px</strong></span>@endforeach
        @elseif (isset($field['aspect_ratio']))
            <span>Aspect ratio <strong>{{ \TrafficOps\TemplateDsl\TemplateImageOptions::ratioLabel($field['aspect_ratio']) }}</strong></span>
        @else
            <span>Original proportions · optional crop and resize</span>
        @endif
    </div>
    <div class="fl-image-drop" :class="{ 'is-dragging': dragging }"
         x-on:dragenter.prevent="dragEnter($event)" x-on:dragover.prevent="dragOver($event)" x-on:dragleave.prevent="dragLeave()" x-on:drop.prevent="dropFiles($event)">
        <input id="{{ $fieldId }}" x-ref="file" type="file" accept="image/jpeg,image/png,image/gif,image/webp,image/avif" class="sr-only" x-on:change="selectFiles($event)" :disabled="saving || loading" aria-describedby="{{ $fieldId }}-formats {{ $fieldId }}-upload-error">
        <template x-if="previewUrl && !previewFailed">
            <div class="fl-image-preview">
                <img :src="previewUrl" alt="{{ $fieldLabel }} preview" x-on:error="previewFailed = true">
                <div class="min-w-0">
                    <p class="truncate text-sm font-medium" x-text="selectedName || @js($uploadedImage?->getClientOriginalName()) || 'Current image'"></p>
                    <p class="mt-1 text-xs text-base-content/55">Drop a new image here to replace it</p>
                    <div class="mt-3 flex flex-wrap gap-2">
                        <button type="button" class="d-btn d-btn-outline d-btn-sm" x-on:click="editCurrent()" :disabled="saving || loading">Crop & resize</button>
                        <button type="button" class="d-btn d-btn-ghost d-btn-sm" x-on:click="$refs.file.click()" :disabled="saving || loading">Replace</button>
                        <button type="button" class="d-btn d-btn-ghost d-btn-sm text-error" x-on:click="clearImage()" :disabled="saving || loading" wire:loading.attr="disabled">Remove</button>
                    </div>
                </div>
            </div>
        </template>
        <button type="button" class="fl-image-empty" x-show="!previewUrl || previewFailed" x-on:click="$refs.file.click()" :disabled="saving || loading">
            <span class="fl-image-symbol" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M12 16V4m-4 4 4-4 4 4M4 15v4a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-4"/></svg></span>
            <strong>Drop an image here, or browse</strong>
            <span>Preview, crop and resize before uploading</span>
        </button>
    </div>
    <p id="{{ $fieldId }}-formats" class="fl-image-formats">JPEG, PNG, GIF, WebP or AVIF · up to {{ $imageConfig['maxBytes'] / 1024 / 1024 }} MB</p>
    <details class="fl-image-url" @if ($fieldValue && !$preview) open @endif>
        <summary>Use an image URL or template asset</summary>
        <label for="{{ $fieldId }}-url" class="sr-only">{{ $fieldLabel }} URL or asset path</label>
        <input id="{{ $fieldId }}-url" type="text" x-model="url" placeholder="https://example.com/image.jpg or assets/image.jpg" class="d-input d-input-bordered mt-3 w-full" aria-describedby="{{ $fieldId }}-error">
        <p class="mt-2 text-xs text-base-content/50">An uploaded image takes precedence over this value.</p>
    </details>
    @if ($uploadedImage)
        <button type="button" wire:click="removeUpload(@js($fieldPath))" wire:loading.attr="disabled" :disabled="saving || loading" class="d-btn d-btn-ghost d-btn-xs mt-2">Discard upload and restore original</button>
    @endif
    <p x-show="error" x-cloak x-text="error" class="mt-2 text-sm text-error" role="alert"></p>
    @error($uploadPath)<p id="{{ $fieldId }}-upload-error" class="mt-2 text-sm text-error" role="alert">{{ $message }}</p>@enderror
    @include('templates.image-crop-dialog')
</div>
