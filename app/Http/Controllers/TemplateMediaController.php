<?php

namespace App\Http\Controllers;

use App\Models\LandingRelease;
use App\Models\TemplateMedia;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TemplateMediaController extends Controller
{
    public function __invoke(Request $request, string $filename): StreamedResponse
    {
        $media = TemplateMedia::query()->where('filename', $filename)->firstOrFail();
        $disk = Storage::disk($media->disk);
        $path = $media->path;
        if (! $disk->exists($path) && is_string($request->query('release'))) {
            $release = LandingRelease::query()->find($request->query('release'));
            abort_unless($release, 404);
            $disk = Storage::disk(config('fast-landings.storage_disk'));
            $path = $release->storage_path.'/_media/'.$filename;
        }
        abort_unless($disk->exists($path), 404);

        return $disk->response($path, $media->filename, [
            'Content-Type' => $media->mime_type,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }
}
