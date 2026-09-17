<x-ui::page>
    <x-ui::page-header title="Templates" eyebrow="Landing builder">
        <x-slot:actions>
            <a href="{{ route('landings.index') }}" class="d-btn d-btn-ghost d-btn-sm" wire:navigate>View landings</a>
        </x-slot:actions>
    </x-ui::page-header>

    <x-ui::feedback />

    @if ($canManageTemplates)
        <x-ui::panel title="Import template" description="Upload one HTML, PHP, TXT or TPL template, or a ZIP with the template and its styles, scripts and images. The template declares its own settings and blocks.">
            <form wire:submit="importTemplate" class="grid gap-4">
                <x-upload-area wire:model="templateUpload" wire:key="template-upload-{{ $uploadVersion }}"
                    :file-name="$templateUpload?->getClientOriginalName() ?? ''"
                    id="template-upload" label="Template file" required
                    accept=".html,.php,.txt,.tpl,.zip,text/html,text/plain,application/zip" target="templateUpload,importTemplate"
                    :help="'One file, up to '.(int) (config('fast-landings.max_upload_kb') / 1024).' MB. ZIP packages can start with index.php, index.tpl.html, index.tpl.php, index.html or template.html / .txt / .tpl.'" />
                <div class="flex flex-wrap items-center gap-3">
                    <button class="d-btn d-btn-primary" type="submit" wire:loading.attr="disabled" wire:target="templateUpload,importTemplate">
                        <span class="d-loading d-loading-spinner d-loading-sm" wire:loading wire:target="templateUpload,importTemplate"></span>
                        Import template
                    </button>
                    <p class="text-sm text-base-content/55" wire:loading wire:target="templateUpload" role="status">Uploading template…</p>
                </div>
                <p class="text-xs leading-5 text-base-content/60">Start with an example:
                    <a href="{{ asset('examples/article-template.html') }}" download class="link link-primary underline">single-file template</a>
                    or <a href="{{ asset('examples/article-template.zip') }}" download class="link link-primary underline">ZIP with assets</a>.
                    Try <a href="{{ asset('examples/rich-text-template.tpl') }}" download class="link link-primary underline">WYSIWYG and Markdown editors</a> with image uploads.
                    See <a href="{{ asset('examples/preview-template.tpl') }}" download class="link link-primary underline">a template with preview data</a> for automatic screenshots.
                    Try a <a href="{{ asset('examples/form-website-template.zip') }}" download class="link link-primary underline">form with a PHP success page</a>.
                </p>
            </form>
        </x-ui::panel>
    @endif

    @if ($canManageTemplates && $editingTemplateId)
        <div wire:key="template-editor-{{ $editingTemplateId }}" x-init="$el.scrollIntoView({ behavior: 'smooth', block: 'start' })">
            <x-ui::panel title="Edit template" description="Update the name and description, or upload a file to replace the template's layout, settings and assets.">
                <form wire:submit="saveTemplate" class="grid gap-4">
                    <label class="grid gap-1.5">
                        <span class="text-sm font-medium">Template name</span>
                        <input wire:model="name" type="text" maxlength="200" required class="d-input d-input-bordered w-full" @error('name') aria-invalid="true" @enderror>
                        @error('name')<span class="text-sm text-error" role="alert">{{ $message }}</span>@enderror
                    </label>
                    <label class="grid gap-1.5">
                        <span class="text-sm font-medium">Description</span>
                        <textarea wire:model="description" rows="3" maxlength="2000" class="d-textarea d-textarea-bordered w-full" @error('description') aria-invalid="true" @enderror></textarea>
                        @error('description')<span class="text-sm text-error" role="alert">{{ $message }}</span>@enderror
                    </label>
                    <x-upload-area wire:model="replacementUpload" wire:key="replacement-upload-{{ $replacementUploadVersion }}"
                        :file-name="$replacementUpload?->getClientOriginalName() ?? ''"
                        id="replacement-upload" label="Replacement file (optional)"
                        accept=".html,.php,.txt,.tpl,.zip,text/html,text/plain,application/zip" target="replacementUpload,saveTemplate"
                        :help="'HTML, PHP, TXT, TPL or ZIP, up to '.(int) (config('fast-landings.max_upload_kb') / 1024).' MB. Leave empty to keep the current content. The name and description above will be used when saving.'" />
                    <p class="text-sm text-base-content/55" wire:loading wire:target="replacementUpload" role="status">Uploading replacement…</p>
                    <p class="text-sm text-base-content/65">Existing landing releases stay unchanged. New landings and future edits use the updated template.</p>
                    <div class="flex flex-wrap gap-2">
                        <button type="submit" class="d-btn d-btn-primary" wire:loading.attr="disabled" wire:target="replacementUpload,saveTemplate">
                            <span class="d-loading d-loading-spinner d-loading-sm" wire:loading wire:target="replacementUpload,saveTemplate"></span>
                            Save template
                        </button>
                        <button type="button" class="d-btn d-btn-ghost" wire:click="cancelEdit" wire:loading.attr="disabled" wire:target="replacementUpload,saveTemplate">Cancel</button>
                    </div>
                </form>
            </x-ui::panel>
        </div>
    @endif

    <x-ui::panel title="Available templates" description="Choose a template, customize its content and appearance, then generate a landing page.">
        @if ($templates->isEmpty())
            <x-ui::empty-state title="No templates yet" :description="$canManageTemplates ? 'Import a template to start building reusable landing pages.' : 'Ask an administrator to import a template.'" />
        @else
            <div class="grid gap-5 md:grid-cols-2 xl:grid-cols-3" data-testid="template-grid" @if ($hasPendingPreviews) wire:poll.5s @endif>
                @foreach ($templates as $template)
                    @php($pending = in_array($template->preview_status, ['queued', 'processing'], true))
                    @php($canPreview = $template->supportsLocalPreview() || isset($template->definition['previewUrl']))
                    <article wire:key="template-{{ $template->id }}" class="group flex min-w-0 flex-col overflow-hidden rounded-box border border-base-300 bg-base-100 transition-shadow hover:shadow-lg" data-testid="template-card">
                        <a href="{{ route('landings.from-template', $template) }}" wire:navigate class="relative block aspect-[16/10] overflow-hidden border-b border-base-300 bg-base-200" aria-label="Use {{ $template->name }}">
                            @if ($template->preview_path)
                                <img src="{{ route('templates.preview', ['template' => $template, 'v' => basename($template->preview_path)]) }}"
                                    alt="Screenshot of {{ $template->name }}" width="1440" height="1000" loading="lazy"
                                    class="h-full w-full object-cover object-top transition-transform duration-300 group-hover:scale-[1.02]">
                            @else
                                <div class="flex h-full flex-col items-center justify-center gap-3 px-6 text-center text-base-content/40">
                                    @if ($pending)
                                        <span class="d-loading d-loading-spinner d-loading-md" aria-hidden="true"></span>
                                    @else
                                        <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.25" aria-hidden="true"><rect x="3" y="4" width="18" height="16" rx="2"/><path d="M3 8h18M7 6h.01M10 6h.01M7 16l3-3 3 3 2-2 3 3"/></svg>
                                    @endif
                                    <span class="text-sm">{{ $pending ? 'Creating screenshot…' : ($template->preview_status === 'failed' ? 'Screenshot unavailable' : (!$canPreview ? 'Add a public preview URL for this PHP template' : ($template->hasPreviewSource() ? 'Preview not generated yet' : 'No preview provided'))) }}</span>
                                </div>
                            @endif
                        </a>
                        <div class="flex flex-1 flex-col p-5">
                            <div class="mb-4 flex items-start justify-between gap-3">
                                <h2 class="break-words text-lg font-semibold">{{ $template->name }}</h2>
                                <span class="d-badge d-badge-outline shrink-0 text-xs">{{ count($template->definition['sections']) }} sections</span>
                            </div>
                            <p class="mb-5 whitespace-pre-line break-words text-sm leading-6 text-base-content/65">{{ $template->description ?: 'Customizable landing template.' }}</p>
                            <div class="mt-auto border-t border-base-300 pt-4">
                                <p class="mb-3 truncate text-xs text-base-content/50" title="{{ $template->original_name }}">{{ $template->original_name }} · {{ $template->created_at->format('M j, Y') }}</p>
                                @if (($canManageTemplates && $canPreview && $template->hasPreviewSource() && config('fast-landings.previews.enabled')) || isset($template->definition['previewUrl']))
                                    <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                                        @if ($canManageTemplates && $canPreview && $template->hasPreviewSource() && config('fast-landings.previews.enabled'))
                                            <button type="button" wire:click="refreshPreview('{{ $template->id }}')" wire:loading.attr="disabled" wire:target="refreshPreview('{{ $template->id }}')" @disabled($pending)
                                                class="d-btn d-btn-ghost d-btn-xs -ml-2 text-base-content/55" aria-label="Refresh screenshot for {{ $template->name }}">
                                                {{ $pending ? 'Creating preview' : ($template->preview_status === 'failed' ? 'Retry preview' : 'Refresh preview') }}
                                            </button>
                                        @endif
                                        @if (isset($template->definition['previewUrl']))
                                            <a href="{{ $template->definition['previewUrl'] }}" target="_blank" rel="noopener noreferrer" class="d-btn d-btn-outline d-btn-xs">Open preview ↗</a>
                                        @endif
                                    </div>
                                @endif
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <a href="{{ route('landings.from-template', $template) }}" class="d-btn d-btn-primary d-btn-sm" wire:navigate>Use template <span aria-hidden="true">→</span></a>
                                    @if ($canManageTemplates)
                                        <a href="{{ route('templates.files', $template) }}" class="d-btn d-btn-outline d-btn-sm" wire:navigate>Files</a>
                                        <button type="button" class="d-btn d-btn-ghost d-btn-sm" wire:click="editTemplate(@js($template->id))" wire:loading.attr="disabled" wire:target="replacementUpload,saveTemplate,editTemplate">Edit</button>
                                        <button type="button" class="d-btn d-btn-ghost d-btn-sm text-error" wire:click="deleteTemplate(@js($template->id))" wire:confirm="Delete this template? Existing landings will remain available." wire:loading.attr="disabled" wire:target="deleteTemplate">Delete</button>
                                    @endif
                                </div>
                            </div>
                        </div>
                    </article>
                @endforeach
            </div>
        @endif
    </x-ui::panel>
</x-ui::page>
