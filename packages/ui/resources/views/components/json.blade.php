@props(['value'])

<pre {{ $attributes->class('overflow-auto max-h-96 rounded-xl bg-base-200 p-4 text-xs leading-relaxed whitespace-pre-wrap break-all') }}><code class="ui-json language-json">{{ \TrafficOps\FastLandings\Ui\Support\JsonSyntaxHighlighter::highlight($value) }}</code></pre>
