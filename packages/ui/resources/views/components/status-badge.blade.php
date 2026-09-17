@props([
    'label',
    'tone' => 'neutral',
])

@php
    $toneClass = [
        'success' => 'd-badge-success',
        'warning' => 'd-badge-warning',
        'error' => 'd-badge-error',
        'neutral' => 'd-badge-ghost',
    ][$tone] ?? 'd-badge-ghost';
@endphp

<span {{ $attributes->class(['d-badge', 'd-badge-soft', 'ui-status-badge', $toneClass]) }}>{{ $label }}</span>
