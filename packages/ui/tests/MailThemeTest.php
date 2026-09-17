<?php

namespace TrafficOps\FastLandings\Ui\Tests;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Notifications\Messages\MailMessage;
use TrafficOps\FastLandings\Ui\Support\MailTheme;

class MailThemeTest extends TestCase
{
    private string $publicDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->publicDirectory = sys_get_temp_dir().'/ui-mail-'.bin2hex(random_bytes(8));
        mkdir($this->publicDirectory.'/build', 0777, true);
        $this->app->usePublicPath($this->publicDirectory);
        config([
            'app.url' => 'https://panel.example.com',
            'mail.brand' => 'pwapps',
            'mail.markdown.theme' => 'trafficops',
            'mail.markdown.paths' => [MailTheme::path()],
        ]);
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->publicDirectory);

        parent::tearDown();
    }

    public function test_mail_renders_without_a_frontend_build(): void
    {
        $html = $this->renderMail();

        $this->assertStringContainsString('alt="PWApps"', $html);
        $this->assertStringContainsString('data:image/png;base64,', $html);
        $this->assertStringContainsString('Arial, sans-serif', $html);
        $this->assertStringNotContainsString('@font-face', $html);
    }

    public function test_mail_uses_built_fonts_on_the_asset_host_even_with_a_vite_hot_file(): void
    {
        config(['app.asset_url' => 'https://assets.example.com']);
        file_put_contents($this->publicDirectory.'/hot', 'http://localhost:5173');
        file_put_contents($this->publicDirectory.'/build/manifest.json', json_encode([
            '../../node_modules/@fontsource-variable/onest/files/onest-latin-wght-normal.woff2' => ['file' => 'assets/onest-latin.woff2'],
            '../../node_modules/@fontsource-variable/onest/files/onest-cyrillic-wght-normal.woff2' => ['file' => 'assets/onest-cyrillic.woff2'],
        ]));

        $html = $this->renderMail();

        $this->assertStringContainsString('https://assets.example.com/build/assets/onest-latin.woff2', $html);
        $this->assertStringContainsString('https://assets.example.com/build/assets/onest-cyrillic.woff2', $html);
        $this->assertStringContainsString('U+0301,U+0400-045F', $html);
        $this->assertStringNotContainsString('localhost:5173', $html);
    }

    private function renderMail(): string
    {
        return (string) (new MailMessage)
            ->markdown('ui-mail::notification')
            ->greeting('Hello!')
            ->line('Your account is ready.')
            ->action('Log in', 'https://panel.example.com/login')
            ->render();
    }
}
