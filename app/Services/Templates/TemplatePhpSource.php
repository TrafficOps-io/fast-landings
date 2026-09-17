<?php

namespace App\Services\Templates;

/** Keep PHP source opaque to the template language without evaluating it. */
class TemplatePhpSource
{
    public static function protect(string $source, array &$fragments): string
    {
        $result = '';
        $php = '';
        $prefix = self::markerPrefix($source, $fragments);
        $flush = static function () use (&$result, &$php, &$fragments, $prefix): void {
            if ($php !== '') {
                foreach (preg_split('/(\r\n|\r|\n)/', $php, -1, PREG_SPLIT_DELIM_CAPTURE) as $part) {
                    if ($part === '' || in_array($part, ["\r\n", "\r", "\n"], true)) {
                        $result .= $part;

                        continue;
                    }
                    $key = $prefix.count($fragments)."\x1A";
                    $fragments[$key] = $part;
                    $result .= $key;
                }
                $php = '';
            }
        };
        foreach (token_get_all($source) as $token) {
            if (is_array($token) && $token[0] === T_INLINE_HTML) {
                $flush();
                $result .= $token[1];
            } else {
                $php .= is_array($token) ? $token[1] : $token;
            }
        }
        $flush();

        return $result;
    }

    private static function markerPrefix(string $source, array $fragments): string
    {
        $digest = substr(hash('sha256', $source), 0, 20);
        $attempt = 0;
        do {
            $prefix = "\x1APHP_{$digest}_{$attempt}_";
            $attempt++;
        } while (str_contains($source, $prefix) || self::hasMarkerPrefix($fragments, $prefix));

        return $prefix;
    }

    private static function hasMarkerPrefix(array $fragments, string $prefix): bool
    {
        foreach (array_keys($fragments) as $marker) {
            if (str_starts_with((string) $marker, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
