<?php

namespace TrafficOps\FastLandings\Ui\Tests;

use Illuminate\Support\Facades\Blade;

class DataTableTest extends TestCase
{
    public function test_table_scrolls_horizontally_and_uses_daisy_table(): void
    {
        $html = Blade::render(<<<'BLADE'
            <x-ui::data-table>
                <x-slot:head><tr><th>Time</th></tr></x-slot:head>
                <x-slot:body><tr><td>now</td></tr></x-slot:body>
            </x-ui::data-table>
        BLADE);

        $this->assertStringContainsString('overflow-x-auto', $html);
        $this->assertStringContainsString('d-table', $html);
        $this->assertStringContainsString('<thead>', $html);
        $this->assertStringContainsString('<tbody>', $html);
        $this->assertStringContainsString('Time', $html);
        $this->assertStringContainsString('now', $html);
    }

    public function test_default_density_is_not_compact(): void
    {
        $html = Blade::render(<<<'BLADE'
            <x-ui::data-table><x-slot:head><tr><th>A</th></tr></x-slot:head><x-slot:body><tr><td>b</td></tr></x-slot:body></x-ui::data-table>
        BLADE);

        $this->assertStringNotContainsString('d-table-sm', $html);
    }

    public function test_compact_density_adds_the_small_modifier(): void
    {
        $html = Blade::render(<<<'BLADE'
            <x-ui::data-table density="compact"><x-slot:head><tr><th>A</th></tr></x-slot:head><x-slot:body><tr><td>b</td></tr></x-slot:body></x-ui::data-table>
        BLADE);

        $this->assertStringContainsString('d-table-sm', $html);
    }

    public function test_json_highlights_and_escapes(): void
    {
        $html = Blade::render('<x-ui::json :value="$value" />', [
            'value' => ['name' => '<script>', 'count' => 2, 'ok' => true, 'nothing' => null],
        ]);

        $this->assertStringContainsString('<code class="ui-json', $html);
        $this->assertStringContainsString('ui-json-key', $html);
        $this->assertStringContainsString('ui-json-number', $html);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }
}
