<?php

namespace Tests\Feature;

use App\Enums\DomainKind;
use App\Enums\DomainProvider;
use App\Enums\DomainStatus;
use App\Models\Domain;
use App\Models\Landing;
use App\Models\LandingRelease;
use App\Services\LandingPhpRuntime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Tests\TestCase;

class LandingContentControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('landings');
        config([
            'fast-landings.storage_disk' => 'landings',
            'fast-landings.spa_fallback' => true,
        ]);
    }

    public function test_active_domain_resolves_to_its_own_landing(): void
    {
        $this->publishedLanding('first.example.test', '<h1>First landing</h1>');
        $this->publishedLanding('second.example.test', '<h1>Second landing</h1>');

        $first = $this->get('http://first.example.test/');
        $second = $this->get('http://second.example.test/');

        $this->assertSame('<h1>First landing</h1>', $this->fileContents($first));
        $this->assertSame('<h1>Second landing</h1>', $this->fileContents($second));
        $first->assertHeaderMissing('Set-Cookie');
        $second->assertHeaderMissing('Set-Cookie');
    }

    public function test_wildcard_dns_routes_only_explicitly_registered_and_assigned_hostnames(): void
    {
        [, , $base] = $this->publishedLanding('example.test', '<h1>Root landing</h1>');
        $base->update(['dns_scope' => 'wildcard']);
        [, , $child] = $this->publishedLanding('offer.example.test', '<h1>Offer landing</h1>');
        $child->update(['parent_domain_id' => $base->id]);
        $unassigned = $this->domain('unused.example.test', null, DomainStatus::Active);
        $unassigned->update(['parent_domain_id' => $base->id, 'is_primary' => false]);

        $this->assertSame('<h1>Root landing</h1>', $this->fileContents($this->get('http://example.test/')));
        $this->assertSame('<h1>Offer landing</h1>', $this->fileContents($this->get('http://offer.example.test/')));
        foreach (['unknown.example.test', 'nested.offer.example.test', 'unused.example.test'] as $hostname) {
            $this->get('http://'.$hostname.'/')->assertNotFound();
        }

        $child->update(['status' => DomainStatus::Drifted]);
        $this->get('http://offer.example.test/')->assertNotFound();
    }

    public function test_html_is_private_and_never_emits_admin_cookies(): void
    {
        $this->publishedLanding('html.example.test', '<h1>Fresh HTML</h1>');

        $response = $this->get('http://html.example.test/');

        $this->assertSame('<h1>Fresh HTML</h1>', $this->fileContents($response));
        $cacheControl = (string) $response->headers->get('Cache-Control');
        $this->assertStringContainsString('private', $cacheControl);
        $this->assertStringContainsString('no-cache', $cacheControl);
        $this->assertStringNotContainsString('public', $cacheControl);
        $response->assertHeaderMissing('Set-Cookie');
    }

    public function test_static_asset_is_revalidated_after_release_switches_and_has_safe_headers(): void
    {
        [, $release] = $this->publishedLanding('assets.example.test', '<h1>Assets</h1>');
        Storage::disk('landings')->put($release->storage_path.'/assets/app.js', 'window.ready = true;');

        $response = $this->get('http://assets.example.test/assets/app.js');

        $this->assertSame('window.ready = true;', $this->fileContents($response));
        $cacheControl = (string) $response->headers->get('Cache-Control');
        $this->assertStringContainsString('public', $cacheControl);
        $this->assertStringContainsString('no-cache', $cacheControl);
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->assertHeaderMissing('Set-Cookie');
        $this->assertNotSame('', (string) $response->headers->get('Content-Type'));
    }

    public function test_spa_fallback_serves_entrypoint_only_for_extensionless_paths(): void
    {
        $this->publishedLanding('spa.example.test', '<main>Single page application</main>');

        $fallback = $this->get('http://spa.example.test/products/summer');

        $this->assertSame('<main>Single page application</main>', $this->fileContents($fallback));
        $this->get('http://spa.example.test/assets/missing.js')->assertNotFound();
    }

    public function test_panel_health_and_livewire_paths_do_not_shadow_landing_content(): void
    {
        [, $release] = $this->publishedLanding('routes.example.test', '<main>Landing router</main>');
        $livewirePath = 'livewire-'.substr(hash('sha256', config('app.key').'livewire-endpoint'), 0, 8).'/livewire.js';
        Storage::disk('landings')->put($release->storage_path.'/'.$livewirePath, 'landing-owned-script');

        $this->assertSame(
            '<main>Landing router</main>',
            $this->fileContents($this->get('http://routes.example.test/up')),
        );
        $this->assertSame(
            'landing-owned-script',
            $this->fileContents($this->get('http://routes.example.test/'.$livewirePath)),
        );
    }

    public function test_pending_and_unassigned_domains_do_not_serve_content(): void
    {
        [$landing] = $this->publishedLanding(
            'pending.example.test',
            '<h1>Must remain private</h1>',
            DomainStatus::Pending,
        );
        $this->domain('unassigned.example.test', null, DomainStatus::Active);

        $this->get('http://pending.example.test/')->assertNotFound();
        $this->get('http://unassigned.example.test/')->assertNotFound();

        $landing->update(['is_active' => false]);
        Domain::query()->where('hostname', 'pending.example.test')->update([
            'status' => DomainStatus::Active->value,
        ]);
        $this->get('http://pending.example.test/')->assertNotFound();
    }

    public function test_verified_domain_keeps_serving_while_unreachable(): void
    {
        [, , $domain] = $this->publishedLanding('steady.example.test', '<h1>Still online</h1>');

        $domain->update(['status' => DomainStatus::Unreachable]);
        $this->assertSame('<h1>Still online</h1>', $this->fileContents($this->get('http://steady.example.test/')));

        $domain->update(['status' => DomainStatus::Error]);
        $this->assertSame('<h1>Still online</h1>', $this->fileContents($this->get('http://steady.example.test/')));
    }

    public function test_verified_domain_stops_serving_once_drifted(): void
    {
        [, , $domain] = $this->publishedLanding('moved.example.test', '<h1>Moved away</h1>');

        $domain->update(['status' => DomainStatus::Drifted]);

        $this->get('http://moved.example.test/')->assertNotFound();
    }

    public function test_never_verified_domain_is_not_served_even_after_transient_statuses(): void
    {
        [, , $domain] = $this->publishedLanding('fresh.example.test', '<h1>Never verified</h1>', DomainStatus::Pending);

        foreach ([DomainStatus::PendingPropagation, DomainStatus::Unreachable, DomainStatus::Error] as $status) {
            $domain->update(['status' => $status]);
            $this->get('http://fresh.example.test/')->assertNotFound();
        }
    }

    public function test_paused_landing_is_not_served_on_a_verified_domain(): void
    {
        [$landing] = $this->publishedLanding('paused.example.test', '<h1>Paused</h1>');

        $landing->update(['is_active' => false]);

        $this->get('http://paused.example.test/')->assertNotFound();
    }

    public function test_verified_child_domain_keeps_serving_while_wildcard_base_fails_transiently(): void
    {
        [, , $base] = $this->publishedLanding('example.test', '<h1>Root landing</h1>');
        $base->update(['dns_scope' => 'wildcard']);
        [, , $child] = $this->publishedLanding('offer.example.test', '<h1>Offer landing</h1>');
        $child->update(['parent_domain_id' => $base->id]);

        // A transient base failure is copied onto its children by DomainManager::refreshSubdomains.
        $base->update(['status' => DomainStatus::Unreachable]);
        $child->update(['status' => DomainStatus::Unreachable]);

        $this->assertSame('<h1>Root landing</h1>', $this->fileContents($this->get('http://example.test/')));
        $this->assertSame('<h1>Offer landing</h1>', $this->fileContents($this->get('http://offer.example.test/')));
    }

    public function test_matching_etag_returns_not_modified_without_a_body(): void
    {
        $this->publishedLanding('etag.example.test', '<h1>Cacheable</h1>');

        $initial = $this->get('http://etag.example.test/')->assertOk();
        $etag = (string) $initial->headers->get('ETag');
        $this->assertMatchesRegularExpression('/^"[a-f0-9]{64}"$/', $etag);

        $this->withHeader('If-None-Match', $etag)
            ->get('http://etag.example.test/')
            ->assertStatus(304)
            ->assertHeader('ETag', $etag)
            ->assertContent('');
    }

    public function test_php_directory_index_is_executed_before_static_html(): void
    {
        [, $release] = $this->publishedLanding('php.example.test', 'Static index');
        Storage::disk('landings')->put($release->storage_path.'/index.php', '<?php echo "Dynamic index";');
        $this->mock(LandingPhpRuntime::class)->shouldReceive('execute')->once()
            ->withArgs(fn (Request $request, string $root, string $script): bool => $root === $release->storage_path && $script === 'index.php')
            ->andReturn(response('Dynamic index'));

        $this->get('http://php.example.test/')->assertOk()->assertContent('Dynamic index')->assertHeaderMissing('ETag');
    }

    public function test_form_post_reaches_php_without_csrf_or_laravel_form_normalization(): void
    {
        [, $release] = $this->publishedLanding('form.example.test', '<form action="success.php" method="post"></form>');
        Storage::disk('landings')->put($release->storage_path.'/success.php', '<?php echo $_POST["name"];');
        $this->mock(LandingPhpRuntime::class)->shouldReceive('execute')->once()
            ->withArgs(function (Request $request, string $root, string $script) use ($release): bool {
                $this->assertSame('POST', $request->getRealMethod());
                $this->assertSame('  Alice  ', $request->request->get('name'));
                $this->assertSame('', $request->request->get('empty'));
                $this->assertFalse($request->hasSession());

                return $root === $release->storage_path && $script === 'success.php';
            })->andReturn(response('Form received', 201));

        $this->post('http://form.example.test/success.php', ['name' => '  Alice  ', 'empty' => ''])
            ->assertStatus(201)->assertContent('Form received')->assertHeaderMissing('Set-Cookie');
    }

    public function test_post_to_static_html_is_method_not_allowed(): void
    {
        $this->publishedLanding('static.example.test', 'Static page');

        $this->post('http://static.example.test/')->assertStatus(405)->assertHeader('Allow', 'GET, HEAD');
        $this->post('http://static.example.test/index.html')->assertStatus(405);
        $this->post('http://static.example.test/missing-route')->assertNotFound();
    }

    public function test_original_html_url_executes_compiled_php_preserving_post_and_query(): void
    {
        [, $release] = $this->publishedLanding('runtime.example.test', 'Home');
        Storage::disk('landings')->put($release->storage_path.'/success.php', '<?php echo "Runtime";');
        $this->mock(LandingPhpRuntime::class)->shouldReceive('execute')->once()
            ->withArgs(function (Request $request, string $root, string $script) use ($release): bool {
                $this->assertSame('POST', $request->getRealMethod());
                $this->assertSame('/success.html?subid=123', $request->getRequestUri());
                $this->assertSame('  Visitor  ', $request->request->get('name'));
                $this->assertSame('123', $request->query('subid'));

                return $root === $release->storage_path && $script === 'success.php';
            })->andReturn(response('Runtime response'));

        $this->post('http://runtime.example.test/success.html?subid=123', ['name' => '  Visitor  '])
            ->assertOk()->assertContent('Runtime response')->assertHeaderMissing('ETag');
        $this->get('http://runtime.example.test/missing.html')->assertNotFound();
    }

    public function test_existing_static_html_keeps_precedence_over_php_alias(): void
    {
        [, $release] = $this->publishedLanding('precedence.example.test', 'Static homepage');
        Storage::disk('landings')->put($release->storage_path.'/index.php', '<?php echo "Runtime homepage";');
        $this->mock(LandingPhpRuntime::class)->shouldNotReceive('execute');

        $this->assertSame('Static homepage', $this->fileContents($this->get('http://precedence.example.test/index.html')));
    }

    public function test_directories_redirect_preserving_method_then_resolve_php_index(): void
    {
        [, $release] = $this->publishedLanding('directory.example.test', 'Root');
        Storage::disk('landings')->put($release->storage_path.'/offer/index.php', '<?php echo "Nested";');
        Storage::disk('landings')->put($release->storage_path.'/offer/index.html', 'Nested static');
        $this->mock(LandingPhpRuntime::class)->shouldReceive('execute')->once()
            ->withArgs(fn (Request $request, string $root, string $script): bool => $script === 'offer/index.php')
            ->andReturn(response('Nested'));

        $this->post('http://directory.example.test/offer?b=2&a=1')
            ->assertStatus(308)->assertHeader('Location', '/offer/?b=2&a=1');
        // Laravel's test URL helper trims a final slash without a query string.
        $this->post('http://directory.example.test/offer/?submitted=1')->assertOk()->assertContent('Nested');
    }

    public function test_missing_php_does_not_fall_back_to_static_or_dynamic_index(): void
    {
        [, $release] = $this->publishedLanding('missing.example.test', 'Root');
        Storage::disk('landings')->put($release->storage_path.'/index.php', '<?php echo "Root";');
        $release->update(['entrypoint' => 'index.php']);
        $this->mock(LandingPhpRuntime::class)->shouldNotReceive('execute');

        $this->get('http://missing.example.test/missing.php')->assertNotFound();
        $this->post('http://missing.example.test/missing.php')->assertNotFound();
        $this->get('http://missing.example.test/missing-route')->assertNotFound();
    }

    public function test_hidden_files_template_sources_and_php_backups_are_never_served(): void
    {
        [, $release] = $this->publishedLanding('private.example.test', 'Root');
        $paths = ['.env', '.git/config', '.runtime-sources.json', 'parts/.secret', 'index.tpl.html', 'success.tpl.php',
            'config.inc', 'source.phtml', 'archive.phar', 'index.php8', 'index.PHP',
            'index.php.txt', 'index.php.txt.php', 'config.ini', 'data.sqlite', 'backup.sql', 'index.html.bak'];
        foreach ($paths as $path) {
            Storage::disk('landings')->put($release->storage_path.'/'.$path, 'PRIVATE SOURCE');
        }
        $this->mock(LandingPhpRuntime::class)->shouldNotReceive('execute');

        foreach ($paths as $path) {
            $this->get('http://private.example.test/'.$path)->assertNotFound();
        }
    }

    public function test_unavailable_php_runtime_fails_closed_without_returning_script_source(): void
    {
        [, $release] = $this->publishedLanding('offline.example.test', 'Root');
        Storage::disk('landings')->put($release->storage_path.'/index.php', '<?php echo "DO NOT EXPOSE SOURCE";');
        config(['fast-landings.php.address' => 'tcp://127.0.0.1:0']);

        $this->get('http://offline.example.test/')->assertStatus(503)->assertDontSee('DO NOT EXPOSE SOURCE');
    }

    public function test_symlinks_cannot_escape_the_release_directory(): void
    {
        [, $release] = $this->publishedLanding('escape.example.test', 'Root');
        Storage::disk('landings')->put('outside.txt', 'OTHER RELEASE SECRET');
        symlink(Storage::disk('landings')->path('outside.txt'), Storage::disk('landings')->path($release->storage_path.'/escape.txt'));

        $this->get('http://escape.example.test/escape.txt')->assertNotFound();
    }

    /** @return array{Landing, LandingRelease, Domain} */
    private function publishedLanding(
        string $hostname,
        string $html,
        DomainStatus $status = DomainStatus::Active,
    ): array {
        $landing = Landing::query()->create([
            'name' => Str::headline(strtok($hostname, '.')),
            'slug' => Str::slug($hostname).'-'.Str::lower(Str::random(6)),
            'is_active' => true,
        ]);
        $release = LandingRelease::query()->create([
            'landing_id' => $landing->id,
            'uploaded_by' => null,
            'original_name' => 'landing.zip',
            'storage_path' => $landing->id.'/releases/'.Str::ulid(),
            'entrypoint' => 'index.html',
            'size_bytes' => strlen($html),
            'file_count' => 1,
            'checksum' => hash('sha256', $html),
            'is_active' => true,
            'activated_at' => now(),
        ]);
        $domain = $this->domain($hostname, $landing, $status);
        Storage::disk('landings')->put($release->storage_path.'/index.html', $html);

        return [$landing, $release, $domain];
    }

    private function domain(string $hostname, ?Landing $landing, DomainStatus $status): Domain
    {
        return Domain::query()->create([
            'landing_id' => $landing?->id,
            'hostname' => $hostname,
            'system_subdomain' => null,
            'kind' => DomainKind::Custom,
            'provider' => DomainProvider::Dns,
            'status' => $status,
            'is_primary' => true,
            'dns_target' => 'origin.fast-landings.test',
        ]);
    }

    private function fileContents(TestResponse $response): string
    {
        $response->assertOk();
        $this->assertInstanceOf(BinaryFileResponse::class, $response->baseResponse);

        $contents = file_get_contents($response->baseResponse->getFile()->getPathname());
        $this->assertNotFalse($contents);

        return $contents;
    }
}
