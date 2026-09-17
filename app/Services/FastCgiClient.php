<?php

namespace App\Services;

use RuntimeException;

/** A single-request FastCGI transport for the isolated landing PHP service. */
class FastCgiClient
{
    public function request(array $parameters, string $body): string
    {
        $address = (string) config('fast-landings.php.address');
        $timeout = max(1, (int) config('fast-landings.php.timeout', 35));
        $socket = @stream_socket_client($address, $errorCode, $errorMessage, min($timeout, 3));

        if ($socket === false) {
            throw new RuntimeException('Cannot connect to the landing PHP runtime.');
        }

        stream_set_timeout($socket, $timeout);

        try {
            // FCGI_RESPONDER, without connection reuse. No parent environment is sent.
            $this->write($socket, $this->record(1, pack('nCxxxxx', 1, 0)));
            $encoded = '';
            foreach ($parameters as $name => $value) {
                $name = (string) $name;
                $value = (string) $value;
                $encoded .= $this->length(strlen($name)).$this->length(strlen($value)).$name.$value;
            }
            $this->writeRecords($socket, 4, $encoded);
            $this->writeRecords($socket, 5, $body);

            $output = '';
            $received = 0;
            $maximum = (int) config('fast-landings.php.max_response_bytes', 32 * 1024 * 1024);
            while (true) {
                $header = unpack('Cversion/Ctype/nrequest/nlength/Cpadding/Creserved', $this->read($socket, 8));
                if ($header['version'] !== 1 || $header['request'] !== 1) {
                    throw new RuntimeException('Invalid response from the landing PHP runtime.');
                }
                $content = $this->read($socket, $header['length']);
                $this->read($socket, $header['padding']);
                $received += strlen($content);
                if ($received > $maximum) {
                    throw new RuntimeException('The landing PHP runtime response exceeds its configured limit.');
                }

                if ($header['type'] === 6) {
                    $output .= $content;
                } elseif ($header['type'] === 3) {
                    if (strlen($content) !== 8 || ord($content[4]) !== 0) {
                        throw new RuntimeException('The landing PHP runtime could not complete the request.');
                    }

                    return $output;
                }
                // STDERR belongs in the runtime logs, never in the public response.
            }
        } finally {
            fclose($socket);
        }
    }

    private function length(int $length): string
    {
        return $length < 128 ? chr($length) : pack('N', $length | 0x80000000);
    }

    private function record(int $type, string $content): string
    {
        return pack('CCnnCC', 1, $type, 1, strlen($content), 0, 0).$content;
    }

    private function writeRecords($socket, int $type, string $content): void
    {
        for ($offset = 0, $length = strlen($content); $offset < $length; $offset += 65535) {
            $this->write($socket, $this->record($type, substr($content, $offset, 65535)));
        }
        $this->write($socket, $this->record($type, ''));
    }

    private function write($socket, string $content): void
    {
        $offset = 0;
        $length = strlen($content);
        while ($offset < $length) {
            $written = @fwrite($socket, substr($content, $offset));
            if ($written === false || $written === 0) {
                throw new RuntimeException('The landing PHP runtime request timed out or disconnected.');
            }
            $offset += $written;
        }
    }

    private function read($socket, int $length): string
    {
        $content = '';
        while (strlen($content) < $length) {
            $chunk = @fread($socket, $length - strlen($content));
            if ($chunk === false || $chunk === '') {
                throw new RuntimeException('The landing PHP runtime response timed out or disconnected.');
            }
            $content .= $chunk;
        }

        return $content;
    }
}
