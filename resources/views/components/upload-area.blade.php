@props([
    'id',
    'label',
    'accept',
    'help',
    'fileName' => '',
    'required' => false,
    'target' => null,
])

@php($property = $attributes->wire('model')->value())

<div {{ $attributes->except(['wire:model'])->class('grid min-w-0 gap-1.5') }}
    x-data="{
        dragDepth: 0,
        selectedName: @js($fileName),
        error: '',
        dropFile(event) {
            this.dragDepth = 0;
            if (this.$refs.file.disabled) return;
            const files = event.dataTransfer?.files;
            if (!files?.length) return;
            if (files.length !== 1) {
                this.error = 'Choose one file at a time.';
                return;
            }
            this.$refs.file.files = files;
            this.$refs.file.dispatchEvent(new Event('change', { bubbles: true }));
        },
    }"
    x-on:livewire-upload-error="error = 'Upload failed. Please choose the file again.'"
    x-on:livewire-upload-cancel="error = 'Upload cancelled. Please choose the file again.'">
    <label for="{{ $id }}" class="text-sm font-medium">{{ $label }}</label>
    <div class="fl-image-drop relative min-w-0" :class="{ 'is-dragging': dragDepth > 0 }"
        x-on:dragenter.prevent="if (!$refs.file.disabled && Array.from($event.dataTransfer?.types ?? []).includes('Files')) dragDepth++"
        x-on:dragover.prevent="if (!$refs.file.disabled) $event.dataTransfer.dropEffect = 'copy'"
        x-on:dragleave.prevent="dragDepth = Math.max(0, dragDepth - 1)"
        x-on:drop.prevent.stop="dropFile($event)">
        <input id="{{ $id }}" x-ref="file" {{ $attributes->whereStartsWith('wire:model') }} type="file" accept="{{ $accept }}" class="sr-only"
            @if ($required) required x-bind:required="!$wire.{{ $property }}" @endif
            aria-describedby="{{ $id }}-help {{ $id }}-error {{ $id }}-upload-error"
            @error($property) aria-invalid="true" @enderror
            wire:loading.attr="disabled" wire:target="{{ $target ?? $property }}"
            x-on:change="selectedName = $event.target.files?.[0]?.name ?? ''; error = ''">
        <label for="{{ $id }}" class="fl-image-empty min-w-0">
            <span class="fl-image-symbol" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M12 16V4m-4 4 4-4 4 4M4 15v4a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-4"/></svg></span>
            <strong class="max-w-full [overflow-wrap:anywhere]" x-text="selectedName || 'Drop a file here, or browse'">Drop a file here, or browse</strong>
            <span x-text="selectedName ? 'Drop another file or browse to replace it' : 'Choose one file'">Choose one file</span>
        </label>
    </div>
    <p id="{{ $id }}-help" class="text-xs text-base-content/55">{{ $help }}</p>
    <p id="{{ $id }}-upload-error" x-show="error" x-cloak x-text="error" class="text-sm text-error" role="alert"></p>
    @error($property)<p id="{{ $id }}-error" class="text-sm text-error" role="alert">{{ $message }}</p>@enderror
</div>
