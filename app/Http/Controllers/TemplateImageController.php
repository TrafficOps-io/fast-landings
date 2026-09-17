<?php

namespace App\Http\Controllers;

use App\Models\LandingRelease;
use App\Models\LandingTemplate;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TemplateImageController extends Controller
{
    public function template(Request $request, LandingTemplate $template): StreamedResponse
    {
        $path = $request->string('path')->toString();
        abort_unless(in_array($path, $template->asset_paths, true), 404);

        return $this->image($template->storage_path, $path);
    }

    public function release(Request $request, LandingRelease $release): StreamedResponse
    {
        $path = $request->string('path')->toString();
        abort_unless(in_array($path, Arr::flatten($release->template_values ?? []), true)
            && preg_match('~^_(?:uploads|media)/[A-Za-z0-9]+\.(?:jpe?g|png|gif|webp|avif)$~D', $path), 404);

        return $this->image($release->storage_path, $path);
    }

    private function image(string $directory, string $path): StreamedResponse
    {
        abort_unless(preg_match('~\.(?:jpe?g|png|gif|webp|avif|svg)$~iD', $path)
            && ! str_contains($path, '..') && ! str_contains($path, '\\') && ! str_starts_with($path, '/'), 404);
        $disk = Storage::disk(config('fast-landings.storage_disk'));
        abort_unless($disk->exists($directory.'/'.$path), 404);

        return $disk->response($directory.'/'.$path, basename($path), [
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "sandbox; default-src 'none'; style-src 'unsafe-inline'",
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }
}
