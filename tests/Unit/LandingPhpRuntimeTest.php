<?php

namespace Tests\Unit;

use App\Services\FastCgiClient;
use App\Services\LandingPhpRuntime;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Mockery;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Tests\TestCase;

class LandingPhpRuntimeTest extends TestCase
{
    public function test_raw_form_request_and_redirect_cookies_are_preserved_without_application_environment(): void
    {
        $body = 'name=++Alice++&empty=&tags%5B%5D=a&tags%5B%5D=b';
        $request = Request::create('https://offer.example/success.php?utm=summer+sale', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
            'HTTP_COOKIE' => 'PHPSESSID=abc; campaign=summer',
            'HTTP_AUTHORIZATION' => 'Bearer landing-token',
            'HTTP_PROXY' => 'attacker.invalid',
            'REMOTE_ADDR' => '203.0.113.20',
        ], $body);
        $client = Mockery::mock(FastCgiClient::class);
        $client->shouldReceive('request')->once()->withArgs(function (array $parameters, string $forwarded) use ($body): bool {
            $this->assertSame($body, $forwarded);
            $this->assertSame('POST', $parameters['REQUEST_METHOD']);
            $this->assertSame('utm=summer+sale', $parameters['QUERY_STRING']);
            $this->assertSame('/success.php?utm=summer+sale', $parameters['REQUEST_URI']);
            $this->assertSame('/srv/landings/1/releases/test/success.php', $parameters['SCRIPT_FILENAME']);
            $this->assertSame('/srv/landings/1/releases/test', $parameters['DOCUMENT_ROOT']);
            $this->assertSame('PHPSESSID=abc; campaign=summer', $parameters['HTTP_COOKIE']);
            $this->assertSame('Bearer landing-token', $parameters['HTTP_AUTHORIZATION']);
            $this->assertSame('on', $parameters['HTTPS']);
            $this->assertSame('203.0.113.20', $parameters['REMOTE_ADDR']);
            $this->assertArrayNotHasKey('HTTP_PROXY', $parameters);
            $this->assertArrayNotHasKey('APP_KEY', $parameters);
            $this->assertArrayNotHasKey('DB_PASSWORD', $parameters);

            return true;
        })->andReturn("Status: 303 See Other\r\nLocation: /thanks.php?id=42\r\nSet-Cookie: first=one; Path=/; HttpOnly\r\nSet-Cookie: second=two; Path=/\r\nContent-Type: text/html; charset=UTF-8\r\n\r\nSubmitted");

        $response = (new LandingPhpRuntime($client))->execute($request, '1/releases/test', 'success.php');

        $this->assertSame(303, $response->getStatusCode());
        $this->assertSame('/thanks.php?id=42', $response->headers->get('Location'));
        $this->assertCount(2, $response->headers->getCookies());
        $this->assertSame('Submitted', $response->getContent());
        $this->assertNull($response->headers->get('ETag'));
    }

    public function test_json_request_body_and_php_error_status_are_preserved(): void
    {
        $request = Request::create('http://offer.example/api.php', 'PUT', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{"value": 7}');
        $client = Mockery::mock(FastCgiClient::class);
        $client->shouldReceive('request')->once()->withArgs(function (array $parameters, string $body): bool {
            return $parameters['REQUEST_METHOD'] === 'PUT'
                && $parameters['CONTENT_TYPE'] === 'application/json'
                && $body === '{"value": 7}';
        })->andReturn("Status: 422 Unprocessable Content\r\nContent-Type: application/json\r\nCache-Control: no-store\r\n\r\n{\"error\":\"invalid\"}");

        $response = (new LandingPhpRuntime($client))->execute($request, '1/releases/test', 'api.php');

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('{"error":"invalid"}', $response->getContent());
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_consumed_multipart_body_rebuilds_nested_fields_files_and_empty_values(): void
    {
        $request = Request::create('http://offer.example/upload.php', 'POST', [
            'name' => '  Alice  ', 'empty' => '', 'tags' => ['a', 'b'],
        ], [], ['attachments' => [UploadedFile::fake()->createWithContent('lead.txt', 'uploaded content')]], [
            'CONTENT_TYPE' => 'multipart/form-data; boundary=original',
        ]);
        $client = Mockery::mock(FastCgiClient::class);
        $client->shouldReceive('request')->once()->withArgs(function (array $parameters, string $body): bool {
            $this->assertStringStartsWith('multipart/form-data; boundary=fast-landings-', $parameters['CONTENT_TYPE']);
            $this->assertSame((string) strlen($body), $parameters['CONTENT_LENGTH']);
            $this->assertStringContainsString("name=\"name\"\r\n\r\n  Alice  \r\n", $body);
            $this->assertStringContainsString("name=\"empty\"\r\n\r\n\r\n", $body);
            $this->assertStringContainsString("name=\"tags[1]\"\r\n\r\nb\r\n", $body);
            $this->assertStringContainsString('name="attachments[0]"; filename="lead.txt"', $body);
            $this->assertStringContainsString("\r\n\r\nuploaded content\r\n", $body);

            return true;
        })->andReturn("Content-Type: text/plain\r\n\r\nreceived");

        $this->assertSame('received', (new LandingPhpRuntime($client))->execute($request, '1/releases/test', 'upload.php')->getContent());
    }

    public function test_runtime_connection_failure_returns_service_unavailable(): void
    {
        $client = Mockery::mock(FastCgiClient::class);
        $client->shouldReceive('request')->once()->andThrow(new RuntimeException('Cannot connect.'));
        $this->expectException(ServiceUnavailableHttpException::class);

        (new LandingPhpRuntime($client))->execute(Request::create('/index.php'), '1/releases/test', 'index.php');
    }

    public function test_invalid_runtime_response_is_not_returned_as_page_content(): void
    {
        $client = Mockery::mock(FastCgiClient::class);
        $client->shouldReceive('request')->once()->andReturn('<?php secret_source();');
        $this->expectException(ServiceUnavailableHttpException::class);

        (new LandingPhpRuntime($client))->execute(Request::create('/index.php'), '1/releases/test', 'index.php');
    }
}
