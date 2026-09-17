<?php

namespace TrafficOps\FastLandings\Ui\Tests;

use Illuminate\Support\Facades\Blade;
use PHPUnit\Framework\Attributes\DataProvider;

class NavigationLinkTest extends TestCase
{
    public static function linkProvider(): array
    {
        return [
            'navigation' => ['nav-link', 'ui-utility-link'],
            'section' => ['section-link', 'ui-section-link'],
        ];
    }

    #[DataProvider('linkProvider')]
    public function test_inactive_link_has_no_active_marker(string $component, string $baseClass): void
    {
        $html = Blade::render('<x-ui::'.$component.' href="/campaigns">Campaigns</x-ui::'.$component.'>');

        $this->assertStringContainsString($baseClass, $html);
        $this->assertStringNotContainsString('is-active', $html);
        $this->assertStringNotContainsString('aria-current', $html);
    }

    #[DataProvider('linkProvider')]
    public function test_active_link_is_marked_for_sighted_and_assistive_users(string $component, string $baseClass): void
    {
        $html = Blade::render('<x-ui::'.$component.' href="/campaigns" :active="true">Campaigns</x-ui::'.$component.'>');

        $this->assertStringContainsString('is-active', $html);
        $this->assertStringContainsString('aria-current="page"', $html);
    }

    #[DataProvider('linkProvider')]
    public function test_navigation_attribute_is_present_by_default(string $component, string $baseClass): void
    {
        $html = Blade::render('<x-ui::'.$component.' href="/campaigns">Campaigns</x-ui::'.$component.'>');

        $this->assertStringContainsString('wire:navigate', $html);
    }

    #[DataProvider('linkProvider')]
    public function test_navigation_attribute_can_be_disabled(string $component, string $baseClass): void
    {
        $html = Blade::render('<x-ui::'.$component.' href="/docs" :navigate="false">Docs</x-ui::'.$component.'>');

        $this->assertStringNotContainsString('wire:navigate', $html);
    }

    #[DataProvider('linkProvider')]
    public function test_caller_classes_are_merged_not_overwritten(string $component, string $baseClass): void
    {
        $html = Blade::render('<x-ui::'.$component.' href="/api" class="hidden md:inline-flex">API</x-ui::'.$component.'>');

        $this->assertStringContainsString($baseClass, $html);
        $this->assertStringContainsString('hidden', $html);
        $this->assertStringContainsString('md:inline-flex', $html);
    }

    public function test_section_link_uses_its_own_class(): void
    {
        $html = Blade::render('<x-ui::section-link href="/hosts" :active="true">Hosts</x-ui::section-link>');

        $this->assertStringContainsString('ui-section-link', $html);
        $this->assertStringContainsString('is-active', $html);
        $this->assertStringContainsString('aria-current="page"', $html);
    }
}
