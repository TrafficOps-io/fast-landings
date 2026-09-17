@props(['message' => null])
<x-mail::layout>
<x-slot:header>
<x-mail::header :url="config('app.url')" :message="$message" />
</x-slot:header>

{!! $slot !!}

@isset($subcopy)
<x-slot:subcopy>
<x-mail::subcopy>{!! $subcopy !!}</x-mail::subcopy>
</x-slot:subcopy>
@endisset

<x-slot:footer>
<x-mail::footer>
© {{ date('Y') }} [trafficops.io](https://trafficops.io)
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>
