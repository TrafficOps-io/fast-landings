<?php

namespace TrafficOps\FastLandings\Ui\Tests;

use Illuminate\Support\Facades\Blade;

class PageTest extends TestCase
{
    public function test_page_header_preserves_caller_attributes(): void
    {
        $html = Blade::render('<x-ui::page-header title="Campaigns" id="campaign-heading" class="mb-8" />');

        $this->assertMatchesRegularExpression('/<header[^>]*id="campaign-heading"/', $html);
        $this->assertStringContainsString('space-y-3 mb-8', $html);
    }

    public function test_page_defers_width_to_the_container_and_keeps_rhythm(): void
    {
        $html = Blade::render('<x-ui::page>CONTENT</x-ui::page>');

        // Ширину задаёт `.ui-container` на `<main>`; страница её больше не дублирует.
        $this->assertStringNotContainsString('max-w-7xl', $html);

        // Центрирование и вертикальный ритм остаются за страницей.
        $this->assertStringContainsString('mx-auto', $html);
        $this->assertStringContainsString('space-y-6', $html);
        $this->assertStringContainsString('CONTENT', $html);
    }

    public function test_page_does_not_add_padding(): void
    {
        $html = Blade::render('<x-ui::page>CONTENT</x-ui::page>');

        $this->assertStringNotContainsString('p-6', $html);
        $this->assertStringNotContainsString('px-4', $html);
    }

    public function test_page_width_is_overridable(): void
    {
        $html = Blade::render('<x-ui::page width="max-w-3xl">CONTENT</x-ui::page>');

        $this->assertStringContainsString('max-w-3xl', $html);
    }

    public function test_page_header_renders_title_and_actions(): void
    {
        $html = Blade::render(<<<'BLADE'
            <x-ui::page-header title="Campaigns">
                <x-slot:actions><a href="/new">New</a></x-slot:actions>
            </x-ui::page-header>
        BLADE);

        $this->assertStringContainsString('Campaigns', $html);
        $this->assertStringContainsString('New', $html);
        $this->assertStringContainsString('<h1', $html);
    }

    public function test_page_header_eyebrow_is_optional(): void
    {
        $withEyebrow = Blade::render('<x-ui::page-header title="T" eyebrow="DASHBOARD" />');
        $without = Blade::render('<x-ui::page-header title="T" />');

        $this->assertStringContainsString('ui-eyebrow', $withEyebrow);
        $this->assertStringContainsString('DASHBOARD', $withEyebrow);
        $this->assertStringNotContainsString('ui-eyebrow', $without);
    }

    public function test_page_header_back_link_is_optional_and_owns_its_arrow(): void
    {
        $html = Blade::render('<x-ui::page-header title="T" back="/list" back-label="All items" />');

        $this->assertStringContainsString('ui-back-link', $html);
        $this->assertStringContainsString('All items', $html);
        $this->assertSame(1, substr_count($html, '←'));

        $this->assertStringNotContainsString('ui-back-link', Blade::render('<x-ui::page-header title="T" />'));
    }
}
