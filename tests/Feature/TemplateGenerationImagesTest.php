<?php

namespace Tests\Feature;

use App\Models\TemplateMedia;
use App\Models\User;
use App\Services\Templates\TemplateGenerationImages;
use App\Services\Templates\TemplateMediaService;
use GdImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class TemplateGenerationImagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('generation-images');
        config(['fast-landings.templates.media_disk' => 'generation-images']);
    }

    public function test_unconstrained_image_keeps_original_bytes_and_actual_mime(): void
    {
        $bytes = UploadedFile::fake()->image('original.jpg', 320, 200)->getContent();
        $user = User::factory()->create();
        $media = app(TemplateGenerationImages::class)->store($bytes, [], $user);

        $this->assertSame($bytes, Storage::disk($media->disk)->get($media->path));
        $this->assertSame('image/jpeg', $media->mime_type);
        $this->assertSame($user->id, $media->uploaded_by);
        $this->assertSame('generation-images', $media->disk);
        $this->assertDatabaseCount('template_media', 1);
    }

    public function test_fixed_sizes_use_first_size_and_crop_from_center(): void
    {
        $source = imagecreatetruecolor(300, 100);
        imagefilledrectangle($source, 0, 0, 99, 99, imagecolorallocate($source, 255, 0, 0));
        imagefilledrectangle($source, 100, 0, 199, 99, imagecolorallocate($source, 0, 255, 0));
        imagefilledrectangle($source, 200, 0, 299, 99, imagecolorallocate($source, 0, 0, 255));
        $media = app(TemplateGenerationImages::class)->store($this->png($source), [
            'sizes' => [['width' => 50, 'height' => 50], ['width' => 300, 'height' => 100]],
        ], User::factory()->create());

        $bytes = Storage::disk($media->disk)->get($media->path);
        $this->assertSame([50, 50], array_slice(getimagesizefromstring($bytes), 0, 2));
        $this->assertSame('image/png', $media->mime_type);
        $output = imagecreatefromstring($bytes);
        $this->assertSame(0x00FF00, imagecolorat($output, 0, 0));
        $this->assertSame(0x00FF00, imagecolorat($output, 49, 49));
        imagedestroy($output);
    }

    public function test_aspect_ratio_preserves_useful_resolution_and_transparency(): void
    {
        $source = imagecreatetruecolor(320, 320);
        imagealphablending($source, false);
        imagesavealpha($source, true);
        imagefill($source, 0, 0, imagecolorallocatealpha($source, 255, 0, 0, 90));
        $media = app(TemplateGenerationImages::class)->store($this->png($source), [
            'aspect_ratio' => '16:9',
        ], User::factory()->create());

        $bytes = Storage::disk($media->disk)->get($media->path);
        $this->assertSame([320, 180], array_slice(getimagesizefromstring($bytes), 0, 2));
        $output = imagecreatefromstring($bytes);
        $this->assertSame(90, imagecolorsforindex($output, imagecolorat($output, 160, 90))['alpha']);
        imagedestroy($output);
    }

    public function test_matching_dimensions_do_not_reencode_the_image(): void
    {
        $bytes = UploadedFile::fake()->image('original.jpg', 320, 180)->getContent();
        $media = app(TemplateGenerationImages::class)->store($bytes, [
            'aspect_ratio' => 16 / 9,
        ], User::factory()->create());

        $this->assertSame($bytes, Storage::disk($media->disk)->get($media->path));
    }

    #[DataProvider('invalidImages')]
    public function test_invalid_image_bytes_are_rejected_without_storage(string $bytes): void
    {
        try {
            app(TemplateGenerationImages::class)->store($bytes, [], User::factory()->create());
            $this->fail('Invalid image bytes were accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('generation', $exception->errors());
        }
        $this->assertDatabaseCount('template_media', 0);
        $this->assertSame([], Storage::disk('generation-images')->allFiles());
    }

    public static function invalidImages(): array
    {
        return [
            'empty' => [''],
            'text' => ['not an image'],
            'svg' => ['<svg xmlns="http://www.w3.org/2000/svg" width="100" height="100"></svg>'],
        ];
    }

    public function test_compressed_file_size_is_limited_before_image_decoding(): void
    {
        config(['fast-landings.templates.max_image_kb' => 1]);
        $bytes = UploadedFile::fake()->image('large.png', 10, 10)->getContent().str_repeat("\0", 1024);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('exceeds the allowed file size');
        app(TemplateGenerationImages::class)->store($bytes, [], User::factory()->create());
    }

    public function test_pixel_limit_rejects_image_bomb_before_image_decoding(): void
    {
        $bytes = UploadedFile::fake()->image('small.png', 10, 10)->getContent();
        // A valid PNG header declares huge dimensions while its compressed data stays tiny.
        $bytes = substr_replace($bytes, pack('NN', 5001, 5000), 16, 8);
        $bytes = substr_replace($bytes, pack('N', crc32(substr($bytes, 12, 17))), 29, 4);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('25 million pixels');
        app(TemplateGenerationImages::class)->store($bytes, [], User::factory()->create());
    }

    public function test_valid_header_with_corrupt_image_data_is_rejected(): void
    {
        $bytes = substr(UploadedFile::fake()->image('truncated.png', 10, 10)->getContent(), 0, 33);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('could not be decoded');
        app(TemplateGenerationImages::class)->store($bytes, [], User::factory()->create());
    }

    public function test_resized_output_must_still_fit_the_file_size_limit(): void
    {
        $source = imagecreatetruecolor(100, 100);
        for ($x = 0; $x < 100; $x++) {
            for ($y = 0; $y < 100; $y++) {
                imagesetpixel($source, $x, $y, (($x * 91 + $y * 67) % 256) << 16 | (($x * 37 + $y * 53) % 256) << 8 | (($x * 19 + $y * 83) % 256));
            }
        }
        ob_start();
        imagejpeg($source, null, 1);
        $bytes = ob_get_clean();
        imagedestroy($source);
        config(['fast-landings.templates.max_image_kb' => (int) ceil(strlen($bytes) / 1024)]);

        try {
            app(TemplateGenerationImages::class)->store($bytes, [
                'sizes' => [['width' => 150, 'height' => 150]],
            ], User::factory()->create());
            $this->fail('Oversized PNG output was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('generation', $exception->errors());
        }
        $this->assertDatabaseCount('template_media', 0);
    }

    public function test_temporary_file_is_removed_when_storage_fails(): void
    {
        $temporaryPath = null;
        $this->mock(TemplateMediaService::class, function (MockInterface $mock) use (&$temporaryPath): void {
            $mock->shouldReceive('upload')->once()->withArgs(function (UploadedFile $file, User $user) use (&$temporaryPath): bool {
                $temporaryPath = $file->getRealPath();

                return is_file($temporaryPath);
            })->andThrow(new RuntimeException('Storage failure'));
        });
        try {
            app(TemplateGenerationImages::class)->store(
                UploadedFile::fake()->image('image.png', 10, 10)->getContent(), [], User::factory()->create(),
            );
            $this->fail('Storage failure was not propagated.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Storage failure', $exception->getMessage());
        }
        $this->assertNotNull($temporaryPath);
        $this->assertFileDoesNotExist($temporaryPath);
    }

    public function test_discard_removes_the_stored_image_and_media_record(): void
    {
        $service = app(TemplateGenerationImages::class);
        $media = $service->store(
            UploadedFile::fake()->image('image.png', 10, 10)->getContent(), [], User::factory()->create(),
        );

        $service->discard($media);

        Storage::disk($media->disk)->assertMissing($media->path);
        $this->assertNull(TemplateMedia::find($media->id));
    }

    public function test_vision_input_limits_dimensions_and_flattens_transparency(): void
    {
        $source = imagecreatetruecolor(2000, 1000);
        imagealphablending($source, false);
        imagesavealpha($source, true);
        imagefill($source, 0, 0, imagecolorallocatealpha($source, 0, 0, 0, 127));
        $input = app(TemplateGenerationImages::class)->visionInput($this->png($source));

        $this->assertSame('image/jpeg', $input['mime_type']);
        $bytes = base64_decode($input['data'], true);
        $this->assertLessThanOrEqual(768 * 1024, strlen($bytes));
        $this->assertSame([1568, 784], array_slice(getimagesizefromstring($bytes), 0, 2));
        $output = imagecreatefromstring($bytes);
        $color = imagecolorsforindex($output, imagecolorat($output, 10, 10));
        $this->assertGreaterThanOrEqual(250, min($color['red'], $color['green'], $color['blue']));
        imagedestroy($output);
        $this->assertDatabaseCount('template_media', 0);
    }

    public function test_vision_input_accepts_gif_and_does_not_upscale_small_images(): void
    {
        $bytes = UploadedFile::fake()->image('input.gif', 200, 100)->getContent();
        $input = app(TemplateGenerationImages::class)->visionInput($bytes);

        $this->assertSame('image/jpeg', $input['mime_type']);
        $this->assertSame([200, 100], array_slice(getimagesizefromstring(base64_decode($input['data'], true)), 0, 2));
    }

    public function test_vision_input_reduces_quality_to_fit_noisy_images_within_attachment_limit(): void
    {
        $source = imagecreatetruecolor(1568, 1568);
        $noise = random_bytes(1568 * 1568 * 3);
        for ($y = 0, $offset = 0; $y < 1568; $y++) {
            for ($x = 0; $x < 1568; $x++, $offset += 3) {
                imagesetpixel($source, $x, $y, ord($noise[$offset]) << 16 | ord($noise[$offset + 1]) << 8 | ord($noise[$offset + 2]));
            }
        }
        $input = app(TemplateGenerationImages::class)->visionInput($this->png($source));

        $bytes = base64_decode($input['data'], true);
        $this->assertLessThanOrEqual(768 * 1024, strlen($bytes));
        $this->assertSame([1568, 1568], array_slice(getimagesizefromstring($bytes), 0, 2));
    }

    public function test_vision_input_rejects_corrupt_images_instead_of_forwarding_them(): void
    {
        $bytes = substr(UploadedFile::fake()->image('truncated.png', 10, 10)->getContent(), 0, 33);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('could not be decoded');
        app(TemplateGenerationImages::class)->visionInput($bytes);
    }

    private function png(GdImage $image): string
    {
        ob_start();
        imagepng($image);
        $bytes = ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }
}
