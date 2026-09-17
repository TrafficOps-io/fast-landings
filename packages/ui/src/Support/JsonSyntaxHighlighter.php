<?php

namespace TrafficOps\FastLandings\Ui\Support;

use Illuminate\Support\HtmlString;

final class JsonSyntaxHighlighter
{
    private const TOKEN_PATTERN = '~"(?:\\\\.|[^"\\\\])*"|-?(?:0|[1-9]\\d*)(?:\\.\\d+)?(?:[eE][+-]?\\d+)?|\\b(?:true|false|null)\\b~u';

    public static function highlight(mixed $value): HtmlString
    {
        $json = json_encode(
            $value,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE,
        );

        if ($json === false) {
            return new HtmlString('');
        }

        preg_match_all(self::TOKEN_PATTERN, $json, $matches, PREG_OFFSET_CAPTURE);

        $highlighted = '';
        $offset = 0;

        foreach ($matches[0] as [$token, $tokenOffset]) {
            $highlighted .= self::escape(substr($json, $offset, $tokenOffset - $offset));
            $highlighted .= sprintf(
                '<span class="%s">%s</span>',
                self::tokenClass($token, substr($json, $tokenOffset + strlen($token))),
                self::escape($token),
            );
            $offset = $tokenOffset + strlen($token);
        }

        $highlighted .= self::escape(substr($json, $offset));

        return new HtmlString($highlighted);
    }

    private static function tokenClass(string $token, string $remainder): string
    {
        if (str_starts_with($token, '"')) {
            return preg_match('/^\\s*:/', $remainder) === 1 ? 'ui-json-key' : 'ui-json-string';
        }

        return match ($token) {
            'true', 'false' => 'ui-json-boolean',
            'null' => 'ui-json-null',
            default => 'ui-json-number',
        };
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
