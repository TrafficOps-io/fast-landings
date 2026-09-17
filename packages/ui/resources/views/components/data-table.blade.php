@props(['density' => 'normal'])

<div class="overflow-x-auto">
    <table {{ $attributes->class(['d-table', 'd-table-sm' => $density === 'compact']) }}>
        <thead>{{ $head }}</thead>
        <tbody>{{ $body }}</tbody>
    </table>
</div>
