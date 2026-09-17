<?php

namespace App\Services\Templates;

use App\Models\TemplateMedia;
use App\Models\User;
use GdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use TrafficOps\TemplateDsl\TemplateImageOptions;

class TemplateGenerationImages
{
    private const MAX_PIXELS = 25_000_000;

    private const EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'image/avif' => 'avif',
    ];

    public function __construct(private TemplateMediaService $media, private TemplateImageUploadPolicy $uploads) {}

    public function store(string $bytes, array $field, User $user): TemplateMedia
    {
        abort_unless($user->is_active, 403);
        $dimensions = $this->inspect($bytes);
        [$width, $height] = $dimensions;
        $field = TemplateImageOptions::normalize($field + ['type' => 'image'], 'generation');
        if ($field['type'] !== 'image') {
            $this->invalid('Choose an image field in this template.');
        }

        $temporary = tmpfile();
        if ($temporary === false) {
            throw new RuntimeException('Unable to prepare the generated image.');
        }
        $path = stream_get_meta_data($temporary)['uri'];
        $source = null;
        $output = null;

        try {
            if (fwrite($temporary, $bytes) !== strlen($bytes)) {
                throw new RuntimeException('Unable to prepare the generated image.');
            }
            $mime = $dimensions['mime'];
            $file = new UploadedFile($path, 'image.'.self::EXTENSIONS[$mime], $mime, null, true);
            $this->uploads->validate($file, [], 'generation');
            // Decode only after enforcing both the compressed-byte and pixel limits.
            $source = $this->decode($bytes);

            [$targetWidth, $targetHeight] = $this->targetSize($field, $width, $height);
            if ($targetWidth !== $width || $targetHeight !== $height) {
                $output = $this->crop($source, $width, $height, $targetWidth, $targetHeight);
                if (! imagepng($output, $path)) {
                    throw new RuntimeException('Unable to resize the generated image.');
                }
                clearstatcache(true, $path);
                $file = new UploadedFile($path, 'image.png', 'image/png', null, true);
            }

            $this->uploads->validate($file, $field, 'generation');

            return $this->media->upload($file, $user);
        } finally {
            if ($source instanceof GdImage) {
                imagedestroy($source);
            }
            if ($output instanceof GdImage) {
                imagedestroy($output);
            }
            fclose($temporary);
        }
    }

    /** Create a bounded provider attachment without changing the original website asset. */
    public function visionInput(string $bytes): array
    {
        [$width, $height] = $this->inspect($bytes);
        $source = $this->decode($bytes);
        $output = null;

        try {
            $scale = min(1, 1568 / max($width, $height));
            $targetWidth = max(1, (int) round($width * $scale));
            $targetHeight = max(1, (int) round($height * $scale));
            $output = imagecreatetruecolor($targetWidth, $targetHeight);
            if ($output === false) {
                throw new RuntimeException('Unable to prepare the image for AI input.');
            }
            // JPEG has no alpha; flatten transparent artwork onto a predictable background.
            imagefill($output, 0, 0, imagecolorallocate($output, 255, 255, 255));
            if (! imagecopyresampled($output, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height)) {
                throw new RuntimeException('Unable to prepare the image for AI input.');
            }
            foreach ([85, 75, 65, 55, 45, 35, 25, 15, 5] as $quality) {
                ob_start();
                try {
                    if (! imagejpeg($output, null, $quality)) {
                        throw new RuntimeException('Unable to prepare the image for AI input.');
                    }
                    $encoded = ob_get_contents();
                } finally {
                    ob_end_clean();
                }
                if (strlen($encoded) <= 768 * 1024) {
                    return ['mime_type' => 'image/jpeg', 'data' => base64_encode($encoded)];
                }
            }
            $this->invalid('The image could not be reduced enough for AI input. Choose a smaller image.');
        } finally {
            imagedestroy($source);
            if ($output instanceof GdImage) {
                imagedestroy($output);
            }
        }
    }

    public function discard(TemplateMedia $media): void
    {
        if (! Storage::disk($media->disk)->delete($media->path)) {
            throw new RuntimeException('Unable to remove the generated image.');
        }
        $media->delete();
    }

    private function inspect(string $bytes): array
    {
        $limit = (int) config('fast-landings.templates.max_image_kb', 10240) * 1024;
        if ($bytes === '' || strlen($bytes) > $limit) {
            $this->invalid('The image is empty or exceeds the allowed file size.');
        }
        $dimensions = @getimagesizefromstring($bytes);
        if ($dimensions === false || ! isset(self::EXTENSIONS[$dimensions['mime'] ?? ''])) {
            $this->invalid('Use a valid JPEG, PNG, GIF, WebP or AVIF image.');
        }
        if ($dimensions[0] < 1 || $dimensions[1] < 1 || $dimensions[0] * $dimensions[1] > self::MAX_PIXELS) {
            $this->invalid('Images must contain no more than 25 million pixels.');
        }

        return $dimensions;
    }

    private function decode(string $bytes): GdImage
    {
        if (! function_exists('imagecreatefromstring')) {
            throw new RuntimeException('The GD extension is required to prepare generated images.');
        }
        $source = @imagecreatefromstring($bytes);
        if ($source === false) {
            $this->invalid('The image could not be decoded. Choose a different image.');
        }

        return $source;
    }

    private function targetSize(array $field, int $width, int $height): array
    {
        if (isset($field['sizes'])) {
            return [$field['sizes'][0]['width'], $field['sizes'][0]['height']];
        }
        if (isset($field['aspect_ratio'])) {
            $ratio = (float) $field['aspect_ratio'];
            if ($width / $height > $ratio) {
                return [max(1, (int) round($height * $ratio)), $height];
            }

            return [$width, max(1, (int) round($width / $ratio))];
        }

        return [$width, $height];
    }

    private function crop(GdImage $source, int $width, int $height, int $targetWidth, int $targetHeight): GdImage
    {
        $ratio = $targetWidth / $targetHeight;
        $cropWidth = min($width, max(1, (int) round($height * $ratio)));
        $cropHeight = min($height, max(1, (int) round($width / $ratio)));
        $output = imagecreatetruecolor($targetWidth, $targetHeight);
        if ($output === false) {
            throw new RuntimeException('Unable to resize the generated image.');
        }
        imagealphablending($output, false);
        imagesavealpha($output, true);
        if (! imagecopyresampled(
            $output, $source, 0, 0,
            (int) floor(($width - $cropWidth) / 2), (int) floor(($height - $cropHeight) / 2),
            $targetWidth, $targetHeight, $cropWidth, $cropHeight,
        )) {
            imagedestroy($output);
            throw new RuntimeException('Unable to resize the generated image.');
        }

        return $output;
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['generation' => $message]);
    }
}
