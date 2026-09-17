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
<body class="ui-grid min-h-screen bg-base-200 text-base-content antialiased">
    <header class="absolute inset-x-0 top-0 z-10 flex items-center justify-between p-4 sm:p-6">
        <a href="{{ route('login') }}" class="flex items-center gap-2.5" aria-label="Fast Landings">
            <x-ui::brand-mark brand="fastlandings" />
            <span>
                <x-ui::brand-wordmark brand="fastlandings" class="block" />
                <small class="block text-[11px] leading-tight text-base-content/45">by trafficops.io</small>
            </span>
        </a>
        <x-ui::public-theme-toggle />
    </header>

    <main class="mx-auto flex min-h-screen w-full max-w-md flex-col justify-center px-6 py-24">
        <section class="d-card border border-base-300 bg-base-100 shadow-xl">
            <div class="d-card-body gap-6">{{ $slot }}</div>
        </section>
    </main>

    @livewireScripts
</body>
</html>
