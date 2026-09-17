<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\FileWorkspaceService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class FileWorkspaceController extends Controller
{
    public function show(Request $request, string $workspace, FileWorkspaceService $files): BinaryFileResponse
    {
        $user = $this->activeUser($request);
        abort_unless(is_string($request->query('path')), 404);
        try {
            $path = $files->absolutePath($workspace, $request->string('path')->toString(), $user);
        } catch (ValidationException) {
            abort(404);
        }
        $headers = $this->headers();
        if ($request->boolean('download')) {
            return response()->download($path, basename($path), [...$headers, 'Content-Type' => 'application/octet-stream'])->setPrivate();
        }

        $mime = @getimagesize($path)['mime'] ?? null;
        $headers['Content-Type'] = in_array($mime, ['image/png', 'image/jpeg', 'image/gif', 'image/webp', 'image/avif'], true)
            ? $mime : 'text/plain; charset=UTF-8';

        return response()->file($path, $headers)->setPrivate();
    }

    public function download(Request $request, string $workspace, FileWorkspaceService $files): BinaryFileResponse
    {
        $user = $this->activeUser($request);
        $info = $files->info($workspace, $user);
        $path = $files->archive($workspace, $user);

        return response()->download($path, $info['kind'].'-files.zip', [
            ...$this->headers(), 'Content-Type' => 'application/zip',
        ])->setPrivate()->deleteFileAfterSend();
    }

    private function activeUser(Request $request): User
    {
        $user = User::query()->find($request->user()?->id);
        abort_unless($user?->is_active, 403);

        return $user;
    }

    private function headers(): array
    {
        return [
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "sandbox; default-src 'none'; frame-ancestors 'self'",
            'Cache-Control' => 'private, no-store',
        ];
    }
}
