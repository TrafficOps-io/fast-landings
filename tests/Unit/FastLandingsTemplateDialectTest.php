<?php

namespace Tests\Unit;

use App\Services\Templates\FastLandingsTemplateDialect;
use App\Services\Templates\ImplicitTemplateLayout;
use App\Services\Templates\TemplatePhpSource;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use TrafficOps\TemplateDsl\TemplateDialect;
use TrafficOps\TemplateDsl\TemplateEngine;
use TrafficOps\TemplateDsl\TemplateSourceParser;

class FastLandingsTemplateDialectTest extends TestCase
{
    public function test_application_explicitly_selects_the_trusted_dialect_for_both_core_services(): void
    {
        $dialect = app(TemplateDialect::class);

        $this->assertInstanceOf(FastLandingsTemplateDialect::class, $dialect);
        $this->assertSame('fast-landings-v1', $dialect->id());
        $this->assertSame(
            "@validation query\n@param campaign String required\n@endvalidation\n<?php echo 'trusted'; ?><p>{query.campaign}</p>",
            app(TemplateEngine::class)->render(
                app(TemplateEngine::class)->validateDefinition([
                    'version' => 1,
                    'name' => 'Trusted dialect',
                    'entrypoint' => 'index.php',
                    'sections' => [],
                    'html' => "@validation query\n@param campaign String required\n@endvalidation\n<?php echo 'trusted'; ?><p>{query.campaign}</p>",
                ]),
                [],
            ),
        );

        $parsed = app(TemplateSourceParser::class)->parse(
            "@validation body\n@param email String required\n@endvalidation\n@layout\n<?php echo '{{literal}}'; ?><p>{body.email}</p>\n@endlayout",
            filename: 'index.tpl.php',
        );
        $this->assertStringContainsString("<?php echo '{{literal}}'; ?>", $parsed['html']);
        $this->assertStringStartsWith('@validation body', $parsed['html']);
    }

    public function test_trusted_dialect_still_cannot_bypass_core_traversal_checks(): void
    {
        $called = false;
        $this->expectException(ValidationException::class);
        try {
            app(TemplateSourceParser::class)->parse(
                "@include \"../secret.tpl.php\"\n@layout\nPage\n@endlayout",
                function () use (&$called): string {
                    $called = true;

                    return '';
                },
                'index.tpl.php',
            );
        } finally {
            $this->assertFalse($called);
        }
    }

    public function test_render_rejects_duplicate_request_parameters_across_main_and_used_partials(): void
    {
        $definition = app(TemplateEngine::class)->validateDefinition([
            'version' => 1,
            'name' => 'Conflicting validation',
            'entrypoint' => 'index.php',
            'sections' => [],
            'html' => "@validation query\n@param campaign String required\n@endvalidation\n{{>form}}",
            'partials' => [
                'form' => "@validation query\n@param campaign String\n@endvalidation\n<form>{query.campaign}</form>",
            ],
        ]);

        try {
            app(TemplateEngine::class)->render($definition, []);
            $this->fail('Conflicting validation declarations must fail during rendering.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('Duplicate request parameter query.campaign.', $exception->errors()['template'][0]);
        }
    }

    #[DataProvider('nestedValidationContainers')]
    public function test_request_validation_must_be_declared_at_the_top_level(string $open, string $close): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('@validation must be declared at the top level.');

        app(TemplateSourceParser::class)->parse(<<<TPL
{$open}
@validation query
@param campaign String required
@endvalidation
{$close}
@layout
<main>Landing</main>
@endlayout
TPL, filename: 'index.tpl.php');
    }

    public static function nestedValidationContainers(): array
    {
        return [
            'preview data' => ["@previewData\n{", "}\n@endpreviewData"],
            'section' => ['@section content "Content"', '@endsection'],
            'type' => ['@type Form', '@endtype'],
            'block' => ['@block form()', '@endblock'],
            'layout' => ['@layout', '@endlayout'],
            'loop' => ['@each item in items:', '@endeach'],
            'condition' => ['@if enabled', '@endif'],
        ];
    }

