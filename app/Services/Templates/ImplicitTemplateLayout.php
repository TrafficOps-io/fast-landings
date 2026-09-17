<?php

namespace App\Services\Templates;

/** Add the application-level implicit layout accepted by Fast Landings. */
final class ImplicitTemplateLayout
{
    private const EXPLICIT_LAYOUT = '/^[\t ]*@layout[\t ]*$/m';

    private const PREFIX_DIRECTIVES = ['include', 'param', 'template'];

    private const OPENERS = [
        'block' => 'endblock',
        'previewData' => 'endpreviewData',
        'section' => 'endsection',
        'type' => 'endtype',
        'validation' => 'endvalidation',
    ];

    private const NESTED_OPENERS = [
        ...self::OPENERS,
        'each' => 'endeach',
        'if' => 'endif',
        'layout' => 'endlayout',
    ];

    public function wrap(string $source, string $page): string
    {
        if (in_array($page, ['template.html', 'template.txt', 'template.tpl'], true)) {
            return $source;
        }

        $source = str_replace(["\r\n", "\r"], "\n", $source);
        $php = [];
        $protected = TemplatePhpSource::protect($source, $php);
        if (preg_match(self::EXPLICIT_LAYOUT, $protected)) {
            return $source;
        }

        $sourceLines = explode("\n", $source);
        $protectedLines = explode("\n", $protected);
        $prefixLines = $this->prefixLines($protectedLines);
        $prefix = implode("\n", array_slice($sourceLines, 0, $prefixLines));
        $body = implode("\n", array_slice($sourceLines, $prefixLines));

        if ($prefix !== '' && ! str_ends_with($prefix, "\n")) {
            $prefix .= "\n";
        }
        if ($body !== '' && ! str_ends_with($body, "\n")) {
            $body .= "\n";
        }

        return $prefix."@layout\n".$body."@endlayout\n";
    }

    /** @param list<string> $lines */
    private function prefixLines(array $lines): int
    {
        $stack = [];
        foreach ($lines as $index => $line) {
            if (trim($line) === '') {
                continue;
            }
            if (! preg_match('/^[\t ]*@([A-Za-z][A-Za-z0-9_-]*)\b/', $line, $match)) {
                return $index;
            }

            $directive = $match[1];
            if ($stack !== []) {
                if (isset(self::NESTED_OPENERS[$directive])) {
                    $stack[] = self::NESTED_OPENERS[$directive];
                } elseif ($directive === end($stack)) {
                    array_pop($stack);
                }

                continue;
            }

            if (in_array($directive, self::PREFIX_DIRECTIVES, true)) {
                continue;
            }
            if (isset(self::OPENERS[$directive])) {
                $stack[] = self::OPENERS[$directive];

                continue;
            }

            return $index;
        }

        return count($lines);
    }
}
