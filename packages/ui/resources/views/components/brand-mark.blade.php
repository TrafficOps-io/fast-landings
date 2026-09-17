@props(['brand' => 'trafficops'])

@php
    $brand = match ($brand) {
        'pwapps', 'hookroute', 'fastlandings' => $brand,
        default => 'trafficops',
    };
    $svg = \TrafficOps\FastLandings\Ui\Support\Brand::mark($brand);
@endphp

<span {{ $attributes->class(['ui-mark', 'ui-mark--'.$brand]) }} aria-hidden="true">
    {!! $svg !!}
</span>
