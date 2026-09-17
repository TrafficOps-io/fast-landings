<?php

namespace App\Services\Templates;

use App\Models\TemplateMedia;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class TemplateMediaService
{
    public function upload(UploadedFile $file, User $user): TemplateMedia
    {
        abort_unless($user->is_active, 403);
        Validator::make(['editorImage' => $file], [
            'editorImage' => ['required', 'file', 'image', 'mimes:jpeg,png,gif,webp,avif', 'max:'.config('fast-landings.templates.max_image_kb')],
        ])->validate();

        $diskName = config('fast-landings.templates.media_disk') ?: config('filesystems.default');
        $disk = Storage::disk($diskName);
        $filename = Str::ulid().'.'.$file->guessExtension();
        $path = $disk->putFileAs('template-media', $file, $filename);
        if ($path === false) {
            throw new RuntimeException('Unable to store the editor image.');
        }

        try {
            return TemplateMedia::query()->create([
                'filename' => $filename, 'disk' => $diskName, 'path' => $path,
                'mime_type' => $file->getMimeType(), 'uploaded_by' => $user->id,
            ]);
        } catch (Throwable $exception) {
            $disk->delete($path);
            throw $exception;
        }
    }
}
