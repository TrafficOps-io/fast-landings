<?php

namespace App\Http\Controllers;

use App\Models\LandingTemplate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class TemplateAssetController extends Controller
{
    public function __invoke(LandingTemplate $template, string $path): StreamedResponse
    {
        $path = rawurldecode($path);
        abort_unless(in_array($path, $template->asset_paths, true), 404);
        abort_unless(! str_contains($path, '..') && ! str_contains($path, '\\') && ! str_starts_with($path, '/'), 404);

        $disk = Storage::disk(config('fast-landings.storage_disk'));
        $file = $template->storage_path.'/'.$path;
        abort_unless($disk->exists($file), 404);

        return $disk->response($file, basename($path), [
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; font-src 'self'; img-src 'self' data:",
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }
}
