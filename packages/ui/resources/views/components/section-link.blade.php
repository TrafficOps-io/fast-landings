@props([
    'href',
    'active' => false,
    'navigate' => true,
])

<a
    href="{{ $href }}"
    @if ($navigate) wire:navigate @endif
    @if ($active) aria-current="page" @endif
    {{ $attributes->class(['ui-section-link', 'is-active' => $active]) }}
>{{ $slot }}</a>
