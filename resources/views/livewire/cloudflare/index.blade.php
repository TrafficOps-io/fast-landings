<x-ui::page width="max-w-6xl">
    <x-ui::page-header title="Cloudflare connections" eyebrow="Instance settings" :back="route('domains.index', array_filter(['landing' => $landingId]))" back-label="Domains">
        <x-slot:actions>
            <button type="button" wire:click="add" wire:loading.attr="disabled" class="d-btn d-btn-primary">Add connection</button>
        </x-slot:actions>
    </x-ui::page-header>

    <x-ui::feedback />

    <p class="max-w-3xl text-sm leading-6 text-base-content/65">Connect the Cloudflare accounts you use for landing pages. Each connection stores one API token and lists the accounts and zones it can access. Connections are shared by this installation and managed by administrators.</p>

    @if ($showForm)
        <x-ui::panel :title="$editingIntegrationId ? 'Edit connection' : 'Connect Cloudflare'" description="Use a descriptive name to distinguish tokens for different accounts or projects.">
            <form x-data="{ apiToken: '' }" wire:submit="save(apiToken)" wire:key="cloudflare-form-{{ $formVersion }}" class="grid gap-5 lg:grid-cols-2">
                <div class="space-y-4">
                    <label class="grid gap-1.5">
                        <span class="text-sm font-medium">Connection name</span>
                        <input wire:model="label" type="text" required maxlength="120" placeholder="e.g. Agency — client sites" class="d-input d-input-bordered w-full @error('label') d-input-error @enderror">
                        <span class="text-xs text-base-content/55">A name for this saved token. Actual Cloudflare account names appear after connecting.</span>
                        @error('label') <span class="text-sm text-error" role="alert">{{ $message }}</span> @enderror
                    </label>
                    <label class="grid gap-1.5">
                        <span class="text-sm font-medium">{{ $editingIntegrationId ? 'Replacement API token (optional)' : 'Cloudflare API token' }}</span>
                        <input x-model="apiToken" type="password" maxlength="4096" autocomplete="new-password" spellcheck="false" @required(! $editingIntegrationId) class="d-input d-input-bordered w-full @error('apiToken') d-input-error @enderror">
                        <span class="text-xs text-base-content/55">Stored encrypted on your server and never displayed. @if ($editingIntegrationId) Leave blank to keep the saved token. A replacement must include all zones used by this connection. @endif</span>
                        @error('apiToken') <span class="text-sm text-error" role="alert">{{ $message }}</span> @enderror
                    </label>
                </div>
                <div class="rounded-box border border-base-300 bg-base-200/40 p-4">
                    <h3 class="text-sm font-semibold">Create a token for your zones</h3>
                    <ol class="mt-3 list-decimal space-y-2 pl-4 text-xs leading-5 text-base-content/70">
                        <li>Open <a href="https://dash.cloudflare.com/profile/api-tokens" target="_blank" rel="noopener noreferrer" class="underline">Cloudflare API Tokens ↗</a> and create a custom API token.</li>
                        <li>Add <strong>Zone → Zone → Read</strong> and <strong>Zone → DNS → Edit</strong>.</li>
                        <li>Under Zone Resources, include the zones you will use. Add another connection for zones accessed with a different token.</li>
                        <li>Paste the token here. We verify it and load the available accounts and zones.</li>
                    </ol>
                    <p class="mt-3 text-xs leading-5 text-base-content/55">Connecting or refreshing reads Cloudflare configuration. DNS records are configured when you add a Cloudflare domain.</p>
                </div>
                <div class="flex flex-wrap items-center justify-end gap-2 lg:col-span-2">
                    <button type="button" wire:click="cancelEdit" wire:loading.attr="disabled" class="d-btn d-btn-ghost">Cancel</button>
                    <button type="submit" wire:loading.attr="disabled" class="d-btn d-btn-primary">
                        <span wire:loading wire:target="save" class="d-loading d-loading-spinner d-loading-sm"></span>
                        {{ $editingIntegrationId ? 'Save connection' : 'Connect and load zones' }}
                    </button>
                </div>
            </form>
        </x-ui::panel>
    @endif

    @if ($integrations->isEmpty())
        <x-ui::panel>
            <x-ui::empty-state title="No Cloudflare connections yet" description="Connect a token to see its accounts and zones here. You can also add a domain using manual DNS from the Domains page." />
        </x-ui::panel>
    @else
        <div class="space-y-5">
            @foreach ($integrations as $integration)
                @php
                    $allZones = $integration->accounts->flatMap->zones;
                    $domainCount = $allZones->sum('domains_count');
                    $connectionStatus = match ($integration->status->value) {
                        'active' => 'Connected',
                        'invalid' => 'Token invalid',
                        'degraded' => 'Check permissions',
                        'unreachable' => 'Cloudflare unreachable',
                        default => 'Not verified',
                    };
                    $connectionTone = $integration->status->value === 'active' ? 'success' : ($integration->status->value === 'invalid' ? 'error' : 'warning');
                @endphp
                <section class="overflow-hidden rounded-box border border-base-300 bg-base-100" wire:key="cloudflare-{{ $integration->id }}" aria-label="{{ $integration->label ?? 'Cloudflare connection' }}">
                    <div class="space-y-4 p-5 sm:p-6">
                        <div class="flex flex-wrap items-start justify-between gap-4">
                            <div class="min-w-0 space-y-2">
                                <div class="flex flex-wrap items-center gap-2">
                                    <h2 class="break-words text-base font-semibold">{{ $integration->label ?? 'Unnamed connection' }}</h2>
                                    <x-ui::status-badge :label="$connectionStatus" :tone="$connectionTone" />
                                </div>
                                <p class="text-xs text-base-content/60">{{ $integration->accounts->count() }} {{ Str::plural('account', $integration->accounts->count()) }} · {{ $allZones->count() }} {{ Str::plural('zone', $allZones->count()) }} · {{ $domainCount }} linked {{ Str::plural('domain', $domainCount) }}</p>
                                <p class="text-xs text-base-content/50">{{ $integration->last_synced_at ? 'Last synced '.$integration->last_synced_at->diffForHumans() : 'Zones have not been synced yet' }}@if ($integration->token_expires_at) · Token {{ $integration->token_expires_at->isPast() ? 'expired' : 'expires' }} {{ $integration->token_expires_at->format('M j, Y') }}@endif</p>
                            </div>
                            <div class="flex flex-wrap items-center gap-2">
                                <button type="button" wire:click="sync('{{ $integration->id }}')" wire:loading.attr="disabled" class="d-btn d-btn-outline d-btn-sm" aria-label="Refresh zones for {{ $integration->label }}">
                                    <span wire:loading wire:target="sync('{{ $integration->id }}')" class="d-loading d-loading-spinner d-loading-xs"></span>
                                    Refresh zones
                                </button>
                                <button type="button" wire:click="edit('{{ $integration->id }}')" wire:loading.attr="disabled" class="d-btn d-btn-ghost d-btn-sm" aria-label="Edit {{ $integration->label }}">Edit</button>
                            </div>
                        </div>
                        @error('connection.'.$integration->id)
                            <p class="rounded-box border border-error/25 bg-error/5 p-3 text-sm text-error" role="alert">{{ $message }}</p>
                        @enderror
                        @if ($integration->status->value !== 'active')
                            <p class="text-sm text-base-content/65">{{ match ($integration->status->value) {
                                'invalid' => 'Edit this connection to replace an expired or revoked token, then refresh its zones.',
                                'degraded' => 'Review the token’s zone permissions and resources in Cloudflare, then refresh.',
                                'unreachable' => 'Check this server’s internet connection, then try refreshing again.',
                                default => 'Refresh this connection to verify its token and available zones.',
                            } }}</p>
                        @endif
                    </div>

                    @foreach ($integration->accounts as $account)
                        <div class="border-t border-base-300" wire:key="account-{{ $account->id }}">
                            <div class="flex flex-wrap items-center gap-x-3 gap-y-1 bg-base-200/40 px-5 py-3 sm:px-6">
                                <h3 class="text-sm font-semibold">{{ $account->name }}</h3>
                                <span class="text-xs text-base-content/50">Cloudflare account</span>
                                <span class="break-all font-mono text-[10px] text-base-content/45">{{ $account->cloudflare_id }}</span>
                                @if ($account->status === 'inaccessible') <x-ui::status-badge label="Access lost" tone="warning" /> @endif
                            </div>
                            <ul class="divide-y divide-base-300">
                                @foreach ($account->zones as $zone)
                                    @php
                                        $available = $integration->status->value === 'active' && $account->status === 'active' && $zone->status === 'active' && ! $zone->paused;
                                        $zoneLabel = $zone->paused ? 'Paused' : match ($zone->status) {
                                            'active' => 'Active', 'pending' => 'Pending activation', 'inaccessible' => 'Access lost',
                                            default => Str::headline($zone->status),
                                        };
                                    @endphp
                                    <li class="flex flex-wrap items-center justify-between gap-3 px-5 py-4 sm:px-6" wire:key="zone-{{ $zone->id }}">
                                        <div class="min-w-0 space-y-1.5">
                                            <div class="flex flex-wrap items-center gap-2">
                                                <span class="break-all text-sm font-medium">{{ $zone->name }}</span>
                                                <x-ui::status-badge :label="$zoneLabel" :tone="$available ? 'success' : 'warning'" />
                                                @if ($zone->domains_count) <span class="text-xs text-base-content/50">{{ $zone->domains_count }} linked {{ Str::plural('domain', $zone->domains_count) }}</span> @endif
                                            </div>
                                            @if (! $available)
                                                <p class="text-xs text-base-content/60">{{ match (true) {
                                                    $zone->status === 'inaccessible' || $account->status === 'inaccessible' => 'Include this zone in the token’s resources, then refresh.',
                                                    $zone->paused => 'Resume this zone in Cloudflare before adding domains.',
                                                    $zone->status === 'pending' => 'Finish the nameserver setup in Cloudflare, then refresh.',
                                                    $integration->status->value !== 'active' => 'Restore this connection before adding domains.',
                                                    default => 'Activate this zone in Cloudflare, then refresh.',
                                                } }}</p>
                                                @if ($zone->status === 'pending' && $zone->name_servers)
                                                    <p class="break-all font-mono text-xs text-base-content/55">{{ implode(' · ', $zone->name_servers) }}</p>
                                                @endif
                                            @endif
                                        </div>
                                        @if ($available)
                                            <a href="{{ route('domains.index', array_filter(['create' => 1, 'mode' => 'cloudflare', 'integration' => $integration->id, 'zone' => $zone->id, 'landing' => $landingId])) }}" wire:navigate class="d-btn d-btn-outline d-btn-sm" aria-label="Add domain in {{ $zone->name }}">Add domain</a>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endforeach

                    @if ($integration->accounts->isEmpty())
                        <p class="border-t border-base-300 px-5 py-4 text-sm text-base-content/60 sm:px-6">No accounts or zones are available. Check the token’s zone resources and refresh.</p>
                    @endif

                    <div class="flex flex-wrap items-center justify-between gap-3 border-t border-base-300 px-5 py-3 sm:px-6">
                        <p class="max-w-2xl text-xs text-base-content/55">{{ $domainCount ? 'This connection is in use. Edit to rotate its token, or remove its domains before disconnecting.' : 'Disconnecting removes the saved token from this installation. Cloudflare zones and DNS records stay in place.' }}</p>
                        <button type="button" wire:click="disconnect('{{ $integration->id }}')" wire:confirm="Disconnect this Cloudflare connection and remove its saved API token? Cloudflare zones and DNS records will stay in place." wire:loading.attr="disabled" @disabled($domainCount > 0) class="d-btn d-btn-ghost d-btn-sm text-error" aria-label="Disconnect {{ $integration->label }}">Disconnect</button>
                    </div>
                </section>
            @endforeach
        </div>
    @endif
</x-ui::page>
