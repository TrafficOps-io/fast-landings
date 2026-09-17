<?php

namespace TrafficOps\FastLandings\Ui\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use TrafficOps\FastLandings\Ui\UiServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [UiServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('u', 32)));
    }
}
