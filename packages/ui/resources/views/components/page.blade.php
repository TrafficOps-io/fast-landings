@props(['width' => null])

<div {{ $attributes->class(array_filter(['mx-auto', 'space-y-6', $width])) }}>
    {{ $slot }}
</div>
