<?php

namespace TrafficOps\FastLandings\Ui\Support;

class ErrorPage
{
    public static function styles(): string
    {
        return file_get_contents(__DIR__.'/../../resources/css/error-page.css');
    }
}
