@props([
    'title' => null,
    'description' => null,
])

<section {{ $attributes->class('ui-panel') }}>
    @if ($title)
        <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
                <h2 class="text-base font-semibold">{{ $title }}</h2>
                @if ($description)
                    <p class="mt-1 text-sm text-base-content/60">{{ $description }}</p>
                @endif
            </div>
            {{ $actions ?? '' }}
        </div>
    @endif

    {{ $slot }}
</section>
