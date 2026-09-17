<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class InstallerOriginTest extends TestCase
{
    #[DataProvider('validTargets')]
    public function test_shell_accepts_valid_origin_targets_and_selects_the_dns_record_type(string $target, string $normalized, string $type): void
    {
        $process = new Process(['sh', '-c', $this->functions().'
            target=$(normalize_origin_target "$1")
            record_type=$(origin_record_type "$target") || exit 1
            printf "%s|%s" "$target" "$record_type"
        ', 'installer-origin-test', $target]);
        $process->run();

        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
        $this->assertSame($normalized.'|'.$type, $process->getOutput());
    }

    public static function validTargets(): array
    {
        return [
            ['203.0.113.10', '203.0.113.10', 'A'],
            ['2001:db8::10', '2001:db8::10', 'AAAA'],
            ['2001:0DB8:0:0:0:0:0:10', '2001:0db8:0:0:0:0:0:10', 'AAAA'],
            ['::ffff:192.0.2.1', '::ffff:192.0.2.1', 'AAAA'],
            ['2001:db8:0:0:0:0:192.0.2.1', '2001:db8:0:0:0:0:192.0.2.1', 'AAAA'],
            ['Origin.Example.COM.', 'origin.example.com', 'CNAME'],
            ['xn--bcher-kva.example', 'xn--bcher-kva.example', 'CNAME'],
        ];
    }

    #[DataProvider('invalidTargets')]
    public function test_shell_rejects_invalid_or_unsafe_origin_targets(string $target): void
    {
        $process = new Process(['sh', '-c', $this->functions().'
            target=$(normalize_origin_target "$1")
            origin_record_type "$target"
        ', 'installer-origin-test', $target]);
        $process->run();

        $this->assertFalse($process->isSuccessful(), 'Unexpected valid origin: '.$target);
    }

    public static function invalidTargets(): array
    {
        return array_map(fn (string $target): array => [$target], [
            '', '999.0.113.10', '192.000.2.1', '127.1', '203.0.113.10.',
            '2001:db8:::10', '1:2:3:4:5:6:7', '1:2:3:4:5:6:7:8:9',
            '1:2:3:4:5:6:7::8', '2001::db8::10', '2001:db8::gg',
            '[2001:db8::10]', 'fe80::1%eth0', '::ffff:192.000.2.1',
            '1:2:3:4:5:6:7:192.0.2.1', '::192.0.2.1:abcd',
            'https://origin.example.com', 'origin.example.com:443',
            '203.0.113.0/24', '*.example.com', '-origin.example.com',
            "origin.example.com\nINJECTED=value", "origin.example.com\r", "origin.example.com\t",
            'origin.example.com$(touch /tmp/unsafe)', 'origin.example.com`id`',
        ]);
    }

    #[DataProvider('originOptions')]
    public function test_installer_accepts_the_preferred_option_and_legacy_alias(string $option): void
    {
        $process = new Process(['sh', dirname(__DIR__, 2).'/install.sh', $option, '2001:db8::10', '--help']);
        $process->run();

        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
        $this->assertStringContainsString('--origin-target IP_OR_HOST', $process->getOutput());
        $this->assertStringContainsString('--origin-host IP_OR_HOST', $process->getOutput());
    }

    public static function originOptions(): array
    {
        return [['--origin-target'], ['--origin-host']];
    }

    #[DataProvider('dnsInstructions')]
    public function test_final_dns_instructions_match_the_origin_type(string $origin, string $type, string $panelInstruction): void
    {
        $script = file_get_contents(dirname(__DIR__, 2).'/install.sh');
        $start = strpos($script, 'if [ "$ORIGIN_RECORD_TYPE" = CNAME ]; then');
        $this->assertNotFalse($start);
        $process = new Process(['sh', '-c', substr($script, $start)], env: [
            'PANEL_HOST' => 'panel.example.com',
            'SYSTEM_HOST_SUFFIX' => 'go.example.com',
            'ORIGIN_HOST' => $origin,
            'ORIGIN_RECORD_TYPE' => $type,
            'DATA_ROOT' => '/var/lib/fast-landings',
            'INSTALL_ROOT' => '/opt/fast-landings',
        ]);
        $process->run();

        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
        $this->assertStringContainsString($panelInstruction, $process->getOutput());
        $this->assertStringContainsString("*.go.example.com -> {$origin} ({$type})", $process->getOutput());
    }

    public static function dnsInstructions(): array
    {
        return [
            ['203.0.113.10', 'A', 'panel.example.com -> 203.0.113.10 (A)'],
            ['2001:db8::10', 'AAAA', 'panel.example.com -> 2001:db8::10 (AAAA)'],
            ['origin.example.com', 'CNAME', 'panel.example.com -> this server (A/AAAA)'],
        ];
    }

    private function functions(): string
    {
        $script = file_get_contents(dirname(__DIR__, 2).'/install.sh');
        $functions = [];

        foreach (['contains_control_character', 'validate_hostname', 'normalize_origin_target', 'ip_record_type', 'origin_record_type'] as $name) {
            $this->assertSame(1, preg_match('/^'.$name.'\(\) \{\n.*?^\}/ms', $script, $matches));
            $functions[] = $matches[0];
        }

        return implode("\n", $functions);
    }
}
