@props([
    'home',
    'title',
    'brand' => null,
    'subtitle' => null,
    'navLabel' => null,
    'navigate' => true,
])

<header {{ $attributes->class('ui-topbar') }}>
    <div class="ui-container flex h-16 items-center justify-between gap-4">
        <div class="flex min-w-0 items-center gap-3">
            <a href="{{ $home }}" aria-label="{{ $title }}" class="flex min-w-0 items-center gap-2.5" @if ($navigate) wire:navigate @endif>
                <x-ui::brand-mark :brand="$brand ?? 'trafficops'" />
                <span class="hidden min-w-0 sm:block">
                    @if ($brand)
                        <x-ui::brand-wordmark :brand="$brand" />
                    @else
                        <span class="block truncate text-sm font-semibold leading-tight">{{ $title }}</span>
                    @endif
                    @if ($subtitle)
                        <span class="hidden text-[11px] leading-tight text-base-content/45 sm:block">{{ $subtitle }}</span>
                    @endif
                </span>
            </a>
            {{ $brandExtra ?? '' }}
        </div>

        <nav class="flex items-center gap-1 sm:gap-3" @if ($navLabel) aria-label="{{ $navLabel }}" @endif>
            {{ $utilities ?? '' }}
            {{ $nav ?? '' }}
            {{ $profile ?? '' }}
        </nav>
    </div>
</header>
