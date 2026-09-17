<?php

namespace TrafficOps\FastLandings\Ui\Tests;

use Illuminate\Support\Facades\Blade;
use PHPUnit\Framework\Attributes\DataProvider;

class StatusBadgeTest extends TestCase
{
    public static function toneProvider(): array
    {
        return [
            'success' => ['success', 'd-badge-success'],
            'warning' => ['warning', 'd-badge-warning'],
            'error' => ['error', 'd-badge-error'],
            'neutral' => ['neutral', 'd-badge-ghost'],
        ];
    }

    #[DataProvider('toneProvider')]
    public function test_each_tone_maps_to_a_literal_class(string $tone, string $expected): void
    {
        $html = Blade::render('<x-ui::status-badge label="Active" tone="'.$tone.'" />');

        $this->assertStringContainsString('d-badge', $html);
        $this->assertStringContainsString($expected, $html);
        $this->assertStringContainsString('Active', $html);
    }

    public function test_default_tone_is_neutral(): void
    {
        $html = Blade::render('<x-ui::status-badge label="Draft" />');

        $this->assertStringContainsString('d-badge-ghost', $html);
    }

    public function test_unknown_tone_falls_back_to_neutral(): void
    {
        $html = Blade::render('<x-ui::status-badge label="???" tone="chartreuse" />');

        $this->assertStringContainsString('d-badge-ghost', $html);
        $this->assertStringNotContainsString('chartreuse', $html);
    }

    public function test_class_names_are_literal_so_tailwind_can_find_them(): void
    {
        $source = file_get_contents(__DIR__.'/../resources/views/components/status-badge.blade.php');

        foreach (['d-badge-success', 'd-badge-warning', 'd-badge-error', 'd-badge-ghost'] as $class) {
            $this->assertStringContainsString($class, $source);
        }

        $this->assertStringNotContainsString('d-badge-{{', $source);
        $this->assertStringNotContainsString("'d-badge-'.", $source);
    }
}
