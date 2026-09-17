<dialog x-ref="dialog" wire:ignore class="fl-image-dialog" aria-labelledby="{{ $fieldId }}-crop-title" x-on:cancel.prevent="close()" x-on:click="if ($event.target === $refs.dialog) close()">
    <header class="fl-image-dialog-heading">
        <div><p class="ui-eyebrow">{{ $fieldLabel }}</p><h2 id="{{ $fieldId }}-crop-title">Crop & resize</h2></div>
        <button type="button" class="d-btn d-btn-ghost d-btn-sm" aria-label="Close image editor" x-on:click="close()" :disabled="saving">✕</button>
    </header>
    <p x-show="loading" role="status" class="p-6 text-sm">Opening image…</p>
    <template x-if="current && current.image">
        <div class="fl-image-editor-body">
            <div class="min-w-0">
                <div class="fl-crop-stage">
                    <div class="fl-crop-viewport" tabindex="0" role="group" aria-label="Crop preview. Drag or use arrow keys to move the image."
                         :style="`aspect-ratio:${target.width}/${target.height};width:min(100%, calc(320px * ${target.width / target.height}));background-color:${current.transparent ? 'transparent' : current.background}`"
                         x-on:pointerdown="startDrag($event)" x-on:pointermove="moveDrag($event)" x-on:pointerup="stopDrag()" x-on:pointercancel="stopDrag()" x-on:lostpointercapture="stopDrag()"
                         x-on:wheel.prevent="zoomBy($event.deltaY < 0 ? 0.05 : -0.05)"
                         x-on:keydown.left.prevent="nudge(-0.02, 0)" x-on:keydown.right.prevent="nudge(0.02, 0)" x-on:keydown.up.prevent="nudge(0, -0.02)" x-on:keydown.down.prevent="nudge(0, 0.02)">
                        <img :src="current.url" :style="imageStyle" alt="" draggable="false">
                        <div class="fl-crop-grid" aria-hidden="true"></div>
                    </div>
                </div>
                <p class="mt-3 text-center text-xs text-base-content/60">Drag to reposition · scroll to zoom · arrow keys to adjust</p>
                <p class="mt-3 truncate text-xs text-base-content/60"><span x-text="current.name"></span> · <span x-text="`${current.width} × ${current.height} px`"></span></p>
            </div>
            <fieldset class="fl-crop-controls" :disabled="saving || loading">
                <div class="fl-crop-output"><span>Output size</span><strong x-text="outputLabel"></strong><small>PNG</small></div>
                @if (count($field['sizes'] ?? []) > 1)
                    <label>Size
                        <select class="d-select d-select-sm mt-2 w-full" :value="current.preset" x-on:change="setPreset($event.target.value)">
                            @foreach ($field['sizes'] as $size)<option value="{{ $loop->index }}">{{ $size['label'] ? $size['label'].' · ' : '' }}{{ $size['width'] }} × {{ $size['height'] }} px</option>@endforeach
                        </select>
                    </label>
                @endif
                @if (empty($field['sizes']))
                    <label>Width (px)<input type="number" min="1" max="4096" step="1" :value="target.width" x-on:change="setOutputWidth($event.target.value)" class="d-input d-input-sm mt-2 w-full"><span class="mt-1 block text-xs text-base-content/50">Height follows the aspect ratio.</span></label>
                @endif
                <label><span class="flex justify-between">Zoom <output x-text="Math.round(current.zoom * 100) + '%'"></output></span><input type="range" min="0.1" max="4" step="0.01" x-model.number="current.zoom" class="fl-crop-range"></label>
                <div class="flex flex-wrap gap-2"><button type="button" class="d-btn d-btn-sm" x-on:click="fit()">Fit</button><button type="button" class="d-btn d-btn-sm" x-on:click="reset()">Fill</button></div>
                <label>Horizontal position<input type="range" min="-1" max="1" step="0.01" x-model.number="current.x" class="fl-crop-range"></label>
                <label>Vertical position<input type="range" min="-1" max="1" step="0.01" x-model.number="current.y" class="fl-crop-range"></label>
                <label class="flex items-center gap-2"><input type="checkbox" x-model="current.transparent" class="d-checkbox d-checkbox-sm">Transparent background</label>
                <label x-show="!current.transparent">Background<input type="color" x-model="current.background" class="mt-2 h-9 w-full cursor-pointer rounded border border-base-300"></label>
                <p x-show="lowResolution" class="fl-crop-note" role="status">The source is smaller than this crop. The result may look soft.</p>
                <p class="text-xs leading-5 text-base-content/50">Cropping exports a static PNG. Animated images use their first frame.</p>
            </fieldset>
        </div>
    </template>
    <div class="px-6 pb-3">
        <p x-show="error" x-text="error" class="text-sm text-error" role="alert"></p>
        <div x-show="saving" role="status"><progress class="d-progress d-progress-primary w-full" max="100" :value="progress"></progress><span class="text-xs" x-text="'Uploading… ' + progress + '%'"></span></div>
    </div>
    <footer class="fl-image-dialog-footer">
        @if (empty($field['sizes']) && !isset($field['aspect_ratio']))
            <button type="button" class="d-btn d-btn-ghost d-btn-sm mr-auto" x-show="current?.originalFile" x-on:click="useOriginal()" :disabled="!ready">Use original</button>
        @endif
        <button type="button" class="d-btn d-btn-ghost d-btn-sm" x-on:click="close()" :disabled="saving">Cancel</button>
        <button type="button" class="d-btn d-btn-primary d-btn-sm" x-on:click="save()" :disabled="!ready">Apply & upload</button>
    </footer>
</dialog>
