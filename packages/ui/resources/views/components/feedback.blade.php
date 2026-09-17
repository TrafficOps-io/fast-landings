@props([
    'saved' => session('saved'),
    'errors' => $errors,
])

@if ($saved)
    <div class="d-alert d-alert-success" role="status">{{ $saved }}</div>
@endif

@if ($errors->any())
    <div class="d-alert d-alert-error" role="alert">
        <ul class="list-disc pl-4">
            @foreach ($errors->all() as $message)
                <li>{{ $message }}</li>
            @endforeach
        </ul>
    </div>
@endif
