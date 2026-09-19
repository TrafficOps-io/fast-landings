<div class="grid gap-6" x-data="{ section: 0, uploadsInProgress: 0 }"
    x-on:template-image-upload-start="uploadsInProgress++"
    x-on:template-image-upload-finish="uploadsInProgress = Math.max(0, uploadsInProgress - 1)"
    x-on:livewire-upload-start="uploadsInProgress++"
    x-on:livewire-upload-finish="uploadsInProgress = Math.max(0, uploadsInProgress - 1)"
    x-on:livewire-upload-error="uploadsInProgress = Math.max(0, uploadsInProgress - 1)"
    x-on:livewire-upload-cancel="uploadsInProgress = Math.max(0, uploadsInProgress - 1)">
    <div>
        <a href="{{ route('templates.index') }}" class="ui-back-link" wire:navigate>← Templates</a>
        <div class="mt-4">
            <x-ui::page-header title="Create landing" :eyebrow="$template->name">
                <x-slot:actions>
                    <button type="submit" form="template-landing-form" class="d-btn d-btn-primary d-btn-sm" :disabled="uploadsInProgress > 0" wire:loading.attr="disabled" wire:target="create,uploads">
                        <span class="d-loading d-loading-spinner d-loading-sm" wire:loading wire:target="create"></span>
                        Create landing
                    </button>
                </x-slot:actions>
            </x-ui::page-header>
        </div>
        @if ($template->description)<p class="mt-2 max-w-3xl whitespace-pre-line text-sm text-base-content/65">{{ $template->description }}</p>@endif
    </div>

    <x-ui::feedback />
    <p x-show="uploadsInProgress > 0" x-cloak class="text-sm text-base-content/65" role="status">Uploading images… Please wait before creating your landing.</p>

    @include('livewire.landings.partials.ai-generation')

    <form id="template-landing-form" wire:submit="create" novalidate class="fl-studio-grid">
        <nav aria-label="Landing settings sections" class="fl-studio-nav">
            <button type="button" class="flex shrink-0 items-center gap-3 rounded-lg px-3 py-3 text-left text-sm" @click="section = 0" :class="section === 0 ? 'bg-primary/10 text-primary font-semibold' : 'hover:bg-base-200 text-base-content/65'" :aria-current="section === 0 ? 'step' : null">
                <span class="font-mono text-xs opacity-60">01</span><span>Landing details</span>
                @if ($errors->hasAny(['name', 'slug', 'description']))<span class="text-error" aria-label="Contains errors">•</span>@endif
            </button>
            @foreach ($sections as $section)
                @php($sectionNumber = $loop->iteration)
                <button type="button" class="flex shrink-0 items-center gap-3 rounded-lg px-3 py-3 text-left text-sm" @click="section = {{ $sectionNumber }}" :class="section === {{ $sectionNumber }} ? 'bg-primary/10 text-primary font-semibold' : 'hover:bg-base-200 text-base-content/65'" :aria-current="section === {{ $sectionNumber }} ? 'step' : null">
                    <span class="font-mono text-xs opacity-60">{{ str_pad($sectionNumber + 1, 2, '0', STR_PAD_LEFT) }}</span><span>{{ $section['label'] }}</span>
                    @if (collect($section['fields'])->contains(fn ($field) => $errors->has('values.'.$field['name'].'*') || $errors->has('uploads.'.$field['name'].'*')))<span class="text-error" aria-label="Contains errors">•</span>@endif
                </button>
            @endforeach
            <p class="mt-4 hidden border-t border-base-300 px-3 pt-4 text-xs leading-5 text-base-content/50 lg:block">Your settings will be compiled into a new HTML landing page.</p>
        </nav>

        <div class="min-w-0">
            <section x-show="section === 0" aria-label="Landing details">
                <x-ui::panel title="Landing details" description="Give the generated landing a name and a unique slug.">
                    <div class="grid gap-5 sm:grid-cols-2">
                        <label class="grid gap-1.5">
                            <span class="text-sm font-medium">Name <span class="text-error">*</span></span>
                            <input wire:model.live.debounce.300ms="name" class="d-input d-input-bordered w-full" required maxlength="120" autocomplete="off" @error('name') aria-invalid="true" @enderror>
                            @error('name')<span class="text-sm text-error" role="alert">{{ $message }}</span>@enderror
                        </label>
                        <label class="grid gap-1.5">
                            <span class="text-sm font-medium">Slug <span class="text-error">*</span></span>
                            <input wire:model="slug" class="d-input d-input-bordered w-full" required maxlength="120" placeholder="summer-offer" autocomplete="off" @error('slug') aria-invalid="true" @enderror>
                            @error('slug')<span class="text-sm text-error" role="alert">{{ $message }}</span>@enderror
                        </label>
                        <label class="grid gap-1.5 sm:col-span-2">
                            <span class="text-sm font-medium">Description <span class="text-base-content/50">optional</span></span>
                            <textarea wire:model="description" class="d-textarea d-textarea-bordered min-h-24 w-full" maxlength="2000" @error('description') aria-invalid="true" @enderror></textarea>
                            @error('description')<span class="text-sm text-error" role="alert">{{ $message }}</span>@enderror
                        </label>
                        <div class="sm:col-span-2"><x-landing-tags :suggestions="$tagSuggestions" /></div>
                    </div>
                </x-ui::panel>
            </section>

            @foreach ($sections as $section)
                <section x-show="section === {{ $loop->iteration }}" x-cloak aria-label="{{ $section['label'] }}" wire:key="template-section-{{ $loop->index }}">
                    <x-ui::panel :title="$section['label']">
                        @include('templates.fields', ['fields' => $section['fields'], 'prefix' => ''])
                    </x-ui::panel>
                </section>
            @endforeach

            <div class="mt-5 flex flex-wrap items-center justify-between gap-3">
                <button type="button" class="d-btn d-btn-ghost d-btn-sm" @click="section = Math.max(0, section - 1)" :disabled="section === 0">← Previous section</button>
                <button type="button" class="d-btn d-btn-outline d-btn-sm" @click="section = Math.min({{ count($sections) }}, section + 1)" x-show="section < {{ count($sections) }}">Next section →</button>
                <button type="submit" class="d-btn d-btn-primary d-btn-sm" x-show="section === {{ count($sections) }}" x-cloak :disabled="uploadsInProgress > 0" wire:loading.attr="disabled" wire:target="create,uploads">Create landing</button>
            </div>
        </div>
        <aside class="fl-studio-preview" aria-label="Live preview">
            <header><span><span class="fl-live-dot"></span> LIVE PREVIEW</span><span>DESKTOP</span></header>
            <div class="fl-preview-stage">
                @if ($previewHtml !== null)
                    <div class="fl-browser-frame"><div class="fl-browser-bar"><i></i><i></i><i></i><span>index.html</span></div><iframe title="Landing preview" sandbox="" referrerpolicy="no-referrer" srcdoc="{{ $previewHtml }}"></iframe></div>
                @else
                    <div class="fl-preview-empty"><strong>Preview unavailable</strong><span>Complete the required fields to render this template.</span></div>
                @endif
            </div>
            <footer>Static preview · scripts disabled</footer>
        </aside>
    </form>
</div>
