<?php

namespace App\Services\Templates;

use Illuminate\Validation\ValidationException;
use TrafficOps\TemplateDsl\DirectiveExtraction;
use TrafficOps\TemplateDsl\LiteralRuntimeStrategy;
use TrafficOps\TemplateDsl\PreparedSource;
use TrafficOps\TemplateDsl\SourcePhase;
use TrafficOps\TemplateDsl\TemplateDialect;
use TrafficOps\TemplateDsl\TemplateRuntimeStrategy;
use TrafficOps\TemplateDsl\TemplateSourceSafety;

/**
 * Trusted-author dialect that preserves PHP and request-runtime declarations.
 *
 * Selecting this dialect is an application privilege decision. Template source
 * must never be allowed to select it.
 */
final class FastLandingsTemplateDialect implements TemplateDialect
{
    private LiteralRuntimeStrategy $runtimeStrategy;

    public function __construct(private TemplateRequestRuntime $requestRuntime)
    {
        $this->runtimeStrategy = new LiteralRuntimeStrategy;
    }

    public function id(): string
    {
        return 'fast-landings-v1';
    }

    public function assertSourcePath(string $path): void
    {
        TemplateSourceSafety::assertRelativePath($path);
    }

    public function assertEntrypoint(string $path): void
    {
        if (! in_array($path, ['index.html', 'index.php'], true)) {
            $this->invalid('The template entrypoint must be index.html or index.php.');
        }
    }

    public function assertPagePath(string $path): void
    {
        TemplateSourceSafety::assertRelativePath($path);
        if (! preg_match('/\.(?:html|php)$/D', $path)) {
            $this->invalid('Template page paths must be safe HTML or PHP output paths.');
        }
    }

    public function prepareSource(string $source, string $file, SourcePhase $phase): PreparedSource
    {
        if ($phase === SourcePhase::Renderer) {
            $validation = $this->requestRuntime->validationSource($source);
            $source = $this->requestRuntime->stripValidations($source);

            return $this->protect($source, $file, $phase, ['validation' => $validation]);
        }

        $text = '';
        $opaque = [];
        $offset = 0;
        $chunk = 0;
        foreach ($this->layoutBoundaries($source) as [$boundary, $position]) {
            $prepared = $this->protect(substr($source, $offset, $position - $offset), $file.'.'.$chunk, $phase);
            $text .= $prepared->text;
            $opaque += $prepared->opaque;
            $text .= $boundary;
            $offset = $position + strlen($boundary);
            $chunk++;
        }
        $prepared = $this->protect(substr($source, $offset), $file.'.'.$chunk, $phase);
        $text .= $prepared->text;
        $opaque += $prepared->opaque;

        return new PreparedSource($text, $opaque);
    }

    public function extractDirectives(array $tokens, string $entrypoint): DirectiveExtraction
    {
        $validations = [];
        $filtered = [];
        $page = $entrypoint;
        $contexts = [];
        $openers = [
            'previewData' => 'endpreviewData',
            'section' => 'endsection',
            'type' => 'endtype',
            'block' => 'endblock',
            'layout' => 'endlayout',
            'each' => 'endeach',
            'if' => 'endif',
        ];
        for ($index = 0; $index < count($tokens); $index++) {
            $token = $tokens[$index];
            if ($token['kind'] === '_page') {
                $page = $token['file'];
                $contexts = [];
            }
            if ($token['kind'] !== 'validation') {
                $filtered[] = $token;
                if (isset($openers[$token['kind']]) && ($token['kind'] !== 'previewData' || $token['args'] === '')) {
                    $contexts[] = $openers[$token['kind']];
                } elseif ($contexts !== [] && $token['kind'] === end($contexts) && $token['args'] === '') {
                    array_pop($contexts);
                }

                continue;
            }
            if ($contexts !== []) {
                $this->fail($token, '@validation must be declared at the top level.');
            }

            $declaration = $token['text'];
            $closed = false;
            while (++$index < count($tokens)) {
                $next = $tokens[$index];
                if ($next['kind'] === '_page') {
                    break;
                }
                $declaration .= $next['text'];
                if ($next['kind'] === 'endvalidation' && $next['args'] === '') {
                    $closed = true;
                    break;
                }
            }
            if (! $closed) {
                $this->fail($token, 'Missing @endvalidation.');
            }
            $this->requestRuntime->validate($declaration);
            $validations[$page] = ($validations[$page] ?? '').$declaration;
        }

        return new DirectiveExtraction($filtered, ['validations' => $validations]);
    }

