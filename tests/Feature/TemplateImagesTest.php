<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Livewire\Landings\FromTemplate;
use App\Models\User;
use App\Services\Templates\TemplateArchiveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class TemplateImagesTest extends TestCase
{
    use RefreshDatabase;

    private function template(): array
    {
        Storage::fake('landings');
        $user = User::factory()->create(['role' => UserRole::Administrator]);
        $template = app(TemplateArchiveService::class)->import(new UploadedFile(
            public_path('examples/article-template.zip'), 'article.zip', null, null, true,
        ), $user);

        return [$user, $template];
    }

    public function test_nested_upload_validates_output_and_clear_removes_both_image_sources(): void
    {
        [$user, $template] = $this->template();
        $component = Livewire::actingAs($user)->test(FromTemplate::class, ['template' => $template])
            ->assertSee('Crop & resize', false)
            ->set('uploads.comments.items.0.avatar', UploadedFile::fake()->image('wrong.png', 300, 100))
            ->call('imageUploaded', 'comments.items.0.avatar')->assertHasErrors('uploads.comments.items.0.avatar');
        $component->set('uploads.comments.items.0.avatar', UploadedFile::fake()->image('avatar.png', 256, 256))
            ->call('imageUploaded', 'comments.items.0.avatar')->assertHasNoErrors()
            ->call('clearImage', 'comments.items.0.avatar')->assertSet('values.comments.items.0.avatar', '')
            ->assertSet('uploads.comments.items.0.avatar', null);
    }

    public function test_preview_serves_only_images_listed_in_the_template_and_requires_login(): void
    {
        [$user, $template] = $this->template();
        $url = route('templates.image', ['template' => $template, 'path' => 'assets/logo.svg']);
        $this->get($url)->assertRedirect(route('login'));
        $this->actingAs($user)->get($url)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Content-Security-Policy', "sandbox; default-src 'none'; style-src 'unsafe-inline'");
        foreach (['assets/app.js', 'assets/missing.png', '../private.png', 'assets/../../private.png'] as $path) {
            $this->get(route('templates.image', ['template' => $template, 'path' => $path]))->assertNotFound();
        }
    }

    public function test_create_enforces_new_image_constraints_even_without_the_editor_callback(): void
    {
        [$user, $template] = $this->template();
        Livewire::actingAs($user)->test(FromTemplate::class, ['template' => $template])
            ->set('name', 'Invalid crop')->set('slug', 'invalid-crop')
            ->set('uploads.comments.items.0.avatar', UploadedFile::fake()->image('wrong.png', 300, 100))
            ->call('create')->assertHasErrors('uploads.comments.items.0.avatar');
        $this->assertDatabaseCount('landings', 0);
    }
}
