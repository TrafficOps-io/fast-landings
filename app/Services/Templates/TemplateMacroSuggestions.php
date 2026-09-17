<?php

namespace App\Services\Templates;

/** Request paths available to content editors across all pages in a template. */
class TemplateMacroSuggestions
{
    public function forDefinition(array $definition): array
    {
        $suggestions = [];
        $sources = [$definition['html'] ?? '', ...array_values($definition['pages'] ?? []), ...array_values($definition['partials'] ?? [])];
        foreach ($sources as $source) {
            foreach (app(TemplateRequestRuntime::class)->declarations($source) as $parameter) {
                $name = $parameter['source'].'.'.$parameter['name'];
                $suggestions[$name] = [
                    'token' => '{'.$name.'}',
                    'source' => $parameter['source'],
                    'label' => $parameter['type'].(($parameter['required'] ?? false) ? ' · required' : ' · optional'),
                ];
            }
        }
        $fieldDefaults = function (array $fields) use (&$fieldDefaults, &$sources): void {
            foreach ($fields as $field) {
                if (is_string($field['default'] ?? null)) {
                    $sources[] = $field['default'];
                }
                $fieldDefaults($field['fields'] ?? []);
            }
        };
        foreach ($definition['sections'] ?? [] as $section) {
            $fieldDefaults($section['fields']);
        }
        foreach ($sources as $source) {
            $opaque = [];
            $markup = TemplatePhpSource::protect($source, $opaque);
            preg_match_all('/(?<![\\\\{])\{((query|headers|body)\.[A-Za-z0-9_*][A-Za-z0-9_.*-]*)\}(?!\})/', $markup, $matches, PREG_SET_ORDER);
            foreach ($matches as $match) {
                $suggestions[$match[1]] ??= ['token' => '{'.$match[1].'}', 'source' => $match[2], 'label' => 'Request value used in this template'];
            }
        }
        foreach (['query' => 'URL query parameters', 'body' => 'Submitted form or JSON body', 'headers' => 'HTTP request headers'] as $source => $label) {
            $suggestions[$source.'.*'] ??= ['token' => '{'.$source.'.*}', 'source' => $source, 'label' => $label.' · JSON'];
        }
        foreach (['user-agent', 'referer', 'accept-language', 'content-type'] as $header) {
            $suggestions['headers.'.$header] ??= ['token' => '{headers.'.$header.'}', 'source' => 'headers', 'label' => 'HTTP request header'];
        }

        return array_values($suggestions);
    }
}
