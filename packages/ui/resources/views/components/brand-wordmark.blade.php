@props(['brand' => 'trafficops'])

@php
    [$name, $accent] = match ($brand) {
        'pwapps' => ['PW', 'Apps'],
        'hookroute' => ['Hook', 'Route'],
        'fastlandings' => ['Fast', ' Landings'],
        default => ['Traffic', 'Ops'],
    };
@endphp

<span {{ $attributes->class('ui-brand-wordmark') }}>{{ $name }}<span>{{ $accent }}</span></span>
