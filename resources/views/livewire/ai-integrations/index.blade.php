<x-ui::page width="max-w-5xl">
    <x-ui::page-header title="AI integrations" eyebrow="Instance settings" />

    <x-ui::feedback />

    <p class="text-sm text-base-content/60">Save provider credentials for this Fast Landings instance. AI features and connection checks are not enabled yet.</p>

    <x-ui::panel
        :title="$editingIntegrationId ? 'Edit integration' : 'Add integration'"
        description="Give each integration a name so you can distinguish multiple accounts or keys from the same provider."
    >
        <form x-data="{ apiKey: '' }" wire:submit="save(apiKey)" class="grid gap-4 sm:grid-cols-2" wire:key="integration-form-{{ $formVersion }}">
            <label class="grid gap-1.5">
                <span class="text-sm font-medium">Name</span>
                <input wire:model="name" type="text" required maxlength="120" placeholder="e.g. Production OpenRouter" class="d-input d-input-bordered w-full @error('name') d-input-error @enderror">
                @error('name') <span class="text-sm text-error" role="alert">{{ $message }}</span> @enderror
            </label>

            <label class="grid gap-1.5">
                <span class="text-sm font-medium">Provider</span>
                <select wire:model.live="provider" class="d-select d-select-bordered w-full @error('provider') d-select-error @enderror">
                    @foreach ($providers as $providerOption)
                        <option value="{{ $providerOption->value }}">{{ $providerOption->label() }}</option>
                    @endforeach
                </select>
                @error('provider') <span class="text-sm text-error" role="alert">{{ $message }}</span> @enderror
            </label>

            <label class="grid gap-1.5 sm:col-span-2">
                <span class="text-sm font-medium">API URL <span class="font-normal text-base-content/50">{{ $selectedProvider === \App\Enums\AiProvider::Custom ? '(required)' : '(optional)' }}</span></span>
                <input wire:model="apiUrl" type="url" maxlength="2048" spellcheck="false" autocomplete="off" placeholder="{{ $selectedProvider?->defaultApiUrl() ?? 'https://ai.example.com/v1' }}" @required($selectedProvider === \App\Enums\AiProvider::Custom) class="d-input d-input-bordered w-full @error('apiUrl') d-input-error @enderror">
                @if ($selectedProvider?->defaultApiUrl())
                    <span class="break-all text-xs text-base-content/55">Leave blank to use {{ $selectedProvider->defaultApiUrl() }}.</span>
                @else
                    <span class="text-xs text-base-content/55">Enter your provider's base API URL.</span>
                @endif
                @error('apiUrl') <span class="text-sm text-error" role="alert">{{ $message }}</span> @enderror
            </label>

            <label class="grid gap-1.5 sm:col-span-2">
                <span class="text-sm font-medium">API key</span>
                <input x-model="apiKey" type="password" maxlength="4096" autocomplete="new-password" spellcheck="false" @required(! $editingIntegrationId) class="d-input d-input-bordered w-full @error('apiKey') d-input-error @enderror">
                <span class="text-xs text-base-content/55">Stored encrypted and never displayed. @if ($editingIntegrationId) Leave blank to keep the saved key for the same provider. @endif</span>
                @error('apiKey') <span class="text-sm text-error" role="alert">{{ $message }}</span> @enderror
            </label>

            <div class="flex flex-wrap justify-end gap-2 sm:col-span-2">
                @if ($editingIntegrationId)
                    <button type="button" wire:click="cancelEdit" class="d-btn d-btn-ghost" wire:loading.attr="disabled">Cancel</button>
                @endif
                <button type="submit" class="d-btn d-btn-primary" wire:loading.attr="disabled">
                    <span class="d-loading d-loading-spinner d-loading-sm" wire:loading wire:target="save"></span>
                    {{ $editingIntegrationId ? 'Save changes' : 'Add integration' }}
                </button>
            </div>
        </form>
    </x-ui::panel>

    <x-ui::panel title="Saved integrations" description="These settings are shared by this instance and managed by administrators.">
        @if ($integrations->isEmpty())
            <x-ui::empty-state title="No AI integrations yet" description="Add your first provider using the form above." />
        @else
            <ul class="space-y-3">
                @foreach ($integrations as $integration)
                    <li class="flex flex-wrap items-center justify-between gap-4 rounded-box border border-base-300 p-4" wire:key="integration-{{ $integration->id }}">
                        <div class="min-w-0 flex-1">
                            <h3 class="break-words text-sm font-semibold">{{ $integration->name }}</h3>
                            <p class="mt-1 text-sm text-base-content/65">{{ $integration->provider->label() }} · API key saved</p>
                            <p class="mt-1 break-all text-xs text-base-content/55">{{ $integration->api_url ?? $integration->provider->defaultApiUrl() }}</p>
                        </div>
                        <div class="flex gap-2">
                            <button type="button" wire:click="edit('{{ $integration->id }}')" wire:loading.attr="disabled" class="d-btn d-btn-outline d-btn-sm" aria-label="Edit {{ $integration->name }}">Edit</button>
                            <button type="button" wire:click="remove('{{ $integration->id }}')" wire:confirm="Remove this AI integration and its saved API key?" wire:loading.attr="disabled" class="d-btn d-btn-ghost d-btn-sm text-error" aria-label="Remove {{ $integration->name }}">Remove</button>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-ui::panel>
</x-ui::page>
