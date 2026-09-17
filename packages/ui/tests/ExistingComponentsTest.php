<?php

namespace TrafficOps\FastLandings\Ui\Tests;

use Illuminate\Support\Facades\Blade;

class ExistingComponentsTest extends TestCase
{
    public function test_package_components_are_resolvable(): void
    {
        $html = Blade::render('<x-ui::text-link href="/docs">Read</x-ui::text-link>');

        $this->assertStringContainsString('href="/docs"', $html);
        $this->assertStringContainsString('Read', $html);
    }
}
