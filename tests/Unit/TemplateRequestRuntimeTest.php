<?php

namespace Tests\Unit;

use App\Services\Templates\TemplateRequestRuntime;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class TemplateRequestRuntimeTest extends TestCase
{
    public function test_request_values_are_escaped_nested_and_separate_from_other_sources(): void
    {
        [$output, $status] = $this->execute(
            '<p>{query.name}|{body.name}|{body.user.phone}|{headers.X-Client}|{query.missing}</p><input value="{body.name}">',
            ['name' => 'query'],
            ['name' => '<script>"&\'evil', 'user' => ['phone' => '+380 123 456 789']],
            ['HTTP_X_CLIENT' => 'agent'],
        );
        $this->assertSame('<p>query|&lt;script&gt;&quot;&amp;&#039;evil|+380 123 456 789|agent|</p><input value="&lt;script&gt;&quot;&amp;&#039;evil">', $output);
        $this->assertSame(200, $status);
    }

    public function test_whole_sources_are_json_and_values_cannot_inject_php_or_additional_macros(): void
    {
        [$output] = $this->execute('{body.*}|{query.attack}|{query.target}', ['attack' => '<?php die("attack"); ?> {query.target}', 'target' => 'safe'], ['enabled' => false, 'nested' => ['value' => '<script>']]);
        $this->assertSame('{&quot;enabled&quot;:false,&quot;nested&quot;:{&quot;value&quot;:&quot;&lt;script&gt;&quot;}}|&lt;?php die(&quot;attack&quot;); ?&gt; {query.target}|safe', $output);
    }

    public function test_php_is_opaque_and_double_braces_and_escaped_macros_are_literals(): void
    {
        $php = '<?php $value = "{body.name}"; /* @validation body */ echo $value; ?>';
        $compiler = new TemplateRequestRuntime;
        $this->assertFalse($compiler->hasRuntime($php));
        $this->assertSame($php, $compiler->compile($php));
        $compiled = $compiler->compile($php.'<p>{body.name}</p>');
        $this->assertStringContainsString($php, $compiled);
        [$output] = $this->execute($php.'|{{body.name}}|\{body.name}|{body.name}', [], ['name' => 'Alex']);
        $this->assertSame('{body.name}|{{body.name}}|{body.name}|Alex', $output);
    }

    public function test_existing_strict_php_declaration_remains_valid(): void
    {
        [$output] = $this->execute('<?php declare(strict_types=1); echo "PHP"; ?><b>{query.name}</b>', ['name' => 'Alex']);
        $this->assertSame('PHP<b>Alex</b>', $output);
        [$output] = $this->execute('<?php declare(strict_types=1); namespace Landing; echo "PHP"; ?><b>{query.name}</b>', ['name' => 'Alex']);
        $this->assertSame('PHP<b>Alex</b>', $output);
        [$output] = $this->execute('<?php namespace Landing { echo "PHP"; ?><b>{query.name}</b><?php } ?>', ['name' => 'Alex']);
        $this->assertSame('PHP<b>Alex</b>', $output);
        [$output] = $this->execute('<?PHP declare(strict_types=1); namespace Landing; echo "PHP"; ?><b>{query.name}</b>', ['name' => 'Alex']);
        $this->assertSame('PHP<b>Alex</b>', $output);
    }

    public function test_quoted_attributes_and_urls_with_static_prefixes_are_supported(): void
    {
        [$output] = $this->execute('<a title="A > {query.name}" href="https://example.test/?name={query.name}"><input value="{query.name}" data-pixel="{query.pixel}"></a>', ['name' => '"><script>', 'pixel' => '123']);
        $this->assertSame('<a title="A > &quot;&gt;&lt;script&gt;" href="https://example.test/?name=&quot;&gt;&lt;script&gt;"><input value="&quot;&gt;&lt;script&gt;" data-pixel="123"></a>', $output);
        [$output] = $this->execute('<!-- a normal comment with > and "quotes" --> <input value="{query.name}">', ['name' => 'Alex']);
        $this->assertSame('<!-- a normal comment with > and "quotes" --> <input value="Alex">', $output);
    }

    #[DataProvider('unsafeContexts')]
    public function test_macros_reject_executable_and_unquoted_contexts(string $source): void
    {
        $this->expectException(ValidationException::class);
        (new TemplateRequestRuntime)->validate($source);
    }

    public static function unsafeContexts(): array
    {
        return array_map(static fn (string $source): array => [$source], [
            '<script>const name = "{body.name}";</script>',
            '<SCRIPT>const name = `{body.name}`;</SCRIPT>',
            '<style>.name { color: {body.name}; }</style>',
            '<input value={body.name}>',
            '<input {body.attributes}>',
            '<{body.tag}>',
            '<button onclick="alert(\'{body.name}\')">Click</button>',
            '<div style="background:{body.name}"></div>',
            '<iframe srcdoc="{body.html}"></iframe>',
            '<a href="{query.url}">Click</a>',
            '<a href="java{query.scheme}">Click</a>',
            '<a href="javascript:run({query.value})">Click</a>',
            '<img srcset="{query.srcset}">',
            '<meta http-equiv="refresh" content="0;url={query.url}">',
            '<!--><input onclick="{query.code}">',
            '<!---><script>const code = `{query.code}`;</script>',
            '<!-- comment --!><input onclick="{query.code}">',
            '<!bogus " ><input onclick="{query.code}">',
            '<div bogus="safe" broken"><script>const code = `{query.code}`;</script>',
            '<foo="><script>" > {query.code} </script>',
            '<meta http-equiv="&#x72;efresh" content="0;url={query.url}">',
            '<meta http-equiv="refresh" http-equiv="content-type" content="0;url={query.url}">',
            '<svg><a href="https://example.test"><animate attributeName="href" values="{query.url}" /></a></svg>',
            '<svg><a href="https://example.test"><set attributeName="href" to="{query.url}" /></a></svg>',
            '<svg><a href="https://example.test"><set attributeName="{query.attribute}" to="javascript:alert(1)" /></a></svg>',
        ]);
    }

    public function test_validation_runs_before_existing_php_and_markup(): void
    {
        [$output, $status] = $this->execute(<<<'TPL'
<?php echo "Page must not run"; ?>
<p>Page must not render</p>
@validation query
  @param subid String required
@endvalidation
TPL);
        $this->assertSame('Invalid request.', $output);
        $this->assertSame(422, $status);
    }

    public function test_validation_literals_cannot_be_replaced_by_runtime_export_markers(): void
    {
        [$output, $status] = $this->execute(<<<'TPL'
@validation query
  @param reference String required mask="__SHARED_RENDERER__"
@endvalidation
<p>{query.reference}</p>
TPL, ['reference' => '__SHARED_RENDERER__']);
        $this->assertSame('<p>__SHARED_RENDERER__</p>', $output);
        $this->assertSame(200, $status);
    }

    #[DataProvider('validRules')]
    public function test_scalar_rules_accept_expected_values(string $declaration, mixed $value): void
    {
        [$output, $status] = $this->execute("@validation body\n@param value {$declaration}\n@endvalidation\nOK", [], ['value' => $value]);
        $this->assertSame('OK', $output);
        $this->assertSame(200, $status);
    }

    public static function validRules(): array
    {
        return [
            ['String required min=4 max=4 length=4', 'Ігор'],
            ['String required lenght=10', '1234567890'],
            ['String required mask="+380 ... ... ..."', '+380 123 456 789'],
            ['Number required min=1.5 max=3', '2.25'],
            ['Integer required min=-2 max=2', '-1'],
            ['Boolean required', false],
            ['Boolean required', 'false'],
            ['String min=4', null],
        ];
    }

    #[DataProvider('invalidValues')]
    public function test_scalar_rules_reject_bad_values(string $declaration, mixed $value): void
    {
        [$output, $status] = $this->execute("@validation body\n@param value {$declaration}\n@endvalidation\nLEAK", [], ['value' => $value]);
        $this->assertSame('Invalid request.', $output);
        $this->assertSame(422, $status);
    }

    public static function invalidValues(): array
    {
        return [
            ['String required', []],
            ['String required', 123],
            ['String required', '  '],
            ['String required min=4', 'Яна'],
            ['String max=4', 'Hello'],
            ['String length=4', '12345'],
            ['String mask="+380 ... ... ..."', '+380 123 456 7890'],
            ['String mask="+380 ... ... ..."', '+380 abc 456 789'],
            ['Number min=1.5', '1.4'],
            ['Number max=3', '3.1'],
            ['Number', 'INF'],
            ['Number', true],
            ['Integer', '2.5'],
            ['Integer', 2.5],
            ['Boolean', 'yes'],
        ];
    }

    public function test_metadata_and_static_preview_preserve_php_and_expose_normalized_rules(): void
    {
        $source = "@validation headers fallback=\"/error\"\n@param X-Client String required lenght=10\n@endvalidation\n<?php echo 'opaque'; ?><b>{headers.x-client}</b>";
        $compiler = new TemplateRequestRuntime;
        $this->assertSame([['source' => 'headers', 'name' => 'X-Client', 'path' => 'X-Client', 'type' => 'String', 'required' => true, 'length' => 10, 'fallback' => '/error']], $compiler->declarations($source));
        $this->assertSame("<?php echo 'opaque'; ?><b>{headers.x-client}</b>", $compiler->stripValidations($source));
        $this->assertSame("@validation headers fallback=\"/error\"\n@param X-Client String required lenght=10\n@endvalidation\n", $compiler->validationSource($source));
    }

    #[DataProvider('invalidDeclarations')]
    public function test_invalid_declarations_fail_during_compilation(string $source): void
    {
        $this->expectException(ValidationException::class);
        (new TemplateRequestRuntime)->compile($source);
    }

    public static function invalidDeclarations(): array
    {
        $wrap = static fn (string $rule): array => ["@validation body\n@param name {$rule}\n@endvalidation"];

        return [
            ["@validation cookies\n@param name String\n@endvalidation"],
            ["@validation body\n@param name String"],
            ['@endvalidation'],
            ["@validation body\n@endvalidation"],
            ["@validation body\n@validation query\n@endvalidation"],
            ["@validation body\nHello\n@endvalidation"],
            ["@validation body\n@param name String\n@param name String\n@endvalidation"],
            ["@validation headers\n@param X-Name String\n@param x-name String\n@endvalidation"],
            ["@validation body fallback=\"//evil.test\"\n@param name String\n@endvalidation"],
            ["@validation body fallback=\"https://evil.test\"\n@param name String\n@endvalidation"],
            ["@validation body fallback=\"/%2Fevil.test\"\n@param name String\n@endvalidation"],
            ["@validation body fallback=\"/error%0d%0aX-Evil:1\"\n@param name String\n@endvalidation"],
            $wrap('Unknown'),
            $wrap('String min=-1'),
            $wrap('String length=1.5'),
            $wrap('String min=5 max=4'),
            $wrap('String min=5 length=4'),
            $wrap('String length=3 lenght=3'),
            $wrap('String required required'),
            $wrap('String required=maybe'),
            $wrap('Boolean min=1'),
            $wrap('Number mask=".."'),
            $wrap('String mask="unterminated'),
            $wrap('String unsupported=true'),
        ];
    }

    public function test_http_runtime_handles_json_forms_headers_and_page_specific_redirects(): void
    {
        $directory = sys_get_temp_dir().'/fl-request-'.bin2hex(random_bytes(8));
        mkdir($directory);
        $compiler = new TemplateRequestRuntime;
        file_put_contents($directory.'/echo.php', $compiler->compile('{body.*}'));
        file_put_contents($directory.'/index.php', $compiler->compile("@validation query fallback=\"/error\"\n@param subid String required\n@endvalidation\nINDEX {query.subid}"));
        file_put_contents($directory.'/success.php', $compiler->compile(<<<'TPL'
@validation body fallback="submit-error"
  @param user.name String required min=4
  @param phone String required mask="+380 ... ... ..."
@endvalidation
@validation headers
  @param X-Client String required
@endvalidation
{body.user.name}|{body.phone}|{headers.x-client}|{query.subid}
TPL));
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $error);
        $this->assertNotFalse($socket, $error);
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        $server = new Process([PHP_BINARY, '-S', $address, '-t', $directory]);
        $server->start();
        try {
            for ($attempt = 0; $attempt < 50; $attempt++) {
                $connection = @stream_socket_client('tcp://'.$address, $errorCode, $error, 0.1);
                if ($connection !== false) {
                    fclose($connection);
                    break;
                }
                usleep(20000);
            }
            $this->assertTrue($server->isRunning(), $server->getErrorOutput());
            [$body, $headers] = $this->request($address, '/index.php');
            $this->assertStringContainsString('302', $headers[0]);
            $this->assertContains('Location: /error', $headers);
            $this->assertSame('', $body);
            [$body, $headers] = $this->request($address, '/index.php?subid=abc');
            $this->assertSame('INDEX abc', $body);
            $this->assertStringContainsString('200', $headers[0]);

            [$body, $headers] = $this->request($address, '/echo.php', 'POST', '{"empty":{},"list":[]}', ['Content-Type: application/json']);
            $this->assertSame('{&quot;empty&quot;:{},&quot;list&quot;:[]}', $body);
            $this->assertStringContainsString('200', $headers[0]);

            foreach (['application/json; charset=UTF-8', 'application/problem+json', 'application/x-www-form-urlencoded'] as $contentType) {
                $payload = ['user' => ['name' => 'Ігор'], 'phone' => '+380 123 456 789'];
                $content = str_starts_with($contentType, 'application/x-') ? http_build_query($payload) : json_encode($payload);
                [$body, $headers] = $this->request($address, '/success.php?subid=q', 'POST', $content, ['Content-Type: '.$contentType, 'X-Client: Browser']);
                $this->assertSame('Ігор|+380 123 456 789|Browser|q', $body);
                $this->assertStringContainsString('200', $headers[0]);
            }
            foreach (['{"user":', '{"user":{"name":"Ann"},"phone":"+380 123 456 789"}', '"scalar"'] as $content) {
                [$body, $headers] = $this->request($address, '/success.php?phone=+380123456789&user[name]=QueryName', 'POST', $content, ['Content-Type: application/json', 'X-Client: Browser']);
                $this->assertSame('', $body);
                $this->assertStringContainsString('303', $headers[0]);
                $this->assertContains('Location: submit-error', $headers);
            }
            [$body, $headers] = $this->request($address, '/success.php', 'PUT', '{}', ['Content-Type: application/json']);
            $this->assertSame('', $body);
            $this->assertStringContainsString('303', $headers[0]);
        } finally {
            $server->stop();
            unlink($directory.'/index.php');
            unlink($directory.'/echo.php');
            unlink($directory.'/success.php');
            rmdir($directory);
        }
    }

    private function execute(string $source, array $query = [], array $body = [], array $server = []): array
    {
        $file = tempnam(sys_get_temp_dir(), 'fl-request-');
        file_put_contents($file, (new TemplateRequestRuntime)->compile($source));
        $script = '$_GET = '.var_export($query, true).'; $_POST = '.var_export($body, true).'; $_SERVER = '.var_export($server, true).';'
            .'register_shutdown_function(static function () { fwrite(STDERR, "STATUS:".(http_response_code() ?: 200)); }); require '.var_export($file, true).';';
        try {
            $process = new Process([PHP_BINARY, '-r', $script]);
            $process->mustRun();
            $this->assertMatchesRegularExpression('/^STATUS:\d+$/', $process->getErrorOutput());

            return [$process->getOutput(), (int) substr($process->getErrorOutput(), strlen('STATUS:'))];
        } finally {
            unlink($file);
        }
    }

    private function request(string $address, string $path, string $method = 'GET', string $body = '', array $headers = []): array
    {
        $context = stream_context_create(['http' => ['method' => $method, 'content' => $body, 'header' => implode("\r\n", $headers), 'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 3]]);
        $body = file_get_contents('http://'.$address.$path, false, $context);
        $this->assertNotFalse($body);

        return [$body, $http_response_header];
    }
}
