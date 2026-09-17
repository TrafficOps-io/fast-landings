<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" xmlns="http://www.w3.org/1999/xhtml">
<head>
<title>{{ \TrafficOps\FastLandings\Ui\Support\MailTheme::brand()['name'] }}</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
<meta name="color-scheme" content="light" />
<meta name="supported-color-schemes" content="light" />
<!--[if !mso]><!-->
<style>
@foreach (\TrafficOps\FastLandings\Ui\Support\MailTheme::fonts() as $font)
@font-face {
    font-family: 'Onest Variable';
    font-style: normal;
    font-weight: 100 900;
    font-display: swap;
    src: url('{!! e($font['url']) !!}') format('woff2');
    unicode-range: {{ $font['range'] }};
}
@endforeach
</style>
<!--<![endif]-->
<style>
@media only screen and (max-width: 600px) {
    .body { padding: 0 12px !important; }
    .inner-body, .footer { width: 100% !important; }
    .content-cell { padding: 28px 24px !important; }
    .header { padding: 28px 16px !important; }
    h1 { font-size: 26px !important; }
}
</style>
<!--[if mso]>
<style>body, table, td, p, a, h1, h2, h3 { font-family: Arial, sans-serif !important; }</style>
<![endif]-->
{!! $head ?? '' !!}
</head>
<body>
<table class="wrapper" width="100%" cellpadding="0" cellspacing="0" role="presentation" bgcolor="#fbf7f2">
<tr>
<td align="center">
<table class="content" width="100%" cellpadding="0" cellspacing="0" role="presentation">
{!! $header ?? '' !!}
<tr>
<td class="body" align="center">
<table class="inner-body" align="center" width="570" cellpadding="0" cellspacing="0" role="presentation" bgcolor="#fffdfb">
<tr>
<td class="content-cell">
{!! Illuminate\Mail\Markdown::parse($slot) !!}
{!! $subcopy ?? '' !!}
</td>
</tr>
</table>
</td>
</tr>
{!! $footer ?? '' !!}
</table>
</td>
</tr>
</table>
</body>
</html>
