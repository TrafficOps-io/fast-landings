<x-ui::page>
    <x-ui::page-header title="Landings" eyebrow="Deployments">
        <x-slot:actions>
            <div class="flex flex-wrap items-center gap-2">
                <a href="{{ route('templates.index') }}" class="d-btn d-btn-outline d-btn-sm" wire:navigate>Create from template</a>
                <button type="button" class="d-btn d-btn-primary d-btn-sm" wire:click="$toggle('showCreate')">
                    {{ $showCreate ? 'Close' : 'Deploy landing' }}
                </button>
            </div>
        </x-slot:actions>
    </x-ui::page-header>

    <x-ui::feedback />

    @if ($showCreate)
        <x-ui::panel title="New landing" description="Upload a ZIP containing index.php or index.html. A single wrapper folder is accepted.">
            <form wire:submit="create" class="grid gap-4 lg:grid-cols-2">
                <label class="grid gap-1.5">
                    <span class="text-sm font-medium">Name</span>
                    <input wire:model.live.debounce.300ms="name" class="d-input d-input-bordered w-full" required maxlength="120">
                </label>
                <label class="grid gap-1.5">
                    <span class="text-sm font-medium">Slug</span>
                    <input wire:model="slug" class="d-input d-input-bordered w-full" required maxlength="120" placeholder="summer-offer">
                </label>
                <label class="grid gap-1.5 lg:col-span-2">
                    <span class="text-sm font-medium">Description <span class="text-base-content/50">optional</span></span>
                    <textarea wire:model="description" class="d-textarea d-textarea-bordered min-h-20 w-full" maxlength="2000"></textarea>
                </label>
                <x-upload-area wire:model="archive" id="landing-archive" label="ZIP archive" required
                    :file-name="$archive?->getClientOriginalName() ?? ''"
                    accept=".zip,application/zip" target="archive,create" class="lg:col-span-2"
                    :help="'Maximum '.(int) (config('fast-landings.max_upload_kb') / 1024).' MB compressed.'" />
                <div class="lg:col-span-2"><x-landing-tags :suggestions="$tagSuggestions" /></div>
                <div class="flex flex-wrap items-center gap-3 lg:col-span-2">
                    <button class="d-btn d-btn-primary" type="submit" wire:loading.attr="disabled" wire:target="archive,create">
                        <span class="d-loading d-loading-spinner d-loading-sm" wire:loading wire:target="archive,create"></span>
                        Upload and deploy
                    </button>
                    <span class="text-sm text-base-content/55" wire:loading wire:target="archive">Uploading archive…</span>
                </div>
            </form>
        </x-ui::panel>
    @endif

    <section aria-label="Landing library" class="space-y-5" @if ($hasPendingPreviews) wire:poll.5s @endif>
        <div class="flex flex-col gap-3 rounded-box border border-base-300 bg-base-100 p-4 sm:flex-row sm:items-end">
            <label class="grid min-w-0 flex-1 gap-1.5">
                <span class="text-xs font-medium text-base-content/60">Search landings</span>
                <input type="search" wire:model.live.debounce.300ms="search" placeholder="Search by name, slug or tag…" maxlength="120" class="d-input d-input-bordered w-full">
            </label>
            <label class="grid gap-1.5 sm:w-48">
                <span class="text-xs font-medium text-base-content/60">Filter by tag</span>
                <select wire:model.live="tag" class="d-select d-select-bordered w-full">
                    <option value="">All tags</option>
                    @foreach ($availableTags as $availableTag)
                        <option value="{{ $availableTag->id }}">{{ $availableTag->name }}</option>
                    @endforeach
                </select>
            </label>
            <label class="grid gap-1.5 sm:w-40">
                <span class="text-xs font-medium text-base-content/60">Status</span>
                <select wire:model.live="status" class="d-select d-select-bordered w-full">
                    <option value="">All statuses</option>
                    <option value="active">Deployed</option>
                    <option value="paused">Paused</option>
                    <option value="empty">Empty</option>
                </select>
            </label>
        </div>

        <div class="flex min-h-7 items-center justify-between gap-3">
            <p class="text-sm text-base-content/60"><span class="font-semibold text-base-content">{{ $landings->total() }}</span> {{ Str::plural('landing', $landings->total()) }}</p>
            @if ($search !== '' || $tag !== '' || $status !== '')
                <button type="button" wire:click="clearFilters" class="d-btn d-btn-ghost d-btn-xs">Clear filters ×</button>
            @endif
        </div>

        <div class="grid grid-cols-1 gap-5 md:grid-cols-2 xl:grid-cols-3" data-testid="landing-grid">
            @forelse ($landings as $landing)
                @php
                    $release = $landing->activeRelease;
                    $pending = in_array($release?->preview_status, ['queued', 'processing'], true);
                    $domain = $landing->domains->firstWhere('status', \App\Enums\DomainStatus::Active);
                @endphp
                <article wire:key="landing-card-{{ $landing->id }}" class="group flex min-w-0 flex-col overflow-hidden rounded-box border border-base-300 bg-base-100 transition-shadow hover:shadow-lg" data-testid="landing-card">
                    <a href="{{ route('landings.show', $landing) }}" wire:navigate class="relative block aspect-[16/10] overflow-hidden border-b border-base-300 bg-base-200" aria-label="View {{ $landing->name }}">
                        @if ($release?->preview_path)
                            <img src="{{ route('landings.preview', ['release' => $release, 'v' => basename($release->preview_path)]) }}"
                                alt="Screenshot of {{ $landing->name }}" width="1440" height="1000" loading="lazy"
                                class="h-full w-full object-cover object-top transition-transform duration-300 group-hover:scale-[1.02]">
                        @else
                            <div class="flex h-full flex-col items-center justify-center gap-3 px-6 text-center text-base-content/40">
                                @if ($pending)
                                    <span class="d-loading d-loading-spinner d-loading-md" aria-hidden="true"></span>
                                @else
                                    <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.25" aria-hidden="true"><rect x="3" y="4" width="18" height="16" rx="2"/><path d="M3 8h18M7 6h.01M10 6h.01M7 16l3-3 3 3 2-2 3 3"/></svg>
                                @endif
                                <span class="text-sm">{{ !$release ? 'Deploy a release to see its preview' : ($pending ? 'Creating screenshot…' : ($release->entrypoint === 'index.php' ? 'Open the landing to view this PHP page' : ($release->preview_status === 'failed' ? 'Screenshot unavailable' : 'Preview not generated yet'))) }}</span>
                            </div>
                        @endif
                        <div class="absolute left-3 top-3 rounded-full bg-base-100/95 shadow-sm">
                            <x-ui::status-badge :label="!$landing->is_active ? 'Paused' : ($release ? 'Deployed' : 'Empty')" :tone="!$landing->is_active ? 'neutral' : ($release ? 'success' : 'warning')" />
                        </div>
                    </a>
                    <div class="flex flex-1 flex-col gap-4 p-5">
                        <div class="min-w-0">
                            <a href="{{ route('landings.show', $landing) }}" wire:navigate class="block truncate text-base font-semibold hover:text-primary" title="{{ $landing->name }}">{{ $landing->name }}</a>
                            <p class="mt-1 truncate font-mono text-xs text-base-content/45" title="{{ $landing->slug }}">{{ $landing->slug }}</p>
                            @if ($landing->description)<p class="mt-3 line-clamp-2 break-words text-sm leading-relaxed text-base-content/60">{{ $landing->description }}</p>@endif
                        </div>
                        <div class="flex flex-wrap gap-1.5">
                            @forelse ($landing->tags as $landingTag)
                                <button type="button" wire:click="$set('tag', '{{ $landingTag->id }}')" class="max-w-full truncate rounded-md bg-primary/10 px-2 py-1 text-xs font-medium text-primary hover:bg-primary/20" title="Filter by {{ $landingTag->name }}">{{ $landingTag->name }}</button>
                            @empty
                                <span class="text-xs text-base-content/35">No tags</span>
                            @endforelse
                        </div>
                        <div class="mt-auto space-y-3">
                            <p class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-base-content/50">
                                <span>{{ $landing->domains_count }} {{ Str::plural('domain', $landing->domains_count) }}</span>
                                <span>{{ $landing->releases_count }} {{ Str::plural('release', $landing->releases_count) }}</span>
                                <span class="truncate">{{ $landing->template ? 'Template' : 'ZIP archive' }}</span>
                            </p>
                            <div class="flex flex-wrap items-center justify-between gap-2 border-t border-base-200 pt-3">
                                @if ($release && $release->entrypoint === 'index.html' && config('fast-landings.previews.enabled'))
                                    <button type="button" wire:click="refreshPreview('{{ $landing->id }}')" wire:loading.attr="disabled" wire:target="refreshPreview('{{ $landing->id }}')" @disabled($pending)
                                        class="d-btn d-btn-ghost d-btn-xs -ml-2 text-base-content/55" aria-label="Refresh screenshot for {{ $landing->name }}">
                                        @if ($pending)<span class="d-loading d-loading-spinner d-loading-xs" aria-hidden="true"></span>@endif
                                        {{ $pending ? 'Creating preview' : ($release->preview_status === 'failed' ? 'Retry preview' : 'Refresh preview') }}
                                    </button>
                                @else
                                    <span class="text-xs text-base-content/40">{{ $release ? 'Screenshot preview' : 'No active release' }}</span>
                                @endif
                                <div class="flex flex-wrap items-center gap-2">
                                    @if ($release)
                                        <a href="{{ route('landings.download', ['release' => $release]) }}" class="d-btn d-btn-outline d-btn-xs" aria-label="Download ZIP for {{ $landing->name }}" download>Download ZIP</a>
                                    @endif
                                    @if ($domain && $release && $landing->is_active)
                                        <a href="{{ $domain->publicUrl() }}" target="_blank" rel="noopener noreferrer" class="d-btn d-btn-outline d-btn-xs" aria-label="Open {{ $landing->name }}">Open landing ↗</a>
                                    @else
                                        <a href="{{ route('landings.show', $landing) }}" wire:navigate class="d-btn d-btn-outline d-btn-xs">Manage →</a>
                                    @endif
                                </div>
                            </div>
                        </div>
                    </div>
                </article>
            @empty
                <div class="col-span-full rounded-box border border-dashed border-base-300 bg-base-100 py-8">
                    @if ($search !== '' || $tag !== '' || $status !== '')
                        <x-ui::empty-state title="No matching landings" description="Try a different search or clear the filters." />
                    @else
                        <x-ui::empty-state title="No landings yet" description="Create a landing from a template or deploy a ZIP archive to get started." />
                    @endif
                </div>
            @endforelse
        </div>
        {{ $landings->links() }}
    </section>
</x-ui::page>
