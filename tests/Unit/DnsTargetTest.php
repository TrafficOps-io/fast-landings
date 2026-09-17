<?php

namespace Tests\Unit;

use App\Support\DnsTarget;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TrafficOps\Cloudflare\Exceptions\CloudflareValidationException;

class DnsTargetTest extends TestCase
{
    #[DataProvider('validTargets')]
    public function test_addresses_and_hostnames_are_normalized_and_have_the_correct_record_type(string $input, string $normalized, string $type): void
    {
        $this->assertSame($normalized, DnsTarget::normalize($input));
        $this->assertSame($type, DnsTarget::recordType($input));
    }

    /** @return array<string, array{string, string, string}> */
    public static function validTargets(): array
    {
        return [
            'IPv4' => [' 203.0.113.42 ', '203.0.113.42', 'A'],
            'IPv6' => [' 2001:0DB8:0000:0000:0000:0000:0000:0042 ', '2001:db8::42', 'AAAA'],
            'embedded IPv4' => ['::FFFF:203.0.113.42', '::ffff:203.0.113.42', 'AAAA'],
            'hostname' => [' ORIGIN.Example.COM. ', 'origin.example.com', 'CNAME'],
            'international hostname' => ['BÜCHER.example', 'xn--bcher-kva.example', 'CNAME'],
        ];
    }

    #[DataProvider('invalidTargets')]
    public function test_invalid_addresses_do_not_become_cname_targets(string $input): void
    {
        $this->expectException(CloudflareValidationException::class);

        DnsTarget::normalize($input);
    }

    /** @return array<string, array{string}> */
    public static function invalidTargets(): array
    {
        return [
            'out-of-range IPv4' => ['999.0.2.1'],
            'short IPv4' => ['127.1'],
            'leading-zero IPv4' => ['192.000.2.1'],
            'IPv4 port' => ['192.0.2.1:443'],
            'IPv4 trailing dot' => ['192.0.2.1.'],
            'invalid IPv6' => ['2001:db8:::1'],
            'scoped IPv6' => ['fe80::1%eth0'],
            'bracketed IPv6' => ['[2001:db8::1]'],
            'URL' => ['https://origin.example.com'],
            'CIDR' => ['192.0.2.0/24'],
            'wildcard' => ['*.example.com'],
            'empty' => [''],
        ];
    }
}
