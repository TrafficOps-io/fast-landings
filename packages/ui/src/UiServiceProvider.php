<?php

namespace TrafficOps\FastLandings\Ui;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;

class UiServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views/mail', 'ui-mail');

        Blade::anonymousComponentPath(
            __DIR__.'/../resources/views/components',
            'ui',
        );
    }
}
