<?php

namespace App\Services\Templates;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Application-owned storage/upload policy; not part of the template DSL. */
final class TemplateImageUploadPolicy
{
    public function validate(UploadedFile $file, array $field, string $path): void
    {
        $data = [];
        data_set($data, $path, $file);
        Validator::make($data, [$path => [
            'required', 'file', 'image', 'mimes:jpeg,png,gif,webp,avif', 'max:'.config('fast-landings.templates.max_image_kb', 10240),
        ]])->validate();
        [$width, $height] = getimagesize($file->getRealPath());
        if (isset($field['sizes']) && ! collect($field['sizes'])->contains(fn ($size) => $size['width'] === $width && $size['height'] === $height)) {
            throw ValidationException::withMessages([$path => 'Crop the image to one of the template sizes.']);
        }
        // Allow one pixel of rounding when an aspect ratio produces fractional dimensions.
        if (isset($field['aspect_ratio']) && abs($width - $height * $field['aspect_ratio']) > max(1, $field['aspect_ratio'])) {
            throw ValidationException::withMessages([$path => 'Crop the image to the aspect ratio required by the template.']);
        }
    }
}
