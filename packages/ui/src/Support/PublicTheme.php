<?php

namespace TrafficOps\FastLandings\Ui\Support;

class PublicTheme
{
    public static function script(): string
    {
        return file_get_contents(__DIR__.'/../../resources/js/public-theme.js');
    }
}
