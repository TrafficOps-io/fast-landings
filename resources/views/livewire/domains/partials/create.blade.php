<x-ui::panel x-ref="domainCreate" tabindex="-1" class="scroll-mt-6 focus:outline-none" title="Add a domain" description="Choose how this address connects to your server.">
    <x-slot:actions><button type="button" class="d-btn d-btn-ghost d-btn-sm" wire:click="cancelCreate">Cancel</button></x-slot:actions>
    <form wire:submit="create" class="grid gap-6">
        <fieldset><legend class="mb-3 text-xs font-semibold uppercase tracking-wider text-base-content/50">1 · Connection method</legend>
            <div class="grid gap-3 sm:grid-cols-3">
                @foreach ([['system', 'System subdomain', 'Use this installation’s wildcard DNS.'], ['dns', 'Manual DNS', 'Connect an address at any DNS provider.'], ['cloudflare', 'Cloudflare', 'Set up DNS through a saved connection.']] as [$value, $label, $hint])
                    <label class="cursor-pointer rounded-box border p-4 transition {{ $mode === $value ? 'border-primary bg-primary/5' : 'border-base-300 hover:border-primary/40' }}">
                        <span class="flex items-center gap-2"><input type="radio" wire:model.live="mode" value="{{ $value }}" class="d-radio d-radio-primary d-radio-sm"><strong class="text-sm">{{ $label }}</strong></span>
                        <span class="mt-2 block text-xs leading-5 text-base-content/55">{{ $hint }}</span>
                    </label>
                @endforeach
            </div>
        </fieldset>
        @if ($mode !== 'system')
            <fieldset><legend class="mb-3 text-xs font-semibold uppercase tracking-wider text-base-content/50">Connection scope</legend>
                <div class="grid gap-3 sm:grid-cols-3">
                    @foreach ([['exact', 'Exact hostname', 'One address, such as example.com.'], ['subdomain', 'One subdomain', 'Choose a domain and enter a subdomain.'], ['wildcard', 'Domain + wildcard', 'Root address and DNS for its subdomains.']] as [$value, $label, $hint])
                        <label class="cursor-pointer rounded-box border p-3 {{ $dnsScope === $value ? 'border-primary bg-primary/5' : 'border-base-300' }}"><span class="flex items-center gap-2"><input type="radio" wire:model.live="dnsScope" value="{{ $value }}" class="d-radio d-radio-primary d-radio-sm"><strong class="text-sm">{{ $label }}</strong></span><span class="mt-2 block text-xs text-base-content/55">{{ $hint }}</span></label>
                    @endforeach
                </div>
                @if ($dnsScope === 'wildcard')<p class="mt-3 text-xs leading-5 text-base-content/60">Creates DNS for the root domain and *.domain. Assign the root address now or add named subdomains to landings later. Unknown subdomains do not serve a landing automatically.</p>@endif
            </fieldset>
        @endif
        <div class="grid gap-6 lg:grid-cols-2">
            <fieldset class="min-w-0 space-y-4"><legend class="mb-3 text-xs font-semibold uppercase tracking-wider text-base-content/50">2 · Domain address</legend>
                @if ($mode === 'system')
                    <label class="grid gap-1.5"><span class="text-sm font-medium">{{ app()->environment('local') ? 'Subdomain or local hostname' : 'Subdomain' }}</span>
                        <div class="flex min-w-0 items-center overflow-hidden rounded-field border border-base-300 bg-base-100"><input wire:model.live.debounce.300ms="subdomain" class="d-input min-w-0 w-full border-0 focus:outline-none" placeholder="offer" maxlength="253" required><span class="shrink-0 pr-3 text-sm text-base-content/50">.{{ $installation->domain }}</span></div>
                        @if (app()->environment('local'))<span class="text-xs leading-5 text-base-content/55">A label such as <code>demo</code> uses the suffix above. You can also enter an exact local address such as <code>demo.localhost</code>. The development port is added automatically.</span>@endif
                        @error('subdomain')<span class="text-xs text-error">{{ $message }}</span>@enderror
                    </label>
                    <p class="text-xs leading-5 text-base-content/60">Uses your server’s existing wildcard DNS. Check Server &amp; DNS setup below if this is your first system subdomain.</p>
                @elseif ($mode === 'cloudflare')
                    <label class="grid gap-1.5"><span class="text-sm font-medium">Cloudflare connection</span>
                        <select wire:model.live="integrationId" class="d-select d-select-bordered w-full" required><option value="">Choose a connection</option>@foreach ($integrations as $integration)<option value="{{ $integration->id }}">{{ $integration->label ?: 'Cloudflare connection' }} · {{ $integration->accounts->pluck('name')->join(', ') }}{{ $integration->status->value !== 'active' ? ' · '.$integration->status->value : '' }}</option>@endforeach</select>
                        @error('integrationId')<span class="text-xs text-error">{{ $message }}</span>@enderror
                    </label>
                    @if ($integrations->isEmpty())<p class="text-sm text-base-content/60">No Cloudflare connections yet. {{ auth()->user()->isAdministrator() ? 'Connect an API token to load its accounts and zones.' : 'Ask an administrator to connect a Cloudflare API token, or use Manual DNS.' }}</p>@endif
                    @if (auth()->user()->isAdministrator())<a href="{{ route('cloudflare.index', array_filter(['landing' => $landingId])) }}" wire:navigate class="text-sm text-primary underline">Manage Cloudflare connections ↗</a>@endif
                    @if ($selectedIntegration && ($selectedIntegration->status->value === 'invalid' || in_array($selectedIntegration->token_status, ['invalid', 'expired', 'revoked', 'disabled']) || $selectedIntegration->token_expires_at?->isPast()))<p class="text-sm text-error">This token is invalid or expired. Update the connection or refresh its status before adding a domain.</p>@endif
                    @if ($integrationId !== '')
                        <div class="space-y-3" wire:key="zones-{{ $integrationId }}">
                            <label class="grid gap-1.5"><span class="text-sm font-medium">Zone <span class="font-normal text-base-content/50">· {{ $zones->count() }} available</span></span>
                                <select wire:model.live="zoneId" class="d-select d-select-bordered w-full" required><option value="">Choose a zone</option>
                                    @foreach ($integrations->firstWhere('id', $integrationId)?->accounts ?? [] as $account)
                                        <optgroup label="{{ $account->name }}">
                                            @foreach ($account->zones->whereIn('id', $zones->pluck('id'))->sortBy('name') as $zone)<option value="{{ $zone->id }}">{{ $zone->name }}</option>@endforeach
                                        </optgroup>
                                    @endforeach
                                </select>@error('zoneId')<span class="text-xs text-error">{{ $message }}</span>@enderror
                            </label>
                            <div class="flex flex-wrap items-center justify-between gap-2"><p class="text-xs text-base-content/55">Active, unpaused zones accessible to this token.</p><button type="button" class="d-btn d-btn-ghost d-btn-xs" wire:click="refetchCloudflareZones" wire:loading.attr="disabled" wire:target="refetchCloudflareZones"><span wire:loading.remove wire:target="refetchCloudflareZones">Refresh zones</span><span wire:loading wire:target="refetchCloudflareZones">Refreshing…</span></button></div>
                            @if ($zones->isEmpty())<p class="text-xs leading-5 text-warning">No active zones available. Check zone access, nameserver activation, and token permissions in Cloudflare, then refresh.</p>@endif
                            @error('zones')<p class="text-xs text-error" role="alert">{{ $message }}</p>@enderror
                            @if ($zonesMessage)<p class="text-xs text-success" role="status">{{ $zonesMessage }}</p>@endif
                        </div>
                    @endif
                    @if ($dnsScope === 'subdomain')
                        <label class="grid gap-1.5"><span class="text-sm font-medium">Subdomain</span><input wire:model.live.debounce.250ms="hostnameLabel" class="d-input d-input-bordered w-full" placeholder="offer" maxlength="63" required><span class="break-all text-xs text-base-content/55">Address: {{ $hostnameLabel ?: 'offer' }}.{{ $zones->firstWhere('id', $zoneId)?->name ?? 'select-a-zone' }}</span>@error('hostnameLabel')<span class="text-xs text-error">{{ $message }}</span>@enderror</label>
                    @else
                        <label class="grid gap-1.5"><span class="text-sm font-medium">{{ $dnsScope === 'wildcard' ? 'Base domain' : 'Exact hostname' }}</span><input wire:model="hostname" class="d-input d-input-bordered w-full" placeholder="example.com" maxlength="253"><span class="text-xs text-base-content/55">{{ $dnsScope === 'wildcard' ? 'Enter the base name without *. We configure both the base and its wildcard record.' : 'Use the zone’s root domain or a specific hostname within it.' }}</span></label>
                    @endif
                    @error('hostname')<span class="text-xs text-error">{{ $message }}</span>@enderror
                @else
                    @if ($dnsScope === 'subdomain')
                        <label class="grid gap-1.5"><span class="text-sm font-medium">Base domain</span><input wire:model.live.debounce.250ms="baseHostname" class="d-input d-input-bordered w-full" placeholder="example.com" maxlength="253" required>@error('baseHostname')<span class="text-xs text-error">{{ $message }}</span>@enderror</label>
                        <label class="grid gap-1.5"><span class="text-sm font-medium">Subdomain</span><input wire:model.live.debounce.250ms="hostnameLabel" class="d-input d-input-bordered w-full" placeholder="offer" maxlength="63" required><span class="break-all text-xs text-base-content/55">Address: {{ $hostnameLabel ?: 'offer' }}.{{ $baseHostname ?: 'example.com' }}</span>@error('hostnameLabel')<span class="text-xs text-error">{{ $message }}</span>@enderror</label>
                    @else
                        <label class="grid gap-1.5"><span class="text-sm font-medium">{{ $dnsScope === 'wildcard' ? 'Base domain' : 'Exact hostname' }}</span><input wire:model="hostname" class="d-input d-input-bordered w-full" placeholder="example.com" maxlength="253" required><span class="text-xs leading-5 text-base-content/55">{{ $dnsScope === 'wildcard' ? 'Enter the base domain without *. We show the root and wildcard records to create.' : 'One domain you own, without https://, a path, port, or wildcard.' }}</span></label>
                    @endif
                    @error('hostname')<span class="text-xs text-error">{{ $message }}</span>@enderror
                    <p class="text-xs leading-5 text-base-content/60">After adding the domain, copy the exact DNS record into your provider. Verification continues automatically in the background.</p>
                @endif
                @if ($mode !== 'system')<div class="rounded-box bg-base-200 px-4 py-3 text-xs leading-5"><span class="text-base-content/55">Server target</span><code class="mt-1 block break-all">{{ \App\Support\DnsTarget::recordType($installation->origin_target) }} → {{ $installation->origin_target }}</code>@if ($mode === 'cloudflare')<p class="mt-2 text-base-content/60">Creates a DNS-only record. Matching records are kept; conflicting records are reported for review.</p>@endif</div>@endif
            </fieldset>
            <fieldset class="min-w-0"><legend class="mb-3 text-xs font-semibold uppercase tracking-wider text-base-content/50">3 · Landing <span class="normal-case tracking-normal font-normal">(optional)</span></legend>
                <label class="grid gap-1.5"><span class="text-sm font-medium">Assign to</span><select wire:model.live="landingId" class="d-select d-select-bordered w-full"><option value="">Leave unassigned — choose later</option>@foreach ($landings as $landing)<option value="{{ $landing->id }}">{{ $landing->name }}{{ ! $landing->is_active ? ' · paused' : (! $landing->activeRelease ? ' · no active release' : '') }}</option>@endforeach</select>@error('landingId')<span class="text-xs text-error">{{ $message }}</span>@enderror</label>
                <p class="mt-3 text-sm leading-6 text-base-content/60">{{ $dnsScope === 'wildcard' && $mode !== 'system' ? 'Assigning here uses the root address only. Add and assign subdomains from the landing’s domain settings.' : ($landingId === '' ? 'The domain stays in your registry without serving content. Assign it to a landing when you are ready.' : 'This hostname will serve the selected landing once DNS is connected and the landing has a published release.') }}</p>
                @if ($landings->isEmpty())<a href="{{ route('landings.index') }}" wire:navigate class="mt-3 inline-block text-sm text-primary underline">Create a landing</a>@endif
                <div class="mt-5 border-t border-base-300 pt-4 text-xs leading-5 text-base-content/55">One landing can have several domains. You can move an address to another landing later without changing its DNS.</div>
            </fieldset>
        </div>
        @error('domain')<p class="text-sm text-error" role="alert">{{ $message }}</p>@enderror
        <div class="flex flex-wrap items-center gap-3 border-t border-base-300 pt-4"><button class="d-btn d-btn-primary" type="submit" wire:loading.attr="disabled" wire:target="create,mode,integrationId,zoneId,refetchCloudflareZones" @disabled($mode === 'cloudflare' && ($integrationId === '' || $zoneId === ''))><span wire:loading.remove wire:target="create">Add domain</span><span wire:loading wire:target="create">Adding domain…</span></button><button type="button" class="d-btn d-btn-ghost" wire:click="cancelCreate">Cancel</button></div>
    </form>
</x-ui::panel>