    public function test_implicit_layout_is_added_after_top_level_included_validation_is_extracted(): void
    {
        $parsed = app(TemplateSourceParser::class)->parsePages(
            ['index.tpl.php' => app(ImplicitTemplateLayout::class)->wrap("@include \"request.tpl\"\n<p>{query.campaign}</p>", 'index.tpl.php')],
            'index.tpl.php',
            fn (string $path): string => $path === 'request.tpl'
                ? "@validation query\n@param campaign String required\n@endvalidation"
                : throw new \RuntimeException('Unexpected include.'),
        );

        $this->assertSame(
            "@validation query\n@param campaign String required\n@endvalidation\n<p>{query.campaign}</p>\n",
            $parsed['html'],
        );
    }

    public function test_implicit_layout_does_not_promote_validation_from_an_included_context(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('@validation must be declared at the top level.');

        app(TemplateSourceParser::class)->parsePages(
            ['index.tpl.php' => app(ImplicitTemplateLayout::class)->wrap("@include \"open.tpl\"\n@validation query\n@param campaign String required\n@endvalidation\n@endif", 'index.tpl.php')],
            'index.tpl.php',
            fn (string $path): string => $path === 'open.tpl'
                ? '@if enabled'
                : throw new \RuntimeException('Unexpected include.'),
        );
    }

    public function test_render_keeps_validation_directives_from_user_values_as_data(): void
    {
        $definition = app(TemplateEngine::class)->validateDefinition([
            'version' => 1,
            'name' => 'User-authored text',
            'sections' => [[
                'id' => 'main',
                'label' => 'Main',
                'fields' => [[
                    'name' => 'message',
                    'label' => 'Message',
                    'type' => 'textarea',
                ]],
            ]],
            'html' => '{{message}}',
        ]);

        $output = app(TemplateEngine::class)->render($definition, [
            'message' => "@validation query\n@param injected String required\n@endvalidation",
        ]);

        $this->assertSame("&#64;validation query\n@param injected String required\n&#64;endvalidation", $output);
        $this->assertStringNotContainsString('@validation', $output);
    }

    public function test_parser_keeps_layout_words_inside_php_heredocs_and_comments_opaque(): void
    {
        $source = <<<'TPL'
@layout
<?php
$embedded = <<<'PHP_TEXT'
@layout
@endlayout
PHP_TEXT;
/*
@layout
@endlayout
*/
echo $embedded;
?>
<p>Visible layout</p>
@endlayout
TPL;

        $parsed = app(TemplateSourceParser::class)->parse($source, filename: 'index.tpl.php');

        $this->assertStringContainsString("<<<'PHP_TEXT'\n@layout\n@endlayout\nPHP_TEXT;", $parsed['html']);
        $this->assertStringContainsString("/*\n@layout\n@endlayout\n*/", $parsed['html']);
        $this->assertStringContainsString('<p>Visible layout</p>', $parsed['html']);
    }

    public function test_parser_preserves_real_end_layout_after_an_open_php_block(): void
    {
        $parsed = app(TemplateSourceParser::class)->parse(
            "@layout\n<?php echo 'open';\n@endlayout",
            filename: 'index.tpl.php',
        );

        $this->assertSame("<?php echo 'open';\n", $parsed['html']);
    }

    public function test_php_placeholders_cannot_collide_with_literal_source_text(): void
    {
        $literal = "\x1APHP_0\x1A";
        $source = "<p>{$literal}</p><?php echo 'trusted'; ?>";
        $fragments = [];

        $protected = TemplatePhpSource::protect($source, $fragments);

        $this->assertStringContainsString("<p>{$literal}</p>", $protected);
        $this->assertArrayNotHasKey($literal, $fragments);
        $this->assertSame($source, strtr($protected, $fragments));
    }
}
