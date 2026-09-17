<?php

namespace TrafficOps\FastLandings\Ui\Tests;

use Illuminate\Support\Facades\Blade;

class RecordShellTest extends TestCase
{
    public function test_vertical_orientation_without_navigation_keeps_content_full_width(): void
    {
        $html = Blade::render(<<<'BLADE'
            <x-ui::record-shell back="/c" back-label="Back" title="Campaign" orientation="vertical">
                CONTENT
            </x-ui::record-shell>
        BLADE);

        $this->assertStringContainsString('CONTENT', $html);
        $this->assertStringNotContainsString('<nav', $html);
        $this->assertStringNotContainsString('md:grid-cols-', $html);
    }

    public function test_renders_back_link_with_arrow_supplied_by_the_component(): void
    {
        $html = Blade::render(<<<'BLADE'
            <x-ui::record-shell back="/gateways" back-label="All gateways" title="DE traffic" nav-label="Sections">
                CONTENT
            </x-ui::record-shell>
        BLADE);

        $this->assertStringContainsString('ui-back-link', $html);
        $this->assertStringContainsString('href="/gateways"', $html);
        $this->assertStringContainsString('All gateways', $html);
        $this->assertStringContainsString('←', $html);
        $this->assertSame(1, substr_count($html, '←'));
    }

    public function test_renders_title_badge_and_meta(): void
    {
        $html = Blade::render(<<<'BLADE'
            <x-ui::record-shell back="/g" back-label="Back" title="DE traffic" nav-label="Sections">
                <x-slot:badge><span>ACTIVE</span></x-slot:badge>
                <x-slot:meta><span>Whitelist mode</span></x-slot:meta>
                CONTENT
            </x-ui::record-shell>
        BLADE);

        $this->assertStringContainsString('DE traffic', $html);
        $this->assertStringContainsString('ACTIVE', $html);
        $this->assertStringContainsString('Whitelist mode', $html);
        $this->assertStringContainsString('CONTENT', $html);
    }

    public function test_intro_sits_between_title_and_navigation(): void
    {
        $html = Blade::render(<<<'BLADE'
            <x-ui::record-shell back="/g" back-label="Back" title="TITLE" nav-label="Sections"
                :nav="[['url' => '/a', 'label' => 'NAVITEM', 'active' => false]]">
                <x-slot:intro><span>INTRO</span></x-slot:intro>
                CONTENT
            </x-ui::record-shell>
        BLADE);

        $this->assertLessThan(strpos($html, 'INTRO'), strpos($html, 'TITLE'));
        $this->assertLessThan(strpos($html, 'NAVITEM'), strpos($html, 'INTRO'));
    }

    public function test_navigation_renders_through_section_links(): void
    {
        $html = Blade::render(<<<'BLADE'
            <x-ui::record-shell back="/g" back-label="Back" title="T" nav-label="Sections"
                :nav="[
                    ['url' => '/g/1', 'label' => 'Overview', 'active' => true],
                    ['url' => '/g/1/events', 'label' => 'Events', 'active' => false],
                ]">
                CONTENT
            </x-ui::record-shell>
        BLADE);

        $this->assertStringContainsString('ui-section-nav', $html);
        $this->assertSame(2, substr_count($html, 'ui-section-link'));
        $this->assertSame(1, substr_count($html, 'is-active'));
        $this->assertStringContainsString('aria-label="Sections"', $html);
    }

    public function test_default_width_matches_the_existing_gateway_shell(): void
    {
        $html = Blade::render(<<<'BLADE'
            <x-ui::record-shell back="/g" back-label="B" title="T" nav-label="S">C</x-ui::record-shell>
        BLADE);

        $this->assertStringContainsString('max-w-6xl', $html);
        $this->assertStringContainsString('space-y-7', $html);
        $this->assertStringContainsString('space-y-5', $html);
    }

    public function test_width_is_overridable(): void
    {
        $html = Blade::render(<<<'BLADE'
            <x-ui::record-shell back="/g" back-label="B" title="T" nav-label="S" width="max-w-7xl">C</x-ui::record-shell>
        BLADE);

        $this->assertStringContainsString('max-w-7xl', $html);
        $this->assertStringNotContainsString('max-w-6xl', $html);
    }

    public function test_vertical_orientation_puts_navigation_beside_content(): void
    {
        $html = Blade::render(<<<'BLADE'
            <x-ui::record-shell back="/c" back-label="All campaigns" title="DE traffic"
                orientation="vertical" nav-label="Campaign sections"
                :nav="[
                    ['url' => '/c/1', 'label' => 'Settings', 'active' => true],
                    ['url' => '/c/1/hosts', 'label' => 'Hosts', 'active' => false],
                ]">
                CONTENT
            </x-ui::record-shell>
        BLADE);

        $this->assertStringContainsString('is-vertical', $html);
        $this->assertSame(2, substr_count($html, 'ui-section-link'));
        $this->assertSame(1, substr_count($html, 'is-active'));
        $this->assertStringContainsString('CONTENT', $html);
    }

    public function test_horizontal_orientation_has_no_vertical_modifier(): void
    {
        $html = Blade::render(<<<'BLADE'
            <x-ui::record-shell back="/c" back-label="B" title="T" nav-label="S"
                :nav="[['url' => '/a', 'label' => 'A', 'active' => false]]">C</x-ui::record-shell>
        BLADE);

        $this->assertStringNotContainsString('is-vertical', $html);
    }

    public function test_vertical_orientation_keeps_the_title_outside_the_grid(): void
    {
        $html = Blade::render(<<<'BLADE'
            <x-ui::record-shell back="/c" back-label="B" title="TITLE" orientation="vertical" nav-label="S"
                :nav="[['url' => '/a', 'label' => 'NAVITEM', 'active' => false]]">CONTENT</x-ui::record-shell>
        BLADE);

        $gridStart = strpos($html, 'md:grid-cols-');

        $this->assertNotFalse($gridStart, 'Vertical orientation must lay content out in a grid');
        $this->assertLessThan($gridStart, strpos($html, 'TITLE'), 'Title must sit above the grid, full width');
        $this->assertGreaterThan($gridStart, strpos($html, 'NAVITEM'), 'Navigation must sit inside the grid');
    }
}
