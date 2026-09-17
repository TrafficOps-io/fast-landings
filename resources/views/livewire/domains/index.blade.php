<x-ui::page wire:poll.5s x-data x-on:domain-selected.window="$nextTick(() => { $refs.domainDetails?.scrollIntoView({ block: 'start', behavior: 'smooth' }); $refs.domainDetails?.focus({ preventScroll: true }); })" x-on:domain-create.window="$nextTick(() => { $refs.domainCreate?.scrollIntoView({ block: 'start', behavior: 'smooth' }); $refs.domainCreate?.focus({ preventScroll: true }); })">
    <x-ui::page-header title="Domains" eyebrow="Publishing" :back="$returnLanding ? route('landings.show', $returnLanding) : null" :back-label="$returnLanding ? 'Back to '.$returnLanding->name : null">
        <x-slot:actions>
            @if (auth()->user()->isAdministrator())
                <a href="{{ route('cloudflare.index', array_filter(['landing' => $returnLandingId])) }}" wire:navigate class="d-btn d-btn-ghost d-btn-sm">Cloudflare connections <span class="d-badge d-badge-sm d-badge-ghost">{{ $integrations->count() }}</span></a>
            @endif
            @unless ($showCreate)<button type="button" class="d-btn d-btn-primary d-btn-sm" wire:click="startCreate">+ Add domain</button>@endunless
        </x-slot:actions>
    </x-ui::page-header>
    <p class="-mt-2 max-w-3xl text-sm leading-6 text-base-content/60">Connect an address to this server, then choose the landing it serves. Keep domains unassigned until you need them.</p>
    <x-ui::feedback />

    @unless ($showCreate)
    <div class="grid grid-cols-2 gap-3 lg:grid-cols-4" aria-label="Domain overview">
        @foreach ([['All domains', $counts->total, '', null], ['Configured', $counts->connected, 'active', null], ['Unassigned', $counts->unassigned, '', 'unassigned'], ['Need attention', $counts->attention, 'attention', null]] as [$label, $count, $filterStatus, $filterAssignment])
            <button type="button" class="rounded-box border border-base-300 bg-base-100 px-4 py-3 text-left transition hover:border-primary/50 focus-visible:outline-2 focus-visible:outline-primary" wire:click="filterOverview('{{ $filterAssignment ?: $filterStatus }}')">
                <span class="block text-xs text-base-content/60">{{ $label }}</span>
                <span class="mt-1 block text-2xl font-semibold tabular-nums {{ $label === 'Need attention' && $count ? 'text-error' : '' }}">{{ (int) $count }}</span>
            </button>
        @endforeach
    </div>

    @endunless
    @if ($showCreate)
        @include('livewire.domains.partials.create')
    @endif
    @if ($selectedDomain && ! $showCreate)
        @include('livewire.domains.partials.details', ['domain' => $selectedDomain])
    @elseif ($selectedDomainId !== '' && ! $showCreate)
        <div class="rounded-box border border-base-300 p-4 text-sm" role="status">This domain is no longer in the registry. <button type="button" wire:click="closeDetails" class="underline">Dismiss</button></div>
    @endif

    @unless ($showCreate)
    <x-ui::panel title="Domain registry" description="DNS connection and landing assignment are managed separately.">
        <div class="mb-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-[minmax(12rem,1fr)_auto_auto_auto]">
            <label class="grid gap-1.5"><span class="text-xs font-medium text-base-content/60">Search domains or landings</span><input wire:model.live.debounce.300ms="search" type="search" class="d-input d-input-bordered d-input-sm w-full" placeholder="Search by hostname or landing…"></label>
            <label class="grid gap-1.5"><span class="text-xs font-medium text-base-content/60">Connection</span><select wire:model.live="status" class="d-select d-select-bordered d-select-sm w-full"><option value="">All statuses</option><option value="active">Configured</option><option value="pending">Waiting for DNS</option><option value="attention">Needs attention</option></select></label>
            <label class="grid gap-1.5"><span class="text-xs font-medium text-base-content/60">Assignment</span><select wire:model.live="assignment" class="d-select d-select-bordered d-select-sm w-full"><option value="">All assignments</option><option value="unassigned">Unassigned</option><option value="assigned">Assigned to a landing</option></select></label>
            <label class="grid gap-1.5"><span class="text-xs font-medium text-base-content/60">DNS provider</span><select wire:model.live="provider" class="d-select d-select-bordered d-select-sm w-full"><option value="">All providers</option><option value="system">System subdomain</option><option value="dns">Manual DNS</option><option value="cloudflare">Cloudflare</option></select></label>
        </div>
        @if ($domains->isEmpty())
            @if ($search || $status || $assignment || $provider)
                <x-ui::empty-state title="No matching domains" description="Try a different hostname or clear the filters." />
                <div class="text-center"><button type="button" class="d-btn d-btn-ghost d-btn-sm" wire:click="clearFilters">Clear filters</button></div>
            @else
                <x-ui::empty-state title="Your first address starts here" description="Use a system subdomain, connect your own DNS, or choose a zone from Cloudflare. You can assign a landing later." />
                <div class="text-center"><button type="button" class="d-btn d-btn-primary d-btn-sm" wire:click="startCreate">Add your first domain</button></div>
            @endif
        @else
            <div class="hidden grid-cols-[minmax(0,1.3fr)_minmax(0,1fr)_minmax(0,1fr)_5rem] gap-4 border-b border-base-300 px-3 pb-2 text-xs font-medium text-base-content/50 md:grid" aria-hidden="true"><span>Domain / provider</span><span>DNS connection</span><span>Landing</span><span></span></div>
            <div class="divide-y divide-base-300">
                @foreach ($domains as $domain)
                    <article class="grid min-w-0 gap-3 rounded-lg px-3 py-4 md:grid-cols-[minmax(0,1.3fr)_minmax(0,1fr)_minmax(0,1fr)_5rem] md:items-center md:gap-4 {{ $selectedDomainId === $domain->id ? 'bg-primary/5' : '' }}" wire:key="domain-{{ $domain->id }}">
                        <div class="min-w-0">
                            <button type="button" class="break-all text-left text-sm font-semibold hover:text-primary hover:underline" wire:click="selectDomain('{{ $domain->id }}')">{{ $domain->hostname }}</button>
                            <p class="mt-1 break-words text-xs text-base-content/55">{{ $domain->providerLabel() }}@if ($domain->provider->value === 'cloudflare') · {{ $domain->dnsSource()->cloudflareDomain?->zone?->account?->integration?->label ?: 'Connection unavailable' }}@endif</p>
                        </div>
                        <div class="min-w-0">
                            <p class="mb-1 text-xs text-base-content/50">{{ $domain->dnsScopeLabel() }}</p><x-ui::status-badge :label="$domain->statusLabel()" :tone="$domain->statusTone()" />
                            @if ($domain->verification_requested_at)<p class="mt-1 text-xs text-base-content/55">Check queued</p>@elseif ($domain->last_checked_at && $domain->provider->value !== 'system')<p class="mt-1 text-xs text-base-content/55">Checked {{ $domain->last_checked_at->diffForHumans() }}</p>@endif
                        </div>
                        <div class="min-w-0 text-sm">
                            @if ($domain->landing)
                                <a href="{{ route('landings.show', $domain->landing) }}" wire:navigate class="break-words font-medium hover:text-primary hover:underline">{{ $domain->landing->name }}</a>
                                <p class="mt-1 text-xs text-base-content/55">{{ ! $domain->landing->is_active ? 'Landing paused' : (! $domain->landing->activeRelease ? 'No active release' : ($domain->is_primary ? 'Primary address' : 'Additional address')) }}</p>
                            @else
                                <span class="text-base-content/50">Unassigned</span><p class="mt-1 text-xs text-base-content/45">Choose a landing when ready</p>
                            @endif
                        </div>
                        <button type="button" class="d-btn d-btn-outline d-btn-sm justify-self-start md:justify-self-end" wire:click="selectDomain('{{ $domain->id }}')" aria-label="Manage {{ $domain->hostname }}">Manage</button>
                    </article>
                @endforeach
            </div>
            <div class="mt-4">{{ $domains->links() }}</div>
        @endif
    </x-ui::panel>

    @endunless
    <details class="rounded-box border border-base-300 bg-base-100 px-5 py-4 text-sm">
        <summary class="cursor-pointer font-medium">Server &amp; DNS setup <span class="ml-2 font-normal text-base-content/50">{{ $installation->domain }}</span></summary>
        <div class="mt-4 grid gap-4 text-sm leading-6 text-base-content/65 lg:grid-cols-2">
            <div><strong class="text-base-content">System subdomains</strong><p>Configure wildcard DNS once for this installation:</p><code class="mt-2 block break-all rounded bg-base-200 p-3 text-xs">*.{{ $installation->domain }} &nbsp; {{ \App\Support\DnsTarget::recordType($installation->origin_target) }} &nbsp; {{ $installation->origin_target }}</code></div>
            <div><strong class="text-base-content">Automatic checks &amp; HTTPS</strong><p>Keep the queue worker and scheduler running. Custom DNS is checked in the background. In the supplied production stack, Caddy requests HTTPS certificates when a verified domain serves a published landing. Keep ports 80 and 443 reachable.</p><p class="mt-2 text-xs">DNS verification does not test certificate issuance. System addresses rely on your wildcard DNS configuration.</p></div>
        </div>
    </details>
</x-ui::page>
