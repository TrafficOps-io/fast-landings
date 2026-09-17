@props(['url', 'message' => null])
@php
    $brand = \TrafficOps\FastLandings\Ui\Support\MailTheme::brand();
    $logo = \TrafficOps\FastLandings\Ui\Support\MailTheme::logoPath();
    $logoUrl = $message ? $message->embed($logo) : 'data:image/png;base64,'.base64_encode(file_get_contents($logo));
@endphp
<tr>
<td class="header" align="center">
<table cellpadding="0" cellspacing="0" role="presentation" align="center">
<tr>
<td class="brand-icon" width="{{ $brand['width'] + 14 }}" valign="middle">
<a href="{{ $url }}" aria-label="{{ $brand['name'] }}"><img src="{{ $logoUrl }}" width="{{ $brand['width'] }}" height="40" alt="{{ $brand['name'] }}" /></a>
</td>
<td valign="middle" align="left">
<a href="{{ $url }}" class="brand-wordmark">{{ $brand['word'] }}<span class="brand-accent">{{ $brand['accent'] }}</span></a>
<p class="brand-byline">by <a href="https://trafficops.io">trafficops.io</a></p>
</td>
</tr>
</table>
</td>
</tr>
