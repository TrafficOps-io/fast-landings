<?php

namespace TrafficOps\FastLandings\Ui\Tests;

use Illuminate\Support\Facades\Blade;

class PanelAndListTest extends TestCase
{
    public function test_panel_renders_header_only_when_titled(): void
    {
        $titled = Blade::render(<<<'BLADE'
            <x-ui::panel title="Hosts" description="Domains serving this campaign">
                <x-slot:actions><button>Add</button></x-slot:actions>
                BODY
            </x-ui::panel>
        BLADE);

        $this->assertStringContainsString('ui-panel', $titled);
        $this->assertStringContainsString('Hosts', $titled);
        $this->assertStringContainsString('Domains serving this campaign', $titled);
        $this->assertStringContainsString('Add', $titled);
        $this->assertStringContainsString('BODY', $titled);

        $bare = Blade::render('<x-ui::panel>BODY</x-ui::panel>');

        $this->assertStringContainsString('ui-panel', $bare);
        $this->assertStringContainsString('BODY', $bare);
        $this->assertStringNotContainsString('<h2', $bare);
    }

    public function test_list_renders_a_divided_unordered_list(): void
    {
        $html = Blade::render('<x-ui::list><li>ROW</li></x-ui::list>');

        $this->assertStringContainsString('<ul', $html);
        $this->assertStringContainsString('divide-y', $html);
        $this->assertStringContainsString('ROW', $html);
    }

    public function test_list_row_links_its_title_when_href_given(): void
    {
        $html = Blade::render(<<<'BLADE'
            <x-ui::list-row href="/campaigns/1">
                <x-slot:title>DE traffic</x-slot:title>
                <x-slot:meta>12 installs</x-slot:meta>
                <x-slot:actions><a href="/x">Hosts</a></x-slot:actions>
            </x-ui::list-row>
        BLADE);

        $this->assertStringContainsString('<li', $html);
        $this->assertStringContainsString('href="/campaigns/1"', $html);
        $this->assertStringContainsString('wire:navigate', $html);
        $this->assertStringContainsString('DE traffic', $html);
        $this->assertStringContainsString('12 installs', $html);
        $this->assertStringContainsString('Hosts', $html);
    }

    public function test_list_row_without_href_renders_plain_title(): void
    {
        $html = Blade::render(<<<'BLADE'
            <x-ui::list-row><x-slot:title>en-US</x-slot:title></x-ui::list-row>
        BLADE);

        $this->assertStringContainsString('en-US', $html);
        $this->assertStringNotContainsString('<a ', $html);
    }

    public function test_empty_state_renders_title_description_and_action(): void
    {
        $html = Blade::render(<<<'BLADE'
            <x-ui::empty-state title="No campaigns yet" description="Create your first one.">
                <x-slot:action><a href="/new">New campaign</a></x-slot:action>
            </x-ui::empty-state>
        BLADE);

        $this->assertStringContainsString('No campaigns yet', $html);
        $this->assertStringContainsString('Create your first one.', $html);
        $this->assertStringContainsString('New campaign', $html);
    }

    public function test_empty_state_description_and_action_are_optional(): void
    {
        $html = Blade::render('<x-ui::empty-state title="Nothing here" />');

        $this->assertStringContainsString('Nothing here', $html);
    }
}
