<?php

namespace TrafficOps\FastLandings\Ui\Support;

class MailTheme
{
    public static function path(): string
    {
        return __DIR__.'/../../resources/views/mail';
    }

    /** @return array{key: string, name: string, word: string, accent: string, width: int} */
    public static function brand(): array
    {
        [$key, $word, $accent, $width] = match (config('mail.brand')) {
            'hookroute' => ['hookroute', 'Hook', 'Route', 50],
            'pwapps' => ['pwapps', 'PW', 'Apps', 30],
            default => ['trafficops', 'Traffic', 'Ops', 40],
        };

        return ['key' => $key, 'name' => $word.$accent, 'word' => $word, 'accent' => $accent, 'width' => $width];
    }

    public static function logoPath(): string
    {
        return __DIR__.'/../../resources/brand/mail/'.self::brand()['key'].'.png';
    }

    /**
     * Reuse the application's built Onest assets, never a Vite development URL.
     * Mail still renders with system fonts before the first frontend build.
     *
     * @return array<int, array{url: string, range: string}>
     */
    public static function fonts(): array
    {
        $path = public_path('build/manifest.json');
        $manifest = is_file($path) ? json_decode(file_get_contents($path), true) : [];
        $baseUrl = rtrim(config('app.asset_url') ?: config('app.url'), '/');
        $fonts = [];

        foreach ([
            'cyrillic-ext' => 'U+0460-052F,U+1C80-1C8A,U+20B4,U+2DE0-2DFF,U+A640-A69F,U+FE2E-FE2F',
            'cyrillic' => 'U+0301,U+0400-045F,U+0490-0491,U+04B0-04B1,U+2116',
            'latin-ext' => 'U+0100-02BA,U+02BD-02C5,U+02C7-02CC,U+02CE-02D7,U+02DD-02FF,U+0304,U+0308,U+0329,U+1D00-1DBF,U+1E00-1E9F,U+1EF2-1EFF,U+2020,U+20A0-20AB,U+20AD-20C0,U+2113,U+2C60-2C7F,U+A720-A7FF',
            'latin' => 'U+0000-00FF,U+0131,U+0152-0153,U+02BB-02BC,U+02C6,U+02DA,U+02DC,U+0304,U+0308,U+0329,U+2000-206F,U+20AC,U+2122,U+2191,U+2193,U+2212,U+2215,U+FEFF,U+FFFD',
        ] as $subset => $range) {
            $asset = $manifest['../../node_modules/@fontsource-variable/onest/files/onest-'.$subset.'-wght-normal.woff2']['file'] ?? null;

            if (is_string($asset)) {
                $fonts[] = ['url' => $baseUrl.'/build/'.$asset, 'range' => $range];
            }
        }

        return $fonts;
    }
}
