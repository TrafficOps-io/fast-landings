@props(['home', 'brand', 'title', 'nav' => [], 'links' => [], 'ctaHref', 'ctaLabel', 'navLabel', 'menuLabel'])

<header class="ui-public-header">
    <div class="ui-container ui-public-header-row">
        <a class="ui-public-brand" href="{{ $home }}" aria-label="{{ $title }}">
            <x-ui::brand-mark :brand="$brand" />
            <span><x-ui::brand-wordmark :brand="$brand" /><small>by trafficops.io</small></span>
        </a>
        <nav class="ui-public-nav" aria-label="{{ $navLabel }}">
            @foreach ($nav as $item)
                <a href="{{ $item['href'] }}" @if ($item['active'] ?? false) aria-current="page" @endif>{{ $item['label'] }}</a>
            @endforeach
        </nav>
        <div class="ui-public-actions">
            {{ $language }}
            <x-ui::public-theme-toggle />
            @foreach ($links as $item)
                <a class="ui-public-link" href="{{ $item['href'] }}" @if ($item['active'] ?? false) aria-current="page" @endif>{{ $item['label'] }}</a>
            @endforeach
            <a class="ui-public-cta" href="{{ $ctaHref }}">{{ $ctaLabel }}</a>
            <details class="ui-public-menu">
                <summary aria-label="{{ $menuLabel }}"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h16" stroke-linecap="round"/></svg></summary>
                <nav class="ui-public-menu-panel" aria-label="{{ $navLabel }}">
                    @foreach ([...$nav, ...$links] as $item)
                        <a class="ui-public-link" href="{{ $item['href'] }}" @if ($item['active'] ?? false) aria-current="page" @endif>{{ $item['label'] }}</a>
                    @endforeach
                    <a class="ui-public-cta" href="{{ $ctaHref }}">{{ $ctaLabel }}</a>
                </nav>
            </details>
        </div>
    </div>
</header>
