<?php

namespace App\Services\Templates;

use Illuminate\Validation\ValidationException;
use TrafficOps\Macros\StandaloneRenderer;
use TrafficOps\Macros\Syntax;

/** Compile request values to dependency-free PHP for the isolated landing runtime. */
class TemplateRequestRuntime
{
    public function macroPattern(bool $escaped = true): string
    {
        return Syntax::request()->pattern($escaped);
    }

    public function hasRuntime(string $source): bool
    {
        $opaque = [];
        $source = TemplatePhpSource::protect($source, $opaque);

        return preg_match($this->macroPattern(), $source) === 1
            || preg_match('/^\h*@(?:validation|endvalidation)\b/m', $source) === 1;
    }

    public function validate(string $source): void
    {
        [$markup] = $this->parse($source);
        $this->validateContexts($markup);
    }

    /** Flat declaration metadata shared by editor completion and field forms. */
    public function declarations(string $source): array
    {
        [, $blocks] = $this->parse($source);
        $declarations = [];
        foreach ($blocks as $block) {
            foreach ($block['params'] as $parameter) {
                $declarations[] = ['source' => $block['source'], 'name' => $parameter['path'], ...$parameter, 'fallback' => $block['fallback']];
            }
        }

        return $declarations;
    }

    public function stripValidations(string $source): string
    {
        [$markup, , $opaque] = $this->parse($source);

        return strtr($markup, $opaque);
    }

    /** Keep trusted rules separate while configurable settings are rendered. */
    public function validationSource(string $source): string
    {
        [, , , $validations] = $this->parse($source);

        return $validations;
    }

    public function compile(string $source): string
    {
        if (! $this->hasRuntime($source)) {
            return $source;
        }
        [$markup, $blocks, $opaque] = $this->parse($source);
        $this->validateContexts($markup);
        $variable = '$__fl_request_'.substr(hash('sha256', $source), 0, 16);
        $markup = preg_replace_callback($this->macroPattern(), static fn (array $match): string => $match[1] === '\\'
            ? substr($match[0], 1)
            : '<?php echo '.$variable.'('.var_export('{'.$match[2].'}', true).'); ?>', $markup);
        $markup = strtr($markup, $opaque);
        $runtime = str_replace(['__REQUEST_RENDERER__', '__VALIDATION_BLOCKS__', '__SHARED_RENDERER__'], [$variable, var_export($blocks, true), StandaloneRenderer::source()], $this->runtime());

        // PHP requires leading declare/namespace statements before executable code.
        $prefix = $this->phpDeclarationPrefix($markup);
        if ($prefix !== 0) {
            return substr($markup, 0, $prefix)."\n".$runtime.substr($markup, $prefix);
        }

        return "<?php\n".$runtime.'?>'.$markup;
    }

