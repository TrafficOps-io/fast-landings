<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? config('app.name') }}</title>
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="32x32">
    <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
    <x-ui::public-theme-script storage-key="fast-landings-theme" />
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="ui-app-body min-h-screen text-base-content antialiased">
    <x-ui::topbar :home="route('dashboard')" title="Fast Landings" brand="fastlandings" subtitle="by trafficops.io" nav-label="Main navigation">
        <x-slot:utilities>
            <x-ui::public-theme-toggle />
        </x-slot:utilities>

        <x-slot:nav>
            <x-ui::nav-link :href="route('landings.index')" :active="request()->routeIs('landings.*')">Landings</x-ui::nav-link>
            <x-ui::nav-link :href="route('templates.index')" :active="request()->routeIs('templates.*')">Templates</x-ui::nav-link>
            <x-ui::nav-link :href="route('domains.index')" :active="request()->routeIs('domains.*', 'cloudflare.*')">Domains</x-ui::nav-link>
            @if (auth()->user()->isAdministrator())
                <x-ui::nav-link :href="route('users.index')" :active="request()->routeIs('users.*')">Users</x-ui::nav-link>
            @endif
        </x-slot:nav>

        <x-slot:profile>
            <details class="d-dropdown d-dropdown-end shrink-0">
                <summary class="d-btn d-btn-ghost d-btn-sm h-10 gap-2 px-2 shadow-none">
                    <span class="grid size-7 place-items-center rounded-full bg-primary text-xs font-bold text-primary-content">
                        {{ Str::upper(Str::substr(auth()->user()->name, 0, 2)) }}
                    </span>
                    <span class="hidden max-w-32 truncate sm:inline">{{ auth()->user()->name }}</span>
                </summary>
                <div class="d-dropdown-content z-50 mt-2 w-56 rounded-box border border-base-300 bg-base-100 p-2 shadow">
                    <p class="truncate px-2 py-1 text-xs text-base-content/60">{{ auth()->user()->email }}</p>
                    @if (auth()->user()->isAdministrator())
                        <a href="{{ route('cloudflare.index') }}" wire:navigate class="d-btn d-btn-ghost d-btn-sm w-full justify-start" @if (request()->routeIs('cloudflare.*')) aria-current="page" @endif>Cloudflare connections</a>
                        <a href="{{ route('ai-integrations.index') }}" wire:navigate class="d-btn d-btn-ghost d-btn-sm w-full justify-start" @if (request()->routeIs('ai-integrations.*')) aria-current="page" @endif>AI integrations</a>
                    @endif
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button class="d-btn d-btn-ghost d-btn-sm w-full justify-start" type="submit">Sign out</button>
                    </form>
                </div>
            </details>
        </x-slot:profile>
    </x-ui::topbar>

    <main class="ui-container py-8 sm:py-10">{{ $slot }}</main>

    @livewireScripts
</body>
</html>
