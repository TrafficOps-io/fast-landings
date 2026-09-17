<label class="grid gap-1.5">
    <span class="text-sm font-medium">Account label</span>
    <input wire:model="cloudflareLabel" class="d-input d-input-bordered w-full" placeholder="Production DNS" maxlength="120">
    @error('cloudflareLabel')<span class="text-xs text-error">{{ $message }}</span>@enderror
</label>
<label class="grid gap-1.5">
    <span class="text-sm font-medium">Cloudflare API token</span>
    <input wire:model="cloudflareToken" type="password" class="d-input d-input-bordered w-full" autocomplete="new-password">
    @error('cloudflareToken')<span class="text-xs text-error">{{ $message }}</span>@enderror
</label>
<details class="rounded-box border border-base-300 p-3 text-sm">
    <summary class="cursor-pointer font-medium">API token permissions and setup</summary>
    <ol class="mt-3 list-decimal space-y-3 pl-5 text-xs leading-5 text-base-content/65">
        <li>In <a href="https://dash.cloudflare.com/profile/api-tokens" target="_blank" rel="noopener noreferrer" class="underline">Cloudflare API Tokens</a>, choose Create Token → Create Custom Token.</li>
        <li>Add <strong>Zone → Zone → Read</strong> and <strong>Zone → DNS → Edit</strong> permissions.</li>
        <li>Under Zone Resources, select the zones you want to connect, or all zones in the chosen account.</li>
        <li>Create the token, paste it above, then select Connect account. The available zones will be loaded automatically.</li>
    </ol>
</details>
