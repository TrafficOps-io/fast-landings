<div wire:ignore x-data="templateEditor(@js([
    'type' => $fieldType, 'path' => $fieldPath, 'id' => $fieldId,
    'required' => $field['required'] ?? false,
    'macros' => $this->runtimeMacroSuggestions,
    'mediaUrl' => route('templates.media', ['filename' => '__FILE__', 'release' => $baseReleaseId ?? null]),
]))" x-id="['runtime-macros', 'runtime-macro']" @click.outside="macroOpen = false" @focusout="if (!$el.contains($event.relatedTarget)) macroOpen = false" class="template-editor relative rounded-lg border border-base-300 bg-base-100">
    <div class="flex flex-wrap items-center gap-1 border-b border-base-300 bg-base-200/40 p-2" role="group" aria-label="{{ $fieldLabel }} formatting">
        @foreach (['bold' => 'Bold', 'italic' => 'Italic', 'heading' => 'Heading', 'bulletList' => 'Bullet list', 'orderedList' => 'Numbered list', 'blockquote' => 'Quote', 'codeBlock' => 'Code'] as $command => $label)
            <button type="button" class="d-btn d-btn-ghost d-btn-xs" @mousedown.prevent @click="format(@js($command))" :aria-pressed="active(@js($command))" :disabled="preview" title="{{ $label }}">{{ $label }}</button>
        @endforeach
        <button type="button" class="d-btn d-btn-ghost d-btn-xs" @click="openInsert('link')" :disabled="preview">Link</button>
        <button type="button" class="d-btn d-btn-ghost d-btn-xs" @click="openInsert('image')" :disabled="preview">Image</button>
        <button type="button" class="d-btn d-btn-ghost d-btn-xs" @mousedown.prevent @click="browseMacros()" :disabled="preview" :aria-expanded="macroOpen" :aria-controls="$id('runtime-macros')">Insert variable</button>
        @if ($fieldType === 'wysiwyg')
            <button type="button" class="d-btn d-btn-ghost d-btn-xs" @mousedown.prevent @click="format('undo')">Undo</button>
            <button type="button" class="d-btn d-btn-ghost d-btn-xs" @mousedown.prevent @click="format('redo')">Redo</button>
        @else
            <button type="button" class="d-btn d-btn-outline d-btn-xs ml-auto" @click="togglePreview()" :aria-pressed="preview" :disabled="loadingPreview" x-text="preview ? 'Edit Markdown' : 'Preview'"></button>
        @endif
    </div>
    <div x-show="insert !== ''" x-cloak class="grid gap-3 border-b border-base-300 p-3" @keydown.escape.stop="insert = ''">
        <label class="grid gap-1 text-xs"><span x-text="insert === 'image' ? 'Image URL' : 'Link URL'"></span><input x-ref="url" x-model="url" type="url" placeholder="https://example.com/…" class="d-input d-input-bordered d-input-sm w-full" @keydown.enter.prevent="insertUrl()"></label>
        <label x-show="insert === 'image'" class="grid gap-1 text-xs">Image description<input x-model="alt" type="text" class="d-input d-input-bordered d-input-sm w-full" @keydown.enter.prevent="insertUrl()"></label>
        <div class="flex flex-wrap items-center gap-2">
            <button type="button" @click="insertUrl()" class="d-btn d-btn-primary d-btn-sm">Insert</button>
            <button type="button" @click="insert = ''" class="d-btn d-btn-ghost d-btn-sm">Cancel</button>
            <label x-show="insert === 'image'" class="grid gap-1 text-xs">Or upload an image
                <input type="file" accept="image/jpeg,image/png,image/gif,image/webp,image/avif" @change="uploadImage($event)" :disabled="uploading" class="d-file-input d-file-input-bordered d-file-input-sm w-full">
            </label>
        </div>
        <p x-show="insert === 'image'" class="text-xs text-base-content/55">JPEG, PNG, GIF, WebP or AVIF, up to {{ (int) (config('fast-landings.templates.max_image_kb') / 1024) }} MB.</p>
    </div>
    <p x-show="error" x-cloak x-text="error" class="px-3 pt-3 text-sm text-error" role="alert"></p>
    <p x-show="uploading" x-cloak class="px-3 pt-3 text-sm text-base-content/60" role="status">Uploading image…</p>
    @if ($fieldType === 'wysiwyg')
        <div x-ref="editor" class="template-rich-content"></div>
    @else
        <textarea x-ref="source" id="{{ $fieldId }}" x-show="!preview" x-model="value" @input="sync(); captureMacros()" @select="captureMacros()" @click="captureMacros()" @keyup="rememberSelection(); if (['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes($event.key)) captureMacros()" @keydown="macroKeydown($event)" class="min-h-64 w-full resize-y bg-transparent p-4 font-mono text-sm outline-none" aria-labelledby="{{ $fieldId }}-label" aria-describedby="{{ $fieldId }}-help {{ $fieldId }}-error" aria-autocomplete="list" :aria-controls="$id('runtime-macros')" :aria-expanded="macroOpen" :aria-activedescendant="macroOpen && macroActive >= 0 ? $id('runtime-macro', macroActive) : null" @required($field['required'] ?? false) maxlength="100000" spellcheck="false"></textarea>
        <div x-ref="preview" x-show="preview" x-cloak class="template-rich-content min-h-64 p-4" aria-label="{{ $fieldLabel }} preview" @click="if ($event.target.closest('a')) $event.preventDefault()"></div>
        <p x-show="loadingPreview" x-cloak class="px-4 pb-3 text-xs" role="status">Loading preview…</p>
    @endif
    <p class="border-t border-base-300 px-3 py-2 text-xs text-base-content/50">{{ $fieldType === 'markdown' ? 'Markdown · Formatting, links and images' : 'Rich text · Formatting, links and images' }}</p>
    @include('templates.macro-menu')
</div>
