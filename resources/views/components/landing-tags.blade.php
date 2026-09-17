@props(['suggestions' => [], 'id' => 'landing-tags'])

<div wire:key="{{ $id }}-field">
<div wire:ignore class="relative grid gap-1.5" x-data="landingTags($wire.entangle('tags'), @js($suggestions))"
    @click.outside="commit(); open = false" @focusout="if (!$el.contains($event.relatedTarget)) { commit(); open = false; }">
    <label for="{{ $id }}" class="text-sm font-medium">Tags <span class="text-base-content/50">optional</span></label>
    <div class="flex min-h-11 flex-wrap items-center gap-1.5 rounded-field border border-base-content/20 bg-base-100 px-3 py-2 focus-within:outline-2 focus-within:outline-primary" @click="$refs.input.focus()">
        <template x-for="item in selected" :key="item.name">
            <span class="inline-flex max-w-full items-center gap-1 rounded-md bg-primary/10 px-2 py-1 text-xs text-primary">
                <span class="break-all" x-text="item.name"></span>
                <button type="button" class="shrink-0 px-1 font-semibold" @click.stop="remove(item)" :aria-label="'Remove tag ' + item.name">×</button>
            </span>
        </template>
        <input id="{{ $id }}" x-ref="input" x-model="query" @input="if (!$event.isComposing) input()" @compositionend="input()"
            @focus="open = true" @keydown="keydown($event)" autocomplete="off" maxlength="60"
            class="min-w-24 flex-1 bg-transparent py-1 text-sm outline-none" placeholder="Add or choose a tag…"
            role="combobox" aria-autocomplete="list" aria-controls="{{ $id }}-options" :aria-expanded="open && choices.length > 0"
            :aria-activedescendant="open && active >= 0 ? @js($id.'-option-') + active : null" aria-describedby="{{ $id }}-help">
    </div>
    <div x-cloak x-show="open && choices.length > 0" id="{{ $id }}-options" x-ref="list" role="listbox" aria-label="Tag suggestions"
        class="absolute inset-x-0 top-full z-30 mt-1 max-h-52 overflow-auto rounded-box border border-base-300 bg-base-100 p-1 shadow-xl">
        <template x-for="(option, index) in choices" :key="option.name">
            <button type="button" role="option" :id="@js($id.'-option-') + index" :data-option-index="index" :aria-selected="false"
                class="flex w-full items-center justify-between gap-2 rounded-md px-3 py-2 text-left text-sm hover:bg-base-200"
                :class="{ 'bg-base-200': active === index }" @mousedown.prevent @mouseenter="active = index" @click="add(option); $refs.input.focus()">
                <span class="break-all" x-text="option.name"></span>
                <span class="shrink-0 text-xs text-base-content/50" x-text="option.create ? 'Create tag +' : ''"></span>
            </button>
        </template>
    </div>
    <p id="{{ $id }}-help" class="text-xs text-base-content/50">Press Enter or comma to add. Up to 20 tags.</p>
</div>
    @error('tags')<p class="text-sm text-error" role="alert">{{ $message }}</p>@enderror
    @error('tags.*')<p class="text-sm text-error" role="alert">{{ $message }}</p>@enderror
</div>