    public function finishParsedPage(string $page, string $html, DirectiveExtraction $extraction, array $opaque): string
    {
        $html = ($extraction->pageMetadata['validations'][$page] ?? '').strtr($html, $opaque);
        $this->requestRuntime->validate($html);

        return $html;
    }

    public function validateSource(string $source, string $file = 'template'): void
    {
        $this->requestRuntime->validate($source);
    }

    public function runtime(): TemplateRuntimeStrategy
    {
        return $this->runtimeStrategy;
    }

    public function validationSample(string $fieldType, string $value): string
    {
        return preg_replace(
            '/(?<!\{)\{(?:query|headers|body)\.(?:[A-Za-z0-9_][A-Za-z0-9_-]*(?:\.[A-Za-z0-9_][A-Za-z0-9_-]*)*|\*)\}(?!\})/',
            'runtime-value',
            $value,
        );
    }

    public function finishRendered(string $output, PreparedSource $main, array $partials, array $usedPartials): string
    {
        $validation = (string) ($main->metadata['validation'] ?? '');
        foreach (array_keys($usedPartials) as $name) {
            $validation .= (string) ($partials[$name]->metadata['validation'] ?? '');
        }

        // Values may contain macro text, but only author source may declare
        // validation. Shield PHP while escaping synthesized directives.
        $opaque = [];
        $output = TemplatePhpSource::protect($output, $opaque);
        $output = preg_replace('/^([\t ]*)@(validation|endvalidation)\b/m', '$1&#64;$2', $output);
        $result = $validation.strtr($output, $opaque);
        $this->requestRuntime->validate($result);

        return $result;
    }

    private function protect(string $source, string $file, SourcePhase $phase, array $metadata = []): PreparedSource
    {
        $fragments = [];
        $text = TemplatePhpSource::protect($source, $fragments);
        if ($fragments === []) {
            return new PreparedSource($text, [], $metadata);
        }

        $digest = substr(hash('sha256', $phase->value."\0".$file."\0".$source), 0, 20);
        $attempt = 0;
        do {
            $prefix = "\x1ATDSL_{$digest}_{$attempt}_";
            $attempt++;
        } while (str_contains($source, $prefix));
        $replace = [];
        $opaque = [];
        foreach ($fragments as $marker => $fragment) {
            $key = $prefix.count($opaque)."\x1A";
            $replace[$marker] = $key;
            $opaque[$key] = $fragment;
        }

        return new PreparedSource(strtr($text, $replace), $opaque, $metadata);
    }

    /**
     * Locate DSL layout boundaries without treating their text inside PHP
     * strings, heredocs or comments as template directives. A standalone
     * boundary in ordinary PHP tokens stays visible so pure-PHP layouts may
     * deliberately leave their final PHP block open.
     *
     * @return array<int, array{string, int}>
     */
    private function layoutBoundaries(string $source): array
    {
        $opaqueRanges = [];
        $offset = 0;
        foreach (token_get_all($source) as $token) {
            $text = is_array($token) ? $token[1] : $token;
            $end = $offset + strlen($text);
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT, T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
                $opaqueRanges[] = [$offset, $end];
            }
            $offset = $end;
        }

        preg_match_all('/^[\t ]*@(?:layout|endlayout)[\t ]*\r?(?=\n|\z)/m', $source, $matches, PREG_OFFSET_CAPTURE);
        $boundaries = [];
        $range = 0;
        foreach ($matches[0] as [$boundary, $position]) {
            while (isset($opaqueRanges[$range]) && $opaqueRanges[$range][1] <= $position) {
                $range++;
            }
            if (! isset($opaqueRanges[$range]) || $position < $opaqueRanges[$range][0]) {
                $boundaries[] = [$boundary, $position];
            }
        }

        return $boundaries;
    }

    private function fail(array $token, string $message): never
    {
        throw ValidationException::withMessages(['template' => "{$token['file']}:{$token['line']}: {$message}"]);
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['template' => $message]);
    }
}
