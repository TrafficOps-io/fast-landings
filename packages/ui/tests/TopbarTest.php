<?php

namespace TrafficOps\FastLandings\Ui\Tests;

use Illuminate\Support\Facades\Blade;

class TopbarTest extends TestCase
{
    public function test_brand_link_has_a_name_when_its_visible_title_is_hidden_on_mobile(): void
    {
        $html = Blade::render('<x-ui::topbar home="/dashboard" title="PW Apps" />');

        $this->assertMatchesRegularExpression('/<a[^>]*aria-label="PW Apps"/', $html);
    }

    public function test_renders_the_fast_landings_product_brand(): void
    {
        $html = Blade::render('<x-ui::topbar home="/" title="Fast Landings" brand="fastlandings" />');

        $this->assertStringContainsString('ui-mark--fastlandings', $html);
        $this->assertStringContainsString('viewBox="0 0 80 64"', $html);
        $this->assertStringContainsString('Fast<span> Landings</span>', $html);
    }

    public function test_renders_brand_title_and_link(): void
    {
        $html = Blade::render(<<<'BLADE'
            <x-ui::topbar home="/dashboard" title="PW Apps" subtitle="by trafficops.io" />
        BLADE);

        $this->assertStringContainsString('ui-topbar', $html);
        $this->assertStringContainsString('ui-mark', $html);
        $this->assertStringContainsString('href="/dashboard"', $html);
        $this->assertStringContainsString('PW Apps', $html);
        $this->assertStringContainsString('by trafficops.io', $html);
    }

    public function test_subtitle_is_rendered_only_when_given(): void
    {
        $with = Blade::render('<x-ui::topbar home="/" title="Docs" subtitle="by trafficops.io" />');
        $without = Blade::render('<x-ui::topbar home="/" title="Docs" />');

        $this->assertStringContainsString('by trafficops.io', $with);
        $this->assertStringContainsString('Docs', $without);
        $this->assertLessThan(
            substr_count($with, '<span'),
            substr_count($without, '<span'),
            'The subtitle element must not be rendered at all when no subtitle is given',
        );
    }

    public function test_brand_navigation_attribute_can_be_disabled(): void
    {
        $with = Blade::render('<x-ui::topbar home="/" title="App" />');
        $without = Blade::render('<x-ui::topbar home="/" title="App" :navigate="false" />');

        $this->assertStringContainsString('wire:navigate', $with);
        $this->assertStringNotContainsString('wire:navigate', $without);
    }

    public function test_renders_without_profile_slot(): void
    {
        $html = Blade::render(<<<'BLADE'
            <x-ui::topbar home="/" title="Docs" nav-label="Utilities">
                <x-slot:nav><a href="/api">API</a></x-slot:nav>
            </x-ui::topbar>
        BLADE);

        $this->assertStringContainsString('API', $html);
        $this->assertStringContainsString('aria-label="Utilities"', $html);
    }

    public function test_renders_all_slots_in_order(): void
    {
        $html = Blade::render(<<<'BLADE'
            <x-ui::topbar home="/" title="App" nav-label="Main">
                <x-slot:brandExtra><span>EXTRA</span></x-slot:brandExtra>
                <x-slot:utilities><span>UTIL</span></x-slot:utilities>
                <x-slot:nav><span>NAV</span></x-slot:nav>
                <x-slot:profile><span>PROFILE</span></x-slot:profile>
            </x-ui::topbar>
        BLADE);

        $this->assertStringContainsString('EXTRA', $html);
        $this->assertLessThan(strpos($html, 'UTIL'), strpos($html, 'EXTRA'));
        $this->assertLessThan(strpos($html, 'NAV'), strpos($html, 'UTIL'));
        $this->assertLessThan(strpos($html, 'PROFILE'), strpos($html, 'NAV'));
    }

    public function test_navigation_label_sits_on_the_nav_element(): void
    {
        $html = Blade::render(<<<'BLADE'
            <x-ui::topbar home="/" title="App" nav-label="Main navigation">
                <x-slot:nav><span>NAV</span></x-slot:nav>
            </x-ui::topbar>
        BLADE);

        $this->assertMatchesRegularExpression('/<nav[^>]*aria-label="Main navigation"/', $html);
    }

    public function test_width_and_padding_are_owned_by_the_shared_container(): void
    {
        $html = Blade::render('<x-ui::topbar home="/" title="App" />');

        $this->assertSame(
            1,
            preg_match('/<div class="(ui-container[^"]*)"/', $html, $matches),
            'Topbar must render its inner container as a div carrying ui-container.'
        );

        // Отступы уехали внутрь `.ui-container`; собственных утилит у шапки быть не должно,
        // иначе левый край логотипа снова разойдётся с краем контента.
        $this->assertStringNotContainsString('px-4', $matches[1]);
        $this->assertStringNotContainsString('max-w-7xl', $matches[1]);
    }
}
