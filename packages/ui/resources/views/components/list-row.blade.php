@props(['href' => null, 'title' => null])

<li {{ $attributes->class('flex flex-wrap items-center justify-between gap-3 py-3.5') }}>
    <div class="min-w-0">
        @if ($href)
            <a href="{{ $href }}" class="font-medium" wire:navigate>{{ $title }}</a>
        @else
            <span class="font-medium">{{ $title }}</span>
        @endif

        @if (isset($meta))
            <div class="mt-0.5 text-sm text-base-content/60">{{ $meta }}</div>
        @endif
    </div>

    @if (isset($actions))
        <div class="flex shrink-0 items-center gap-2">{{ $actions }}</div>
    @endif
</li>