    private function phpDeclarationPrefix(string $source): int
    {
        if (strncasecmp($source, '<?php', 5) !== 0) {
            return 0;
        }
        $offset = 0;
        $prefix = 0;
        $declaration = false;
        foreach (token_get_all($source) as $token) {
            $text = is_array($token) ? $token[1] : $token;
            $kind = is_array($token) ? $token[0] : null;
            if (! $declaration && ! in_array($kind, [T_OPEN_TAG, T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                if (! in_array($kind, [T_DECLARE, T_NAMESPACE], true)) {
                    break;
                }
                $declaration = true;
            }
            $offset += strlen($text);
            if ($declaration && in_array($text, [';', '{'], true)) {
                $prefix = $offset;
                $declaration = false;
            }
        }

        return $prefix;
    }

    /** HTML escaping is safe in text/quoted attributes, but not JS, CSS or arbitrary URLs. */
    private function validateContexts(string $markup): void
    {
        if (preg_match($this->macroPattern(), $markup) !== 1) {
            return;
        }
        $offset = 0;
        $size = strlen($markup);
        while (($start = strpos($markup, '<', $offset)) !== false) {
            if (substr($markup, $start, 4) === '<!--') {
                // HTML also closes empty comments abruptly and accepts --!>.
                // Skipping those endings could hide a following script/event attribute.
                if (substr($markup, $start + 4, 1) === '>') {
                    $offset = $start + 5;
                } elseif (substr($markup, $start + 4, 2) === '->') {
                    $offset = $start + 6;
                } elseif (preg_match('/--!?>/', $markup, $closing, PREG_OFFSET_CAPTURE, $start + 4)) {
                    $offset = $closing[0][1] + strlen($closing[0][0]);
                } else {
                    $offset = $size;
                }

                continue;
            }
            if (substr($markup, $start, 2) === '<!') {
                // Bogus declarations are HTML comments ending at the first >;
                // quotes in them never conceal the following HTML markup.
                $end = strpos($markup, '>', $start + 2);
                $offset = $end === false ? $size : $end + 1;

                continue;
            }
            if (! preg_match('/[A-Za-z\/{!]/', $markup[$start + 1] ?? '')) {
                $offset = $start + 1;

                continue;
            }
            if (! preg_match('/\G<\/?([a-z][a-z0-9:_.-]*)(?=[\t\r\n\f \/>])/i', $markup, $name, 0, $start)) {
                $this->fail(substr_count(substr($markup, 0, $start), "\n") + 1, 'Runtime macros require well-formed static HTML tag names.');
            }
            $tagName = strtolower($name[1]);
            $quote = null;
            $waitingForValue = false;
            $unquotedValue = false;
            $end = $start + 1;
            for (; $end < $size; $end++) {
                $character = $markup[$end];
                if ($quote !== null) {
                    if ($character === $quote) {
                        $quote = null;
                    }
                } elseif ($character === '>') {
                    break;
                } elseif ($unquotedValue) {
                    $unquotedValue = ! str_contains(" \t\r\n\f", $character);
                } elseif ($waitingForValue) {
                    if (! str_contains(" \t\r\n\f", $character)) {
                        $waitingForValue = false;
                        if ($character === '"' || $character === "'") {
                            $quote = $character;
                        } else {
                            $unquotedValue = true;
                        }
                    }
                } elseif ($character === '=') {
                    $waitingForValue = true;
                }
            }
            $tag = substr($markup, $start, $end - $start + 1);
            $attributes = [];
            preg_match_all('/([^\s=<>\x27"\/]+)\s*=\s*("[^"]*"|\x27[^\x27]*\x27|[^\s>]+)/', $tag, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
            foreach ($matches as $match) {
                // Browsers retain the first occurrence of a duplicate attribute.
                $attributes[strtolower($match[1][0])] ??= ['value' => $match[2][0], 'offset' => $match[2][1]];
            }
            preg_match_all($this->macroPattern(), $tag, $macros, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
            foreach ($macros as $macro) {
                if ($macro[1][0] === '\\') {
                    continue;
                }
                $line = substr_count(substr($markup, 0, $start), "\n") + 1;
                if (in_array($tagName, ['animate', 'set', 'animatemotion', 'animatetransform', 'animatecolor'], true)) {
                    $this->fail($line, 'Runtime macros cannot control SVG animation attributes. Use a quoted HTML data attribute instead.');
                }
                $attribute = null;
                $value = '';
                foreach ($attributes as $key => $candidate) {
                    if (in_array($candidate['value'][0], ['"', "'"], true) && $macro[0][1] > $candidate['offset'] && $macro[0][1] + strlen($macro[0][0]) < $candidate['offset'] + strlen($candidate['value'])) {
                        $attribute = $key;
                        $value = substr($candidate['value'], 1, -1);
                        break;
                    }
                }
                if ($attribute === null || str_starts_with($attribute, 'on') || in_array($attribute, ['style', 'srcdoc', 'srcset'], true)) {
                    $this->fail($line, 'Runtime macros require HTML text or a quoted attribute; script, style, event handlers, srcdoc and srcset are unsupported.');
                }
                if ($tagName === 'meta' && $attribute === 'content' && strtolower(trim(html_entity_decode(trim($attributes['http-equiv']['value'] ?? '', "\"'"), ENT_QUOTES | ENT_HTML5, 'UTF-8'))) === 'refresh') {
                    $this->fail($line, 'Runtime macros cannot control a meta refresh URL.');
                }
                if (in_array($attribute, ['href', 'src', 'action', 'formaction', 'poster', 'background', 'xlink:href', 'data', 'codebase', 'cite', 'longdesc', 'manifest'], true)) {
                    $prefix = substr($value, 0, strpos($value, $macro[0][0]));
                    $prefix = html_entity_decode($prefix, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    if (! preg_match('~^(?:https?://|/|\./|\.\./|\#|\?|[^:?#]+[/?#])~i', $prefix) || preg_match('/[\x00-\x20\x7f\\\\]/', $prefix)) {
                        $this->fail($line, 'A URL containing runtime macros requires a static http(s) scheme or local path prefix.');
                    }
                }
            }
            $offset = $end + 1;
            if (in_array($tagName, ['script', 'style'], true) && ! str_starts_with($tag, '</')) {
                preg_match('~</'.preg_quote($tagName, '~').'\s*>~i', $markup, $closing, PREG_OFFSET_CAPTURE, $offset);
                $contentEnd = $closing[0][1] ?? $size;
                $content = substr($markup, $offset, $contentEnd - $offset);
                preg_match_all($this->macroPattern(), $content, $macros, PREG_SET_ORDER);
                foreach ($macros as $macro) {
                    if ($macro[1] !== '\\') {
                        $this->fail(substr_count(substr($markup, 0, $start), "\n") + 1, 'Runtime macros inside script or style require a quoted HTML data attribute instead.');
                    }
                }
                $offset = isset($closing[0]) ? $contentEnd + strlen($closing[0][0]) : $size;
            }
        }
    }

    private function parse(string $source): array
    {
        $opaque = [];
        $source = TemplatePhpSource::protect($source, $opaque);
        $blocks = [];
        $block = null;
        $markup = '';
        $seen = [];
        $validationSource = '';
        $blockSource = '';
        foreach (preg_split('/(?<=\n)/', $source) as $index => $line) {
            $number = $index + 1;
            if (preg_match('/^\h*@validation\b(.*?)[\r\n]*$/', $line, $match)) {
                if ($block !== null) {
                    $this->fail($number, 'Validation blocks cannot nest.');
                }
                $arguments = $this->arguments(trim($match[1]), $number);
                $kind = array_shift($arguments);
                if (! in_array($kind, ['query', 'headers', 'body'], true)) {
                    $this->fail($number, '@validation expects query, headers or body.');
                }
                $options = $this->options($arguments, $number);
                if (array_diff(array_keys($options), ['fallback'])) {
                    $this->fail($number, '@validation accepts only fallback="/local-path".');
                }
                $fallback = $options['fallback'] ?? null;
                if ($fallback !== null && ! $this->localFallback($fallback)) {
                    $this->fail($number, 'Validation fallback must be a local absolute or relative URL without control characters.');
                }
                $block = ['source' => $kind, 'fallback' => $fallback, 'params' => []];
                $blockSource = $line;
            } elseif (preg_match('/^\h*@endvalidation\b(.*?)[\r\n]*$/', $line, $match)) {
                if ($block === null || trim($match[1]) !== '') {
                    $this->fail($number, 'Unexpected @endvalidation.');
                }
                if ($block['params'] === []) {
                    $this->fail($number, 'A validation block requires at least one @param.');
                }
                $blocks[] = $block;
                $validationSource .= rtrim($blockSource.$line, "\r\n")."\n";
                $block = null;
            } elseif ($block !== null) {
                $blockSource .= $line;
                if (trim($line) !== '') {
                    if (! preg_match('/^\h*@param\h+(.+?)[\r\n]*$/', $line, $match)) {
                        $this->fail($number, 'Only @param declarations are allowed inside @validation.');
                    }
                    $parameter = $this->parameter($match[1], $number);
                    $key = $block['source'].'.'.($block['source'] === 'headers' ? strtolower($parameter['path']) : $parameter['path']);
                    if (isset($seen[$key])) {
                        $this->fail($number, "Duplicate request parameter {$key}.");
                    }
                    $seen[$key] = true;
                    $block['params'][] = $parameter;
                }
            } else {
                $markup .= $line;
            }
        }
        if ($block !== null) {
            $this->fail(count($blocks) + 1, 'Missing @endvalidation.');
        }

        return [$markup, $blocks, $opaque, $validationSource];
    }

    private function parameter(string $text, int $line): array
    {
        $arguments = $this->arguments($text, $line);
        $path = array_shift($arguments);
        $type = array_shift($arguments);
        if (! is_string($path) || $path === '*' || Syntax::request()->whole('{body.'.$path.'}') === null) {
            $this->fail($line, 'A request parameter needs a name or dotted path.');
        }
        if (! in_array($type, ['String', 'Number', 'Integer', 'Boolean'], true)) {
            $this->fail($line, 'Request parameter types are String, Number, Integer and Boolean.');
        }
        $options = $this->options($arguments, $line);
        if (array_diff(array_keys($options), ['required', 'min', 'max', 'length', 'mask'])) {
            $this->fail($line, 'Request parameters accept required, min, max, length and mask.');
        }
        if (isset($options['required']) && ! is_bool($options['required'])) {
            $this->fail($line, 'The required option must be true or false.');
        }
        foreach (['min', 'max', 'length'] as $rule) {
            if (! isset($options[$rule])) {
                continue;
            }
            if (! is_string($options[$rule]) || ! is_numeric($options[$rule]) || ! is_finite((float) $options[$rule])) {
                $this->fail($line, "{$rule} must be a finite number.");
            }
            $options[$rule] += 0;
            if (($type === 'String' || $rule === 'length') && (! is_int($options[$rule]) || $options[$rule] < 0)) {
                $this->fail($line, "{$rule} must be a non-negative integer for string lengths.");
            }
        }
        if (($type !== 'String' && (isset($options['length']) || isset($options['mask']))) || ($type === 'Boolean' && (isset($options['min']) || isset($options['max'])))) {
            $this->fail($line, 'length and mask apply to String; min and max apply to String, Number or Integer.');
        }
        if (isset($options['min'], $options['max']) && $options['min'] > $options['max']) {
            $this->fail($line, 'min cannot exceed max.');
        }
        if (isset($options['length']) && (($options['min'] ?? 0) > $options['length'] || ($options['max'] ?? $options['length']) < $options['length'])) {
            $this->fail($line, 'length must lie between min and max.');
        }
        if (isset($options['mask']) && ($options['mask'] === '' || strlen($options['mask']) > 1000 || preg_match('//u', $options['mask']) !== 1 || str_contains($options['mask'], "\0"))) {
            $this->fail($line, 'mask must be a non-empty UTF-8 string of at most 1000 bytes.');
        }

        return ['path' => $path, 'type' => $type, 'required' => false, ...$options];
    }

    private function arguments(string $text, int $line): array
    {
        $arguments = [];
        $offset = 0;
        while ($offset < strlen($text)) {
            if (! preg_match('/\G\s*("(?:[^"\\\\]|\\\\.)*"|\x27(?:[^\x27\\\\]|\\\\.)*\x27|=|[^\s=\x27"]+)/', $text, $match, 0, $offset)) {
                if (trim(substr($text, $offset)) === '') {
                    break;
                }
                $this->fail($line, 'Invalid or unclosed quoted argument.');
            }
            $value = $match[1];
            $arguments[] = str_starts_with($value, '"') || str_starts_with($value, "'") ? stripcslashes(substr($value, 1, -1)) : $value;
            $offset += strlen($match[0]);
        }

        return $arguments;
    }

    private function options(array $arguments, int $line): array
    {
        $options = [];
        while ($arguments !== []) {
            $key = array_shift($arguments);
            $key = $key === 'lenght' ? 'length' : $key;
            if (array_key_exists($key, $options)) {
                $this->fail($line, "Duplicate option {$key}.");
            }
            if ($key === 'required' && ($arguments[0] ?? null) !== '=') {
                $options[$key] = true;

                continue;
            }
            if (array_shift($arguments) !== '=' || $arguments === []) {
                $this->fail($line, 'Options use key=value.');
            }
            $value = array_shift($arguments);
            $options[$key] = $key === 'required' ? match ($value) {
                'true' => true, 'false' => false, default => $value,
            } : $value;
        }

        return $options;
    }

    private function localFallback(string $value): bool
    {
        for ($i = 0; $i < 4; $i++) {
            if ($value === '' || preg_match('/[\x00-\x20\x7f\\\\]/', $value) || str_starts_with($value, '//') || preg_match('/^[^\/?#]*:/', $value)) {
                return false;
            }
            $decoded = rawurldecode($value);
            if ($decoded === $value) {
                return true;
            }
            $value = $decoded;
        }

        return false;
    }

    private function fail(int $line, string $message): never
    {
        throw ValidationException::withMessages(['template' => "Request validation:{$line}: {$message}"]);
    }

    private function runtime(): string
    {
        return <<<'PHP'
__REQUEST_RENDERER__ = (static function (): \Closure {
    $headers = [];
    if (function_exists('getallheaders')) {
        foreach ((getallheaders() ?: []) as $name => $value) {
            $headers[strtolower((string) $name)] = $value;
        }
    }
    foreach ($_SERVER as $name => $value) {
        if (str_starts_with($name, 'HTTP_')) {
            $headers[strtolower(str_replace('_', '-', substr($name, 5)))] = $value;
        } elseif (in_array($name, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true)) {
            $headers[strtolower(str_replace('_', '-', $name))] = $value;
        }
    }
    $body = $_POST;
    $invalidBody = false;
    $contentType = strtolower(trim(explode(';', (string) ($headers['content-type'] ?? ''))[0]));
    if ($contentType === 'application/json' || preg_match('~^application/[a-z0-9!#$&^_.+-]+\+json$~D', $contentType)) {
        $raw = file_get_contents('php://input');
        $body = json_decode($raw === false ? '' : $raw, false, 64, JSON_BIGINT_AS_STRING);
        $invalidBody = json_last_error() !== JSON_ERROR_NONE || (! is_array($body) && ! $body instanceof \stdClass);
        $body = $invalidBody ? [] : $body;
    }
    $sources = ['query' => $_GET, 'headers' => $headers, 'body' => $body];
    $renderer = (__SHARED_RENDERER__)($sources);
    foreach (__VALIDATION_BLOCKS__ as $block) {
        $valid = ! ($block['source'] === 'body' && $invalidBody);
        foreach ($block['params'] as $rule) {
            $path = $block['source'] === 'headers' ? strtolower($rule['path']) : $rule['path'];
            $value = $renderer->value($block['source'], $path);
            if ($value === null || (is_string($value) && trim($value) === '')) {
                $valid = $valid && ! $rule['required'];
                continue;
            }
            $numeric = (is_int($value) || is_float($value) || is_string($value)) && is_numeric($value) && is_finite((float) $value);
            $typed = match ($rule['type']) {
                'String' => is_string($value) && preg_match('//u', $value) === 1,
                'Number' => $numeric,
                'Integer' => is_int($value) || (is_string($value) && $numeric && preg_match('/^[+-]?[0-9]+$/D', $value) === 1),
                'Boolean' => is_bool($value) || in_array($value, [0, 1, '0', '1', 'true', 'false'], true),
                default => false,
            };
            if (! $typed) {
                $valid = false;
                continue;
            }
            $measure = $rule['type'] === 'String' ? preg_match_all('/./us', $value) : (float) $value;
            if ((isset($rule['min']) && $measure < $rule['min'])
                || (isset($rule['max']) && $measure > $rule['max'])
                || (isset($rule['length']) && $measure !== $rule['length'])
                || (isset($rule['mask']) && preg_match('~\A'.str_replace('\\.', '[0-9]', preg_quote($rule['mask'], '~')).'\z~u', $value) !== 1)) {
                $valid = false;
            }
        }
        if (! $valid) {
            if ($block['fallback'] !== null) {
                header('Location: '.$block['fallback'], true, in_array(strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'), ['GET', 'HEAD'], true) ? 302 : 303);
            } else {
                http_response_code(422);
                header('Content-Type: text/plain; charset=UTF-8');
                echo 'Invalid request.';
            }
            exit;
        }
    }

    return static fn (string $template): string => $renderer->render($template);
})();

PHP;
    }
}
