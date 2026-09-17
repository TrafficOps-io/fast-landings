<?php

namespace App\Http\Controllers;

use App\Models\LandingTemplate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class TemplatePreviewController extends Controller
{
    public function __invoke(Request $request, LandingTemplate $template): BinaryFileResponse
    {
        $disk = Storage::disk(config('fast-landings.storage_disk'));
        abort_unless($template->preview_path && $disk->exists($template->preview_path), 404);

        $response = response()->file($disk->path($template->preview_path), [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'private, no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ]);
        $response->setEtag(hash('sha256', $template->preview_path));
        $response->isNotModified($request);

        return $response;
    }
}
