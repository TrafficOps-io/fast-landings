<x-ui::panel x-ref="domainDetails" tabindex="-1" :title="$domain->hostname" description="Connection details" class="border-primary/30 scroll-mt-6 focus:outline-none" wire:key="domain-details-{{ $domain->id }}">
    <x-slot:actions><button type="button" class="d-btn d-btn-ghost d-btn-sm" wire:click="closeDetails" aria-label="Close domain details">Close</button></x-slot:actions>
    <div class="grid gap-6 lg:grid-cols-2">
        <section class="min-w-0 space-y-4" aria-label="DNS connection">
            <div class="flex flex-wrap items-center gap-2"><x-ui::status-badge :label="$domain->statusLabel()" :tone="$domain->statusTone()" /><span class="text-xs text-base-content/55">{{ $domain->providerLabel() }}</span></div>
            <p class="text-sm leading-6 text-base-content/65">{{ $domain->statusDescription() }}</p>
            @if ($domain->last_error)<div class="rounded-box border border-warning/30 bg-warning/5 p-3 text-sm leading-6" role="status">{{ \App\Support\DomainError::display($domain->last_error) }}</div>@endif
            @if ($domain->parentDomain)<p class="text-xs leading-5 text-base-content/60">Uses wildcard DNS from <button type="button" wire:click="selectDomain('{{ $domain->parent_domain_id }}')" class="text-primary underline">{{ $domain->parentDomain->hostname }}</button>. This hostname is checked separately because specific DNS records can override the wildcard.</p>@elseif ($domain->dns_scope === 'wildcard')<p class="text-xs leading-5 text-base-content/60">Covers the root address and wildcard subdomains. Each subdomain must be added and assigned separately before it serves content.</p>@endif
            @if ($domain->provider->value !== 'system')
                <div class="space-y-1 text-xs leading-5 text-base-content/55" role="status">
                    @if ($domain->verification_requested_at)
                        <p>Connection check in progress…</p>
                        @if ($domain->verification_requested_at->lt(now()->subMinutes(2)))<p class="text-warning">This is taking longer than usual. Make sure the queue worker and scheduler are running on your server. You can retry when the current job’s lease expires.</p>@endif
                    @endif
                    <p>{{ $domain->last_checked_at ? 'Last checked '.$domain->last_checked_at->diffForHumans() : 'Waiting for the first check' }}@if ($domain->next_check_at && ! $domain->verification_requested_at) · Next check {{ $domain->next_check_at->diffForHumans() }}@endif</p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <button type="button" class="d-btn d-btn-outline d-btn-sm" wire:click="verify('{{ $domain->id }}')" wire:loading.attr="disabled" @disabled($domain->verification_requested_at?->gt(now()->subSeconds(max(300, config('fast-landings.domain_checks.request_lease')))))>Check now</button>
                    @if ($domain->provider->value === 'cloudflare' && ! $domain->parent_domain_id)<button type="button" class="d-btn d-btn-ghost d-btn-sm" wire:click="provision('{{ $domain->id }}')" wire:loading.attr="disabled" @disabled($domain->verification_requested_at?->gt(now()->subSeconds(max(300, config('fast-landings.domain_checks.request_lease')))))>Retry DNS setup</button>@endif
                </div>
            @endif
            @if ($domain->provider->value === 'cloudflare')
                <dl class="grid gap-3 rounded-box bg-base-200 p-4 text-sm sm:grid-cols-2">
                    <div><dt class="text-xs text-base-content/55">Connection</dt><dd class="mt-1 break-words font-medium">{{ $domain->dnsSource()->cloudflareDomain?->zone?->account?->integration?->label ?: 'Unavailable' }}</dd></div>
                    <div><dt class="text-xs text-base-content/55">Cloudflare account</dt><dd class="mt-1 break-words">{{ $domain->dnsSource()->cloudflareDomain?->zone?->account?->name ?: 'Unavailable' }}</dd></div>
                    <div><dt class="text-xs text-base-content/55">Zone</dt><dd class="mt-1 break-all">{{ $domain->dnsSource()->cloudflareDomain?->zone?->name ?: 'Unavailable' }}</dd></div>
                    <div><dt class="text-xs text-base-content/55">Expected DNS</dt><dd class="mt-1 break-all font-mono text-xs">{{ $domain->dnsRecordType() }} → {{ $domain->dns_target }}</dd></div>
                </dl>
                <p class="text-xs leading-5 text-base-content/55">@if ($domain->dns_scope === 'wildcard')Required records: {{ implode(' and ', $domain->dnsRecordNames()) }}. @endif DNS setup runs in the background. Existing matching records are adopted; conflicting records are kept for you to review. New records use DNS only.</p>
                @if (auth()->user()->isAdministrator())<a href="{{ route('cloudflare.index') }}" wire:navigate class="inline-block text-xs text-primary underline">Manage connection and token permissions</a>@endif
            @elseif ($domain->provider->value === 'dns' && ! $domain->parent_domain_id)
                <div class="space-y-3 rounded-box bg-base-200 p-4 text-sm">
                    <h3 class="font-semibold">DNS setup instructions</h3>
                    <p class="text-xs leading-5 text-base-content/60">At your DNS provider, add {{ $domain->dns_scope === 'wildcard' ? 'both records below, each using the same type and target' : 'this record' }}. Review conflicting A, AAAA, or CNAME records for this exact hostname before replacing them.</p>
                    <dl class="grid gap-3 rounded-box bg-base-100 p-3 sm:grid-cols-2">
                        <div><dt class="text-xs text-base-content/55">Type</dt><dd class="mt-1 font-mono">{{ $domain->dnsRecordType() }}</dd></div>
                        <div><dt class="text-xs text-base-content/55">TTL</dt><dd class="mt-1">Auto / default</dd></div>
                        <div><dt class="text-xs text-base-content/55">Name / host</dt><dd class="mt-1 break-all font-mono text-xs">@foreach ($domain->dnsRecordNames() as $recordName)<span class="block">{{ $recordName }}</span>@endforeach</dd></div>
                        <div><dt class="text-xs text-base-content/55">Target / value</dt><dd class="mt-1 break-all font-mono text-xs">{{ $domain->dns_target }}</dd></div>
                    </dl>
                    <p class="text-xs leading-5 text-base-content/65">If your provider appends the zone name automatically, enter only the subdomain in Name, or @ for the root domain.@if ($domain->dns_scope === 'wildcard') Use * for the wildcard record in that same zone.@endif</p>
                    @if ($domain->dnsRecordType() === 'CNAME')<p class="text-xs leading-5 text-base-content/65">For a root domain, use ALIAS, ANAME, or CNAME flattening with the same target.</p>@else<p class="text-xs leading-5 text-base-content/65">This record points to your server’s public {{ $domain->dnsRecordType() === 'A' ? 'IPv4' : 'IPv6' }} address and works for both root domains and subdomains.</p>@endif
                    <p class="text-xs leading-5 text-base-content/65">Use DNS only (proxy disabled) for public DNS verification. Checks continue automatically, even after you close this page.</p>
                </div>
            @elseif ($domain->provider->value === 'system')
                <div class="rounded-box bg-base-200 p-4 text-xs leading-6 text-base-content/65">This address uses your installation’s wildcard DNS:<code class="mt-2 block break-all">*.{{ $installation->domain }} → {{ $domain->dns_target }}</code><p class="mt-2">System addresses are enabled locally without a public DNS check. The server administrator manages wildcard DNS and HTTPS.</p></div>
            @endif
        </section>
        <section class="min-w-0 space-y-4" aria-label="Landing assignment">
            <div class="rounded-box border border-base-300 p-4">
                <h3 class="text-xs font-semibold uppercase tracking-wider text-base-content/50">Serves landing</h3>
                @if ($domain->landing)
                    <a href="{{ route('landings.show', $domain->landing) }}" wire:navigate class="mt-2 block break-words font-semibold hover:text-primary">{{ $domain->landing->name }} ↗</a>
                    <p class="mt-1 text-xs text-base-content/55">{{ $domain->is_primary ? 'Primary address · used by Open landing' : 'Additional address' }}</p>
                    @if (! $domain->landing->is_active)<p class="mt-3 text-sm text-warning">This landing is paused. Publish it to serve content.</p>@elseif (! $domain->landing->activeRelease)<p class="mt-3 text-sm text-warning">This landing needs an active release before it can serve content.</p>@elseif ($domain->status->value === 'active')<a href="{{ $domain->publicUrl() }}" target="_blank" rel="noopener noreferrer" class="d-btn d-btn-outline d-btn-sm mt-4">Open address ↗</a>@else<p class="mt-3 text-sm text-base-content/60">The assignment is saved. Requests will be served after DNS is verified.</p>@endif
                @else
                    <p class="mt-2 font-medium">Unassigned</p><p class="mt-2 text-sm leading-6 text-base-content/60">DNS can be connected now. This address will not serve content until you assign a published landing.</p>
                @endif
                @unless ($showAssignment || $showRemove)<button type="button" class="d-btn d-btn-primary d-btn-sm mt-4" wire:click="beginAssignment('{{ $domain->id }}')">{{ $domain->landing ? 'Change landing' : 'Assign a landing' }}</button>@endunless
            </div>
            @if ($showAssignment)
                <div class="space-y-4 rounded-box border border-primary/30 bg-primary/5 p-4">
                    @if ($assignmentReview)
                        <h3 class="font-semibold">Review assignment</h3>
                        <p class="break-all text-sm font-medium">{{ $domain->hostname }}</p>
                        <dl class="grid gap-3 text-sm sm:grid-cols-2"><div><dt class="text-xs text-base-content/55">From</dt><dd class="mt-1 break-words">{{ $reviewFrom?->name ?? 'Unassigned' }}</dd></div><div><dt class="text-xs text-base-content/55">To</dt><dd class="mt-1 break-words font-semibold">{{ $reviewTo?->name ?? 'Unassigned' }}</dd></div></dl>
                        <p class="text-sm leading-6 text-base-content/65">{{ $reviewTo ? 'New requests to this hostname will use the selected landing when it is published and DNS is connected.' : 'This hostname will stop serving the current landing.' }} DNS records stay unchanged.</p>
                        @if ($reviewTo && (! $reviewTo->is_active || ! $reviewTo->activeRelease))<p class="text-sm text-warning">The selected landing is paused or has no active release. It will not serve content yet.</p>@endif
                        <div class="flex flex-wrap gap-2"><button type="button" class="d-btn d-btn-primary d-btn-sm" wire:click="confirmAssignment" wire:loading.attr="disabled">Confirm assignment</button><button type="button" class="d-btn d-btn-ghost d-btn-sm" wire:click="backToAssignment">Back</button></div>
                    @else
                        <form wire:submit="reviewAssignment" class="space-y-3"><label class="grid gap-1.5"><span class="text-sm font-medium">New landing</span><select wire:model="assignmentLandingId" class="d-select d-select-bordered w-full"><option value="">Unassigned — serve no landing</option>@foreach ($landings as $landing)<option value="{{ $landing->id }}">{{ $landing->name }}{{ ! $landing->is_active ? ' · paused' : (! $landing->activeRelease ? ' · no active release' : '') }}</option>@endforeach</select>@error('assignmentLandingId')<span class="text-xs text-error">{{ $message }}</span>@enderror</label><button type="submit" class="d-btn d-btn-primary d-btn-sm">Review change</button></form>
                    @endif
                    @error('assignmentId')<p class="text-sm text-error" role="alert">{{ $message }}</p>@enderror
                    <button type="button" class="text-xs text-base-content/60 underline" wire:click="cancelAssignment">Cancel assignment</button>
                </div>
            @endif
            <p class="text-xs leading-5 text-base-content/55">A primary address is the default link for a landing. Other assigned domains continue to serve the same content; they do not redirect.</p>
            @if ($showRemove)
                <div class="space-y-3 rounded-box border border-error/30 bg-error/5 p-4">
                    <h3 class="font-semibold">Remove {{ $domain->hostname }}?</h3><p class="text-sm leading-6 text-base-content/65">This removes the address from Fast Landings and stops it from serving content. Your landing and its releases stay available.</p>
                    @if ($domain->provider->value === 'cloudflare' && ! $domain->parent_domain_id)<label class="flex items-start gap-3 text-sm"><input type="checkbox" wire:model="cleanupDns" class="d-checkbox d-checkbox-sm mt-0.5"><span>Also delete DNS records created by Fast Landings<small class="mt-1 block text-base-content/55">Existing records adopted from Cloudflare are always kept. Leave unchecked to keep all DNS records.</small></span></label>@else<p class="text-xs text-base-content/60">DNS records at your provider will stay unchanged.</p>@endif
                    <div class="flex flex-wrap gap-2"><button type="button" class="d-btn d-btn-error d-btn-sm" wire:click="remove" wire:loading.attr="disabled">Remove domain</button><button type="button" class="d-btn d-btn-ghost d-btn-sm" wire:click="$set('showRemove', false)">Cancel</button></div>
                </div>
            @elseif (! $showAssignment)<button type="button" class="text-xs text-error underline" wire:click="beginRemove('{{ $domain->id }}')">Remove from registry</button>@endif
            @error('domain')<p class="text-sm text-error" role="alert">{{ $message }}</p>@enderror
            @error('operation')<p class="text-sm text-error" role="alert">{{ $message }}</p>@enderror
        </section>
    </div>
</x-ui::panel>
