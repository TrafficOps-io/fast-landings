<div class="grid gap-6">
    <x-ui::auth-header title="Welcome back" description="Sign in with the administrator credentials created during installation." />
    <x-ui::auth-session-status class="d-alert d-alert-success text-sm" :status="session('status')" />
    <x-ui::feedback />

    <form wire:submit="login" class="grid gap-4">
        <label class="d-form-control grid gap-1.5">
            <span class="d-label-text text-sm font-medium">Email address</span>
            <input wire:model="email" type="email" class="d-input d-input-bordered w-full" required autofocus autocomplete="email">
        </label>

        <label class="d-form-control grid gap-1.5">
            <span class="d-label-text text-sm font-medium">Password</span>
            <input wire:model="password" type="password" class="d-input d-input-bordered w-full" required autocomplete="current-password">
        </label>

        <label class="flex cursor-pointer items-center gap-3 text-sm">
            <input wire:model="remember" type="checkbox" class="d-checkbox d-checkbox-primary d-checkbox-sm">
            Remember me
        </label>

        <button type="submit" class="d-btn d-btn-primary w-full" wire:loading.attr="disabled" wire:target="login">
            <span class="d-loading d-loading-spinner d-loading-sm" wire:loading wire:target="login"></span>
            Sign in
        </button>
    </form>
</div>
