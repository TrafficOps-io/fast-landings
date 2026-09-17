@props([
    'title',
    'eyebrow' => null,
    'back' => null,
    'backLabel' => null,
])

<header {{ $attributes->class('space-y-3') }}>
    @if ($back)
        <a class="ui-back-link" href="{{ $back }}" wire:navigate>← {{ $backLabel }}</a>
    @endif

    <div class="flex flex-wrap items-start justify-between gap-4">
        <div class="min-w-0">
            @if ($eyebrow)
                <p class="ui-eyebrow">{{ $eyebrow }}</p>
            @endif
            <h1 class="mt-1 text-2xl font-semibold tracking-tight">{{ $title }}</h1>
        </div>
        {{ $actions ?? '' }}
    </div>
</header>
