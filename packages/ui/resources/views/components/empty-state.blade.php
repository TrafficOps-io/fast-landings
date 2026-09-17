@props([
    'title',
    'description' => null,
])

<div {{ $attributes->class('rounded-box border border-dashed border-base-300 px-4 py-10 text-center') }}>
    <p class="font-medium">{{ $title }}</p>

    @if ($description)
        <p class="mx-auto mt-1 max-w-md text-sm text-base-content/60">{{ $description }}</p>
    @endif

    @if (isset($action))
        <div class="mt-4">{{ $action }}</div>
    @endif
</div>
