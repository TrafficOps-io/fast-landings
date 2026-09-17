<?php

namespace TrafficOps\FastLandings\Ui\Tests;

use TrafficOps\FastLandings\Ui\Support\JsonSyntaxHighlighter;

class JsonSyntaxHighlighterTest extends TestCase
{
    public function test_it_highlights_json_tokens(): void
    {
        $html = JsonSyntaxHighlighter::highlight([
            'message' => 'received',
            'attempts' => 2,
            'accepted' => true,
            'error' => null,
        ])->toHtml();

        $this->assertStringContainsString('<span class="ui-json-key">&quot;message&quot;</span>', $html);
        $this->assertStringContainsString('<span class="ui-json-string">&quot;received&quot;</span>', $html);
        $this->assertStringContainsString('<span class="ui-json-number">2</span>', $html);
        $this->assertStringContainsString('<span class="ui-json-boolean">true</span>', $html);
        $this->assertStringContainsString('<span class="ui-json-null">null</span>', $html);
    }

    public function test_it_escapes_json_content_before_returning_html(): void
    {
        $html = JsonSyntaxHighlighter::highlight([
            'payload' => '</code><script>alert("unsafe")</script>',
        ])->toHtml();

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;/code&gt;&lt;script&gt;', $html);
    }
}
