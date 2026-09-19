<x-ui::page>
    <div class="grid gap-6" x-data="{ uploadsInProgress: 0 }"
        x-on:template-image-upload-start="uploadsInProgress++"
    x-on:template-image-upload-finish="uploadsInProgress = Math.max(0, uploadsInProgress - 1)"
    x-on:livewire-upload-start="uploadsInProgress++"
        x-on:livewire-upload-finish="uploadsInProgress = Math.max(0, uploadsInProgress - 1)"
        x-on:livewire-upload-error="uploadsInProgress = Math.max(0, uploadsInProgress - 1)"
        x-on:livewire-upload-cancel="uploadsInProgress = Math.max(0, uploadsInProgress - 1)">
        <x-ui::page-header title="Edit landing content" :eyebrow="$landing->name" :back="route('landings.show', $landing)" back-label="Back to landing">
            <x-slot:actions>
                @if ($template)
                    <button type="submit" form="edit-template-form" class="d-btn d-btn-primary d-btn-sm" :disabled="uploadsInProgress > 0" wire:loading.attr="disabled">
                        <span class="d-loading d-loading-spinner d-loading-sm" wire:loading wire:target="save"></span>
                        Save and activate
                    </button>
                @endif
            </x-slot:actions>
        </x-ui::page-header>

        <x-ui::feedback />

        <x-ui::panel title="Template" description="Edit the saved data or select another template to start with its defaults. Saving creates a new release for this landing and its existing domains.">
            @if ($templates->isEmpty())
                <x-ui::empty-state title="No templates available" description="An administrator needs to import a template before you can use one." />
                <a href="{{ route('templates.index') }}" class="d-btn d-btn-outline d-btn-sm mt-4" wire:navigate>View templates</a>
            @else
                <label class="grid max-w-xl gap-1.5">
                    <span class="text-sm font-medium">Landing template</span>
                    <select wire:model.live="templateId" class="d-select d-select-bordered w-full" :disabled="uploadsInProgress > 0" wire:loading.attr="disabled" aria-invalid="{{ $errors->has('templateId') ? 'true' : 'false' }}">
                        <option value="">Choose a template</option>
                        @foreach ($templates as $option)
                            <option value="{{ $option->id }}">{{ $option->name }}{{ $option->id === $landing->landing_template_id ? ' · current template' : '' }}</option>
                        @endforeach
                    </select>
                    @error('templateId')<span class="text-sm text-error" role="alert">{{ $message }}</span>@enderror
                </label>
                @if ($template?->description)<p class="mt-3 max-w-3xl whitespace-pre-line text-sm text-base-content/65">{{ $template->description }}</p>@endif
                <p class="mt-3 text-xs text-base-content/55">Changing the selection resets unsaved fields and image uploads. The current release stays available until you save.</p>
            @endif
        </x-ui::panel>

        <p x-show="uploadsInProgress > 0" x-cloak class="text-sm text-base-content/65" role="status">Uploading images… Please wait before saving.</p>

        @if ($template)
            <form id="edit-template-form" wire:submit="save" novalidate class="fl-studio-grid" wire:key="edit-template-{{ $template->id }}" x-data="{ section: 0 }">
                <nav aria-label="Template settings sections" class="fl-studio-nav">
                    @foreach ($sections as $section)
                        <button type="button" class="flex shrink-0 items-center gap-3 rounded-lg px-3 py-3 text-left text-sm" @click="section = {{ $loop->index }}" :class="section === {{ $loop->index }} ? 'bg-primary/10 text-primary font-semibold' : 'hover:bg-base-200 text-base-content/65'" :aria-current="section === {{ $loop->index }} ? 'step' : null">
                            <span class="font-mono text-xs opacity-60">{{ str_pad($loop->iteration, 2, '0', STR_PAD_LEFT) }}</span><span>{{ $section['label'] }}</span>
                            @if (collect($section['fields'])->contains(fn ($field) => $errors->has('values.'.$field['name'].'*') || $errors->has('uploads.'.$field['name'].'*')))<span class="text-error" aria-label="Contains errors">•</span>@endif
                        </button>
                    @endforeach
                    <p class="mt-4 hidden border-t border-base-300 px-3 pt-4 text-xs leading-5 text-base-content/50 lg:block">Previous releases remain available for rollback. Saved images are kept unless you replace or clear them.</p>
                </nav>

                <div class="min-w-0">
                    @foreach ($sections as $section)
                        <section x-show="section === {{ $loop->index }}" @if (!$loop->first) x-cloak @endif aria-label="{{ $section['label'] }}" wire:key="edit-section-{{ $template->id }}-{{ $loop->index }}">
                            <x-ui::panel :title="$section['label']">
                                @include('templates.fields', ['fields' => $section['fields'], 'prefix' => ''])
                            </x-ui::panel>
                        </section>
                    @endforeach
                    <div class="mt-5 flex flex-wrap items-center justify-between gap-3">
                        <button type="button" class="d-btn d-btn-ghost d-btn-sm" @click="section = Math.max(0, section - 1)" :disabled="section === 0">← Previous section</button>
                        <button type="button" class="d-btn d-btn-outline d-btn-sm" @click="section = Math.min({{ count($sections) - 1 }}, section + 1)" x-show="section < {{ count($sections) - 1 }}">Next section →</button>
                        <button type="submit" class="d-btn d-btn-primary d-btn-sm" :disabled="uploadsInProgress > 0" wire:loading.attr="disabled">Save and activate</button>
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
        @endif
    </div>
</x-ui::page>
