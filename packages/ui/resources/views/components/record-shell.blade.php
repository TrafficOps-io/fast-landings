@props([
    'back',
    'backLabel',
    'title',
    'nav' => [],
    'navLabel' => null,
    'orientation' => 'horizontal',
    'width' => 'max-w-6xl',
])

<div {{ $attributes->class(['mx-auto space-y-7', $width]) }}>
    <header class="space-y-5">
        <a class="ui-back-link" href="{{ $back }}" wire:navigate>← {{ $backLabel }}</a>

        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="flex items-center gap-3">
                <h1 class="text-3xl font-semibold tracking-tight">{{ $title }}</h1>
                {{ $badge ?? '' }}
            </div>
            {{ $meta ?? '' }}
        </div>

        {{ $intro ?? '' }}

        @if ($nav !== [] && $orientation === 'horizontal')
            <nav class="ui-section-nav" @if ($navLabel) aria-label="{{ $navLabel }}" @endif>
                @foreach ($nav as $item)
                    <x-ui::section-link :href="$item['url']" :active="$item['active']">{{ $item['label'] }}</x-ui::section-link>
                @endforeach
            </nav>
        @endif
    </header>

    @if ($orientation === 'vertical' && $nav !== [])
        <div class="grid gap-7 md:grid-cols-[190px_minmax(0,1fr)]">
            <nav class="ui-section-nav is-vertical" @if ($navLabel) aria-label="{{ $navLabel }}" @endif>
                @foreach (collect($nav)->groupBy('group') as $group => $items)
                    @if ($group !== '')
                        <div class="ui-section-group" role="group" aria-label="{{ $group }}">
                            <p class="ui-section-group-label">{{ $group }}</p>
                    @endif
                    @foreach ($items as $item)
                        <x-ui::section-link :href="$item['url']" :active="$item['active']">{{ $item['label'] }}</x-ui::section-link>
                    @endforeach
                    @if ($group !== '')
                        </div>
                    @endif
                @endforeach
            </nav>
            <div class="min-w-0">{{ $slot }}</div>
        </div>
    @else
        {{ $slot }}
    @endif
</div>
