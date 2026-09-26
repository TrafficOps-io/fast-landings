<?php

namespace App\Http\Controllers;

use App\Models\Domain;
use App\Services\LandingPhpRuntime;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

class LandingContentController extends Controller
{
    public function __construct(private LandingPhpRuntime $phpRuntime) {}

    public function __invoke(Request $request, ?string $path = null): Response
    {
        $hostname = strtolower(rtrim($request->getHost(), '.'));
        abort_if($hostname === config('fast-landings.panel_domain'), 404);

        $domain = Domain::query()
            ->servable()
            ->where('hostname', $hostname)
            ->with('landing.activeRelease')
            ->firstOrFail();

        $landing = $domain->landing;
        $release = $landing?->activeRelease;
        abort_unless($landing?->is_active && $release, 404);

        $relative = $this->normalizePath($path);
        $disk = Storage::disk(config('fast-landings.storage_disk'));
        $file = "{$release->storage_path}/{$relative}";

        if ($relative === '' || $disk->directoryExists($file)) {
            if ($relative !== '' && ! str_ends_with($request->getPathInfo(), '/')) {
                $query = $request->server('QUERY_STRING');

                return new RedirectResponse('/'.$relative.'/'.($query ? '?'.$query : ''), 308);
            }
            $directory = $relative === '' ? '' : $relative.'/';
            $relative = null;
            foreach (['index.php', 'index.html'] as $index) {
                if ($disk->fileExists($release->storage_path.'/'.$directory.$index)) {
                    $relative = $directory.$index;
                    break;
                }
            }
            abort_if($relative === null, 404);
            $file = "{$release->storage_path}/{$relative}";
        } elseif (str_ends_with((string) $path, '/')) {
            abort(404);
        }

        // Runtime HTML pages are published as PHP. Preserve their original
        // links and form actions without redirecting or dropping POST bodies.
        if (! $disk->fileExists($file) && str_ends_with($relative, '.html')) {
            $script = substr($relative, 0, -5).'.php';
            if ($disk->fileExists($release->storage_path.'/'.$script)) {
                $relative = $script;
                $file = $release->storage_path.'/'.$script;
            }
        }

        if (! $disk->fileExists($file) && in_array($request->getRealMethod(), ['GET', 'HEAD'], true)
            && config('fast-landings.spa_fallback') && pathinfo($relative, PATHINFO_EXTENSION) === ''
            && $release->entrypoint === 'index.html') {
            $relative = $release->entrypoint;
            $file = "{$release->storage_path}/{$relative}";
        }

        abort_unless($disk->fileExists($file), 404);
        $root = realpath($disk->path($release->storage_path));
        $resolved = realpath($disk->path($file));
        abort_unless($root !== false && $resolved !== false && str_starts_with($resolved, $root.DIRECTORY_SEPARATOR), 404);

        if (str_ends_with($relative, '.php')) {
            return $this->phpRuntime->execute($request, $release->storage_path, $relative);
        }

        abort_unless(in_array($request->getRealMethod(), ['GET', 'HEAD'], true), 405, '', ['Allow' => 'GET, HEAD']);

        $etag = '"'.hash('sha256', "{$release->checksum}:{$relative}").'"';
        if ($request->headers->get('If-None-Match') === $etag) {
            return response('', 304, ['ETag' => $etag]);
        }

        return $this->response($disk, $file, $relative, $etag);
    }

    private function normalizePath(?string $path): string
    {
        $path = ltrim((string) $path, '/');
        if ($path === '') {
            return '';
        }

        abort_if(str_contains($path, "\0") || str_contains($path, '\\'), 404);
        $path = rtrim($path, '/');
        foreach (explode('/', $path) as $segment) {
            abort_if($segment === '' || str_starts_with($segment, '.'), 404);
        }

        abort_if((bool) preg_match('/\.(tpl|inc|phtml|phar|php[0-9]+|env|ini|log|sql|sqlite3?|bak|backup|old|orig|save|swp|swo)([.\/]|$)/i', $path), 404);
        // Only the exact lower-case .php suffix executes; backups and alternate
        // PHP extensions must never fall through to static source delivery.
        $withoutScriptSuffix = str_ends_with($path, '.php') ? substr($path, 0, -4) : $path;
        abort_if((bool) preg_match('/\.php([.\/]|$)/i', $withoutScriptSuffix), 404);

        return $path;
    }

    private function response(FilesystemAdapter $disk, string $file, string $relative, string $etag): BinaryFileResponse
    {
        $isHtml = strtolower(pathinfo($relative, PATHINFO_EXTENSION)) === 'html';

        $response = new BinaryFileResponse($disk->path($file), 200, [
            'Content-Type' => $disk->mimeType($file) ?: 'application/octet-stream',
            'ETag' => $etag,
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
        ], public: ! $isHtml);

        if ($isHtml) {
            $response->setPrivate();
            $response->headers->set('Cache-Control', 'no-cache, private');
        } else {
            $response->setPublic();
            $response->headers->set('Cache-Control', 'no-cache, public');
        }

        return $response;
    }
}
