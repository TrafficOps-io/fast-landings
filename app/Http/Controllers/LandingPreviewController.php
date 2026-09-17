<?php

namespace App\Http\Controllers;

use App\Models\LandingRelease;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class LandingPreviewController extends Controller
{
    public function __invoke(Request $request, LandingRelease $release): BinaryFileResponse
    {
        $disk = Storage::disk(config('fast-landings.storage_disk'));
        abort_unless($release->preview_path && $disk->exists($release->preview_path), 404);

        $response = response()->file($disk->path($release->preview_path), [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'private, no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ]);
        $response->setPrivate();
        $response->setEtag(hash('sha256', $release->preview_path));
        $response->isNotModified($request);

        return $response;
    }
}
