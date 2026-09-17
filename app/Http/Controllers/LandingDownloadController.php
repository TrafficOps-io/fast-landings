<?php

namespace App\Http\Controllers;

use App\Models\LandingRelease;
use App\Services\LandingDownloadService;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class LandingDownloadController extends Controller
{
    public function __invoke(LandingRelease $release, LandingDownloadService $downloads): BinaryFileResponse
    {
        $slug = Str::slug($release->landing()->firstOrFail()->slug) ?: 'landing';
        $archive = $downloads->archive($release);

        return response()->download($archive, $slug.'-'.$release->id.'.zip', [
            'Content-Type' => 'application/zip',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ])->setPrivate()->deleteFileAfterSend();
    }
}
