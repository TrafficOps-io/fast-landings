<div class="relative" x-data="templateMacroInput(@js($this->runtimeMacroSuggestions))" x-id="['runtime-macros', 'runtime-macro']" @click.outside="macroOpen = false" @focusout="if (!$el.contains($event.relatedTarget)) macroOpen = false">
    @if ($fieldType === 'textarea')
        <textarea x-ref="macroInput" id="{{ $fieldId }}" wire:model="{{ $valuePath }}" @input="captureMacros()" @click="captureMacros()" @select="captureMacros()" @keyup="if (['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes($event.key)) captureMacros()" @keydown="macroKeydown($event)" class="d-textarea d-textarea-bordered min-h-28 w-full" @required($field['required'] ?? false) @if (isset($field['max'])) maxlength="{{ $field['max'] }}" @endif aria-describedby="{{ $fieldId }}-help {{ $fieldId }}-error {{ $fieldId }}-macros" aria-invalid="{{ $errors->has($valuePath) ? 'true' : 'false' }}" aria-autocomplete="list" :aria-expanded="macroOpen" :aria-controls="$id('runtime-macros')" :aria-activedescendant="macroActive >= 0 ? $id('runtime-macro', macroActive) : null"></textarea>
    @else
        <input x-ref="macroInput" id="{{ $fieldId }}" type="text" wire:model="{{ $valuePath }}" @input="captureMacros()" @click="captureMacros()" @select="captureMacros()" @keyup="if (['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes($event.key)) captureMacros()" @keydown="macroKeydown($event)" class="d-input d-input-bordered w-full" @required($field['required'] ?? false) @if (isset($field['max'])) maxlength="{{ $field['max'] }}" @endif aria-describedby="{{ $fieldId }}-help {{ $fieldId }}-error {{ $fieldId }}-macros" aria-invalid="{{ $errors->has($valuePath) ? 'true' : 'false' }}" role="combobox" aria-autocomplete="list" :aria-expanded="macroOpen" :aria-controls="$id('runtime-macros')" :aria-activedescendant="macroActive >= 0 ? $id('runtime-macro', macroActive) : null">
    @endif
    <div class="mt-1 flex items-center justify-between gap-2">
        <p id="{{ $fieldId }}-macros" class="text-xs text-base-content/50">Type { for request values.</p>
        <button type="button" class="d-btn d-btn-ghost d-btn-xs font-normal" @click="browseMacros()" :aria-expanded="macroOpen" :aria-controls="$id('runtime-macros')">Insert variable</button>
    </div>
    @include('templates.macro-menu')
</div>
