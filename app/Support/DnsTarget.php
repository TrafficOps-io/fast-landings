<?php

namespace App\Support;

use TrafficOps\Cloudflare\Exceptions\CloudflareValidationException;
use TrafficOps\Cloudflare\Support\Hostname;

final class DnsTarget
{
    public static function normalize(string $target): string
    {
        $target = trim($target);
        if (filter_var($target, FILTER_VALIDATE_IP) !== false) {
            return inet_ntop(inet_pton($target));
        }

        // Invalid address literals must not silently become CNAME hostnames.
        if ($target === '' || str_contains($target, ':') || preg_match('/^[0-9.]+$/D', $target)) {
            throw new CloudflareValidationException("Invalid DNS target [$target].");
        }

        $hostname = Hostname::normalize($target);
        if (preg_match('/^[0-9.]+$/D', $hostname)) {
            throw new CloudflareValidationException("Invalid DNS target [$target].");
        }

        return $hostname;
    }

    public static function recordType(string $target): string
    {
        $target = self::normalize($target);

        return match (true) {
            filter_var($target, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false => 'A',
            filter_var($target, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false => 'AAAA',
            default => 'CNAME',
        };
    }
}
