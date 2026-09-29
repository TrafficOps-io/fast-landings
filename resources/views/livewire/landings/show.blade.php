<x-ui::page>
    <x-ui::page-header :title="$landing->name" eyebrow="Landing" :back="route('landings.index')" back-label="All landings">
        <x-slot:actions>
            @if ($landing->domains->firstWhere('is_primary', true) ?? $landing->domains->first())
                @php($previewDomain = $landing->domains->firstWhere('is_primary', true) ?? $landing->domains->first())
                <a href="{{ $previewDomain->publicUrl() }}" target="_blank" rel="noopener" class="d-btn d-btn-outline d-btn-sm">Open landing ↗</a>
            @endif
        </x-slot:actions>
    </x-ui::page-header>

    <x-ui::feedback />

    <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_22rem]">
        <div class="min-w-0 space-y-6">
            <x-ui::panel title="Landing content" description="Update this landing from a template or upload a ZIP. Each update creates a new release and keeps the same domains.">
                <div class="mb-5 flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <p class="text-sm font-medium">{{ $landing->template ? 'Template: '.$landing->template->name : ($landing->template_values !== null ? 'Template no longer available' : 'ZIP archive') }}</p>
                        @if (!$landing->template && $landing->template_values !== null)<p class="mt-1 text-xs text-base-content/55">The generated page is still available. Choose another template to edit its content.</p>@endif
                    </div>
                    <div class="flex flex-wrap gap-2">
                        @if ($activeFileRelease = $releases->firstWhere('is_active', true))
                            <a href="{{ route('landings.download', ['release' => $activeFileRelease]) }}" class="d-btn d-btn-outline d-btn-sm" download>Download ZIP</a>
                            <a href="{{ route('landings.files', $activeFileRelease) }}" class="d-btn d-btn-outline d-btn-sm" wire:navigate>Edit files</a>
                        @endif
                        @if ($landing->template)
                            <a href="{{ route('landings.edit-template', $landing) }}" class="d-btn d-btn-primary d-btn-sm" wire:navigate>Edit template data</a>
                            <a href="{{ route('landings.edit-template', ['landing' => $landing, 'change' => 1]) }}" class="d-btn d-btn-outline d-btn-sm" wire:navigate>Change template</a>
                        @else
                            <a href="{{ route('landings.edit-template', $landing) }}" class="d-btn d-btn-primary d-btn-sm" wire:navigate>Use a template</a>
                        @endif
                    </div>
                </div>
                <form wire:submit="deploy" class="flex flex-col gap-3 rounded-box border border-dashed border-base-300 bg-base-200/40 p-4">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-end">
                        <label class="grid min-w-0 flex-1 gap-1.5">
                            <span class="text-sm font-medium">{{ $landing->template_values !== null ? 'Replace with ZIP archive' : 'Deploy new ZIP' }}</span>
                            <input wire:model="archive" type="file" accept=".zip,application/zip" class="d-file-input d-file-input-bordered w-full" required>
                        </label>
                        <button type="submit" class="d-btn d-btn-primary" wire:loading.attr="disabled" wire:target="archive,deploy">
                            <span class="d-loading d-loading-spinner d-loading-sm" wire:loading wire:target="archive,deploy"></span>
                            Deploy
                        </button>
                    </div>
                    @if ($landing->template)
                        <label class="flex items-start gap-2 text-sm">
                            <input wire:model="detachFromTemplate" type="checkbox" class="d-checkbox d-checkbox-sm mt-0.5" @error('detachFromTemplate') aria-invalid="true" @enderror>
                            <span><strong>Detach from template.</strong> This is a template landing: deploying a ZIP makes it a file landing whose content is no longer edited through template values. The previous release keeps its template snapshot; activate it to return to the template.</span>
                        </label>
                        @error('detachFromTemplate')<span class="text-sm text-error" role="alert">{{ $message }}</span>@enderror
                    @endif
                </form>
            </x-ui::panel>
            <x-ui::panel title="Releases" description="Activate a previous release to restore its content and template settings.">
                <div class="overflow-x-auto">
                    <table class="d-table d-table-sm">
                        <thead><tr><th>Archive</th><th>Files</th><th>Uploaded</th><th>Status</th><th></th></tr></thead>
                        <tbody>
                        @forelse ($releases as $release)
                            <tr wire:key="release-{{ $release->id }}">
                                <td><span class="font-medium">{{ $release->original_name }}</span><br><span class="text-xs text-base-content/55">{{ $release->template?->name ?? ($release->template_values !== null ? 'Deleted template' : 'ZIP archive') }}</span><br><span class="font-mono text-xs text-base-content/45">{{ Str::limit($release->checksum, 12, '') }}</span></td>
                                <td>{{ $release->file_count }}<br><span class="text-xs text-base-content/50">{{ \Illuminate\Support\Number::fileSize($release->size_bytes) }}</span></td>
                                <td>{{ $release->created_at->diffForHumans() }}<br><span class="text-xs text-base-content/50">{{ $release->uploader?->name ?? 'Deleted user' }}</span></td>
                                <td><x-ui::status-badge :label="$release->is_active ? 'Active' : 'Inactive'" :tone="$release->is_active ? 'success' : 'neutral'" /></td>
                                <td class="text-right">
                                    <a href="{{ route('landings.download', ['release' => $release]) }}" class="d-btn d-btn-ghost d-btn-xs" aria-label="Download ZIP for {{ $release->original_name }}" download>Download ZIP</a>
                                    @if ($release->is_active)
                                        <a href="{{ route('landings.files', $release) }}" class="d-btn d-btn-ghost d-btn-xs" wire:navigate>Files</a>
                                    @else
                                        <span class="d-btn d-btn-ghost d-btn-xs d-btn-disabled" aria-disabled="true" title="Activate this release first to edit its files">Files</span>
                                        <button class="d-btn d-btn-ghost d-btn-xs" wire:click="activate('{{ $release->id }}')">Activate</button>
                                        <button class="d-btn d-btn-ghost d-btn-xs text-error" wire:click="deleteRelease('{{ $release->id }}')" wire:confirm="Delete this release permanently?">Delete</button>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="py-8 text-center text-base-content/55">No releases.</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </x-ui::panel>

            <x-ui::panel title="Domains" description="These addresses serve this landing. Each domain can be assigned to one landing at a time." wire:poll.visible.10s>
                <x-slot:actions>
                    <a href="{{ route('domains.index', ['create' => 1, 'landing' => $landing->id]) }}" class="d-btn d-btn-primary d-btn-sm" wire:navigate>Connect new domain</a>
                </x-slot:actions>
                @if ($landing->domains->isEmpty())
                    <div class="rounded-box border border-dashed border-base-300 p-5">
                        <p class="font-medium">No domains assigned</p>
                        <p class="mt-1 text-sm text-base-content/60">Connect a new domain or assign an available address below to make this landing accessible.</p>
                    </div>
                @else
                    <ul class="divide-y divide-base-300">
                        @foreach ($landing->domains as $domain)
                            <li class="flex flex-col gap-3 py-4 first:pt-0" wire:key="landing-domain-{{ $domain->id }}">
                                <div class="flex flex-wrap items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <a href="{{ route('domains.index', ['domain' => $domain->id]) }}" class="break-all font-medium hover:underline" wire:navigate>{{ $domain->hostname }}</a>
                                        <p class="mt-1 text-xs text-base-content/55">{{ $domain->providerLabel() }}</p>
                                        @if ($domain->parentDomain)
                                            <p class="mt-1 text-xs text-base-content/55">Uses wildcard DNS from <a href="{{ route('domains.index', ['domain' => $domain->parent_domain_id]) }}" class="underline underline-offset-2" wire:navigate>{{ $domain->parentDomain->hostname }}</a></p>
                                        @elseif ($domain->dns_scope === 'wildcard')
                                            <p class="mt-1 text-xs text-base-content/55">This assignment serves {{ $domain->hostname }}. Assign its subdomains separately below.</p>
                                        @endif
                                    </div>
                                    <div class="flex flex-wrap items-center gap-2">
                                        @if ($domain->is_primary)<x-ui::status-badge label="Primary" tone="neutral" />@endif
                                        <x-ui::status-badge :label="$domain->statusLabel()" :tone="$domain->statusTone()" />
                                    </div>
                                </div>
                                <p class="text-sm text-base-content/60">{{ $domain->statusDescription() }}</p>
                                <div class="flex flex-wrap items-center gap-2">
                                    <a href="{{ route('domains.index', ['domain' => $domain->id]) }}" class="d-btn d-btn-outline d-btn-xs" wire:navigate>Connection details</a>
                                    @unless ($domain->is_primary)
                                        <button class="d-btn d-btn-ghost d-btn-xs" wire:click="makePrimary('{{ $domain->id }}')" wire:loading.attr="disabled">Make primary</button>
                                    @endunless
                                    <button class="d-btn d-btn-ghost d-btn-xs text-error" wire:click="detachDomain('{{ $domain->id }}')" wire:loading.attr="disabled" wire:confirm="Unassign {{ $domain->hostname }} from {{ $landing->name }}? This address will stop serving the landing. The domain and its DNS records will remain available for reuse.">Unassign</button>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                    <p class="mt-2 text-xs text-base-content/55">Primary chooses the address used by “Open landing”. Other assigned domains continue serving this landing; no redirects are created.</p>
                @endif

                @if (! $landing->is_active)
                    <p class="mt-4 rounded-box bg-warning/10 p-3 text-sm">This landing is paused. Its domains will not serve content until Published is enabled in Settings.</p>
                @elseif (! $landing->activeRelease)
                    <p class="mt-4 rounded-box bg-warning/10 p-3 text-sm">Deploy a release before sending visitors to these domains.</p>
                @endif

                <div class="mt-6 border-t border-base-300 pt-5">
                    <h3 class="text-sm font-semibold">Available domains <span class="font-normal text-base-content/50">({{ $availableDomains->count() }})</span></h3>
                    <p class="mt-1 text-sm text-base-content/60">Already connected to this installation and not assigned to a landing. Assignment keeps the existing DNS configuration.</p>
                    @if ($availableDomains->isNotEmpty())
                        <form wire:submit="assignDomain" class="mt-3 flex flex-col items-start gap-3 sm:flex-row sm:items-end">
                            <label class="grid w-full min-w-0 flex-1 gap-1.5">
                                <span class="text-xs font-medium">Available address</span>
                                <select wire:model="domainId" class="d-select d-select-bordered w-full" required>
                                    <option value="">Choose an available domain</option>
                                    @foreach ($availableDomains as $domain)
                                        <option value="{{ $domain->id }}">{{ $domain->hostname }}{{ $domain->dns_scope === 'wildcard' ? ' (base address)' : '' }} · {{ $domain->statusLabel() }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <button class="d-btn d-btn-outline" type="submit" wire:loading.attr="disabled" wire:target="assignDomain">Assign to this landing</button>
                        </form>
                    @else
                        <p class="mt-3 text-sm text-base-content/50">No unassigned domains. Connect a new domain or move one from another landing.</p>
                    @endif
                </div>

                <div class="mt-5 rounded-box border border-base-300 p-4">
                    <h3 class="text-sm font-semibold">Use a subdomain</h3>
                    <p class="mt-1 text-sm text-base-content/60">Choose a connected domain with wildcard DNS, then give this landing its own address. Each subdomain is assigned separately.</p>
                    @if ($wildcardDomains->isNotEmpty())
                        <form wire:submit="assignSubdomain" class="mt-4 grid gap-4">
                            <label class="grid min-w-0 gap-1.5">
                                <span class="text-xs font-medium">Connected domain</span>
                                <select wire:model.live="baseDomainId" class="d-select d-select-bordered w-full" required>
                                    <option value="">Choose a domain with wildcard DNS</option>
                                    @foreach ($wildcardDomains as $baseDomain)
                                        <option value="{{ $baseDomain->id }}">{{ $baseDomain->hostname }} · {{ $baseDomain->providerLabel() }}@if ($baseDomain->provider->value === 'cloudflare') · {{ $baseDomain->cloudflareDomain?->zone?->account?->integration?->label ?: 'Connection unavailable' }}@endif</option>
                                    @endforeach
                                </select>
                            </label>
                            <label class="grid min-w-0 gap-1.5">
                                <span class="text-xs font-medium">Subdomain name</span>
                                <div class="flex min-w-0 items-center rounded-field border border-base-300 bg-base-100 focus-within:outline focus-within:outline-2 focus-within:outline-offset-2">
                                    <input wire:model.live.debounce.200ms="subdomain" class="d-input min-w-0 flex-1 border-0 bg-transparent font-mono focus:outline-none" placeholder="{{ $landing->slug }}" maxlength="63" autocomplete="off" spellcheck="false" aria-describedby="subdomain-preview" required>
                                    <span class="min-w-0 max-w-[60%] truncate pr-3 font-mono text-sm text-base-content/55">.{{ $selectedBaseDomain?->hostname ?? 'your-domain.com' }}</span>
                                </div>
                            </label>
                            @if ($selectedBaseDomain)
                                <div class="rounded-box bg-base-200/60 p-3 text-sm" id="subdomain-preview" aria-live="polite">
                                    <p class="break-all font-mono font-medium">{{ trim($subdomain) !== '' ? Str::lower(trim($subdomain)) : 'your-name' }}.{{ $selectedBaseDomain->hostname }}</p>
                                    <p class="mt-1 text-base-content/60">{{ $selectedBaseDomain->providerLabel() }}@if ($selectedBaseDomain->provider->value === 'cloudflare') · {{ $selectedBaseDomain->cloudflareDomain?->zone?->account?->integration?->label ?: 'Connection unavailable' }} · {{ $selectedBaseDomain->cloudflareDomain?->zone?->account?->name ?: 'Account unavailable' }}@endif · {{ $selectedBaseDomain->statusLabel() }}</p>
                                    <p class="mt-2 text-xs text-base-content/55">Uses *.{{ $selectedBaseDomain->hostname }}. No new DNS record is created. We will check this address in the background before it can serve visitors.</p>
                                </div>
                            @else
                                <p id="subdomain-preview" class="text-xs text-base-content/55">Enter one name, such as “offer”. The selected domain is appended automatically.</p>
                            @endif
                            <button class="d-btn d-btn-outline justify-self-start" type="submit" wire:loading.attr="disabled" wire:target="assignSubdomain">Create and assign subdomain</button>
                        </form>
                    @else
                        <p class="mt-3 text-sm text-base-content/50">Connect a domain with wildcard DNS first to create addresses such as offer.your-domain.com.</p>
                    @endif
                    <a href="{{ route('domains.index', ['create' => 1, 'mode' => 'cloudflare', 'dnsScope' => 'subdomain', 'landing' => $landing->id]) }}" class="mt-3 inline-block text-sm font-medium underline underline-offset-4" wire:navigate>Choose a Cloudflare zone or connect another domain →</a>
                </div>

                @if ($assignedDomains->isNotEmpty())
                    <details class="mt-5 rounded-box border border-base-300 p-4">
                        <summary class="cursor-pointer text-sm font-medium">Move a domain from another landing ({{ $assignedDomains->count() }})</summary>
                        <p class="mt-3 text-sm text-base-content/60">Moving an address changes the landing visitors see. You will review the current and new landing before confirming.</p>
                        <form wire:submit="reviewDomainTransfer" class="mt-3 grid gap-3">
                            <label class="grid gap-1.5">
                                <span class="text-xs font-medium">Domain and current landing</span>
                                <select wire:model="transferDomainId" class="d-select d-select-bordered w-full" required>
                                    <option value="">Choose an assigned domain</option>
                                    @foreach ($assignedDomains as $domain)
                                        <option value="{{ $domain->id }}">{{ $domain->hostname }} — {{ $domain->landing->name }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <button class="d-btn d-btn-outline justify-self-start" type="submit" wire:loading.attr="disabled" wire:target="reviewDomainTransfer">Review move</button>
                        </form>
                    </details>
                @endif

                @if ($domainTransfer !== [])
                    <section class="mt-5 rounded-box border border-warning/40 bg-warning/5 p-4" role="region" aria-labelledby="domain-transfer-title" aria-live="polite">
                        <h3 id="domain-transfer-title" class="font-semibold">Move {{ $domainTransfer['hostname'] }}?</h3>
                        <dl class="mt-3 grid gap-2 text-sm">
                            <div><dt class="inline text-base-content/60">Current landing:</dt> <dd class="inline font-medium">{{ $domainTransfer['from_name'] }}</dd></div>
                            <div><dt class="inline text-base-content/60">New landing:</dt> <dd class="inline font-medium">{{ $domainTransfer['to_name'] }}</dd></div>
                        </dl>
                        <p class="mt-3 text-sm">Visitors to this address will stop seeing {{ $domainTransfer['from_name'] }} and will be routed to {{ $domainTransfer['to_name'] }}. DNS records stay unchanged. The new landing must be published and have an active release to serve content.</p>
                        <div class="mt-4 flex flex-wrap gap-2">
                            <button class="d-btn d-btn-warning d-btn-sm" wire:click="confirmDomainTransfer" wire:loading.attr="disabled" wire:target="confirmDomainTransfer">Confirm move</button>
                            <button class="d-btn d-btn-ghost d-btn-sm" wire:click="cancelDomainTransfer" wire:loading.attr="disabled" wire:target="confirmDomainTransfer">Cancel</button>
                        </div>
                    </section>
                @endif
            </x-ui::panel>
        </div>

        <div class="min-w-0 space-y-6">
            <x-ui::panel title="Settings">
                <form wire:submit="save" class="grid gap-4">
                    <label class="grid gap-1.5"><span class="text-sm font-medium">Name</span><input wire:model="name" class="d-input d-input-bordered w-full" required></label>
                    <label class="grid gap-1.5"><span class="text-sm font-medium">Slug</span><input wire:model="slug" class="d-input d-input-bordered w-full font-mono" required></label>
                    <label class="grid gap-1.5"><span class="text-sm font-medium">Description</span><textarea wire:model="description" class="d-textarea d-textarea-bordered w-full"></textarea></label>
                    <label class="flex cursor-pointer items-center justify-between gap-4 rounded-box border border-base-300 p-3"><span><strong class="block text-sm">Published</strong><small class="text-base-content/55">Pause all domains for this landing.</small></span><input wire:model="isActive" type="checkbox" class="d-toggle d-toggle-primary"></label>
                    <x-landing-tags :suggestions="$tagSuggestions" />
                    <button class="d-btn d-btn-primary" type="submit">Save settings</button>
                </form>
            </x-ui::panel>

            <x-ui::panel title="Danger zone">
                <p class="mb-4 text-sm text-base-content/60">Deleting a landing removes all releases. Domains remain available but become unassigned.</p>
                <button class="d-btn d-btn-error d-btn-outline w-full" wire:click="deleteLanding" wire:confirm="Delete this landing and every stored release?">Delete landing</button>
            </x-ui::panel>
        </div>
    </div>
</x-ui::page>
