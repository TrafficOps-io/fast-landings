<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

class LandingPhpRuntime
{
    public function __construct(private FastCgiClient $client) {}

    public function execute(Request $request, string $releasePath, string $script): Response
    {
        [$body, $contentType] = $this->requestBody($request);
        $root = rtrim((string) config('fast-landings.php.storage_root', '/srv/landings'), '/').'/'.$releasePath;
        $parameters = [
            'GATEWAY_INTERFACE' => 'CGI/1.1',
            'SERVER_SOFTWARE' => 'Fast Landings',
            'SERVER_PROTOCOL' => $request->server('SERVER_PROTOCOL', 'HTTP/1.1'),
            'REQUEST_METHOD' => $request->getRealMethod(),
            'REQUEST_URI' => $request->getRequestUri(),
            'QUERY_STRING' => $request->server('QUERY_STRING', ''),
            'SCRIPT_NAME' => '/'.$script,
            'PHP_SELF' => '/'.$script,
            'SCRIPT_FILENAME' => $root.'/'.$script,
            'DOCUMENT_ROOT' => $root,
            'SERVER_NAME' => $request->getHost(),
            'SERVER_PORT' => (string) $request->getPort(),
            'SERVER_ADDR' => $request->server('SERVER_ADDR', '127.0.0.1'),
            'REMOTE_ADDR' => $request->ip() ?? '127.0.0.1',
            'REMOTE_PORT' => $request->server('REMOTE_PORT', ''),
            'HTTPS' => $request->isSecure() ? 'on' : 'off',
            'REDIRECT_STATUS' => '200',
            'CONTENT_TYPE' => $contentType,
            'CONTENT_LENGTH' => (string) strlen($body),
        ];

        foreach ($request->headers->all() as $name => $values) {
            if (in_array(strtolower($name), ['content-type', 'content-length', 'connection', 'transfer-encoding', 'proxy'], true)) {
                continue;
            }
            $parameters['HTTP_'.strtoupper(str_replace('-', '_', $name))] = implode($name === 'cookie' ? '; ' : ', ', $values);
        }

        try {
            return $this->response($this->client->request($parameters, $body));
        } catch (RuntimeException $exception) {
            Log::warning('Landing PHP runtime is unavailable.', ['reason' => $exception->getMessage()]);

            throw new ServiceUnavailableHttpException(5, 'Landing PHP runtime is unavailable.');
        }
    }

    /** PHP consumes multipart POST bodies before Laravel receives the request. */
    private function requestBody(Request $request): array
    {
        $body = (string) $request->getContent();
        $contentType = (string) $request->headers->get('content-type', '');
        if ($body !== '') {
            return [$body, $contentType];
        }

        if (str_starts_with(strtolower($contentType), 'multipart/form-data')) {
            $boundary = 'fast-landings-'.bin2hex(random_bytes(24));
            $parts = [];
            $this->multipartFields($parts, $request->request->all(), $boundary);
            $this->multipartFiles($parts, $request->files->all(), $boundary);

            return [implode('', $parts)."--{$boundary}--\r\n", 'multipart/form-data; boundary='.$boundary];
        }

        if ($request->request->count() > 0 && ($contentType === '' || str_starts_with(strtolower($contentType), 'application/x-www-form-urlencoded'))) {
            return [http_build_query($request->request->all(), '', '&', PHP_QUERY_RFC3986), 'application/x-www-form-urlencoded'];
        }

        return [$body, $contentType];
    }

    private function multipartFields(array &$parts, array $fields, string $boundary, string $prefix = ''): void
    {
        foreach ($fields as $key => $value) {
            $name = $prefix === '' ? (string) $key : $prefix.'['.$key.']';
            if (is_array($value)) {
                $this->multipartFields($parts, $value, $boundary, $name);
            } else {
                $parts[] = "--{$boundary}\r\nContent-Disposition: form-data; name=\"".$this->quote($name)."\"\r\n\r\n".(string) $value."\r\n";
            }
        }
    }

    private function multipartFiles(array &$parts, array $files, string $boundary, string $prefix = ''): void
    {
        foreach ($files as $key => $file) {
            $name = $prefix === '' ? (string) $key : $prefix.'['.$key.']';
            if (is_array($file)) {
                $this->multipartFiles($parts, $file, $boundary, $name);
            } elseif ($file instanceof UploadedFile) {
                abort_unless($file->isValid(), 413, 'The uploaded file could not be received.');
                $parts[] = "--{$boundary}\r\nContent-Disposition: form-data; name=\"".$this->quote($name).'"; filename="'.$this->quote($file->getClientOriginalName())."\"\r\nContent-Type: ".$this->quote($file->getClientMimeType())."\r\n\r\n".$file->getContent()."\r\n";
            }
        }
    }

    private function quote(string $value): string
    {
        return str_replace(["\r", "\n", '"'], ['%0D', '%0A', '%22'], $value);
    }

    private function response(string $output): Response
    {
        $separator = strpos($output, "\r\n\r\n");
        $delimiterLength = 4;
        if ($separator === false) {
            $separator = strpos($output, "\n\n");
            $delimiterLength = 2;
        }
        if ($separator === false) {
            throw new RuntimeException('The landing PHP runtime returned an invalid HTTP response.');
        }

        $status = null;
        $headers = [];
        foreach (preg_split('/\r?\n/', substr($output, 0, $separator)) as $line) {
            if (! str_contains($line, ':')) {
                throw new RuntimeException('The landing PHP runtime returned an invalid HTTP header.');
            }
            [$name, $value] = explode(':', $line, 2);
            $name = strtolower(trim($name));
            $value = trim($value);
            if ($name === 'status') {
                if (! preg_match('/^([1-5]\d\d)(?:\s|$)/', $value, $matches)) {
                    throw new RuntimeException('The landing PHP runtime returned an invalid HTTP status.');
                }
                $status = (int) $matches[1];
            } elseif (! in_array($name, ['connection', 'transfer-encoding', 'keep-alive', 'trailer', 'upgrade', 'x-powered-by', 'server'], true)) {
                $headers[$name][] = $value;
            }
        }

        $response = new Response(substr($output, $separator + $delimiterLength), $status ?? (isset($headers['location']) ? 302 : 200), $headers);
        if (! isset($headers['cache-control'])) {
            $response->headers->set('Cache-Control', 'no-cache, private');
        }
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        if (! isset($headers['referrer-policy'])) {
            $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        }

        return $response;
    }
}
