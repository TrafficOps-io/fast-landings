<div x-show="macroOpen" x-cloak class="template-macro-menu" @keydown="macroKeydown($event)">
    <input x-ref="macroSearch" x-model="macroSearch" @input.stop="macroActive = -1" type="search" class="d-input d-input-bordered d-input-sm mb-2 w-full" placeholder="Search request values…" aria-label="Search runtime macros" role="combobox" aria-autocomplete="list" :aria-expanded="macroOpen" :aria-controls="$id('runtime-macros')" :aria-activedescendant="macroActive >= 0 ? $id('runtime-macro', macroActive) : null">
    <div x-ref="macroList" class="max-h-56 overflow-y-auto" role="listbox" :id="$id('runtime-macros')" aria-label="Runtime macros">
        <template x-for="(option, index) in filteredMacros" :key="option.token">
            <button type="button" role="option" :id="$id('runtime-macro', index)" :data-macro-index="index" :aria-selected="macroActive === index" :class="{ 'bg-base-200': macroActive === index }" class="block w-full rounded-md px-3 py-2 text-left hover:bg-base-200" @mousedown.prevent @mouseenter="macroActive = index" @click="selectMacro(option)">
                <span class="block font-mono text-xs text-primary" x-text="option.token"></span>
                <span class="mt-1 block text-xs text-base-content/60" x-text="option.label"></span>
            </button>
        </template>
        <p x-show="!filteredMacros.length" class="p-3 text-xs text-base-content/60">No matching values. You can enter a request path manually.</p>
    </div>
</div>
