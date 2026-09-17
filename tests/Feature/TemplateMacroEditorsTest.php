<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Livewire\Landings\FromTemplate;
use App\Models\LandingTemplate;
use App\Models\User;
use App\Services\Templates\TemplateMacroSuggestions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;
use TrafficOps\TemplateDsl\TemplateEngine;
use TrafficOps\TemplateDsl\TemplateSourceParser;

class TemplateMacroEditorsTest extends TestCase
{
    use RefreshDatabase;

    public function test_runtime_declarations_are_suggested_in_text_and_rich_text_settings(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Administrator]));
        $definition = app(TemplateEngine::class)->validateDefinition(app(TemplateSourceParser::class)->parse(<<<'TPL'
@param title String = "Hello {body.name}"
@param message Text
@param content Wysiwyg
@param notes Markdown
@validation body fallback="/error"
  @param name String required min=4
  @param phone String mask="+380 ... ... ..."
@endvalidation
@layout
<h1>{{title}}</h1><p>{{message}}</p><main>{{&content}}{{&notes}}</main>
@endlayout
TPL));
        $template = LandingTemplate::query()->create([
            'name' => 'Request form', 'definition' => $definition, 'asset_paths' => [],
            'storage_path' => 'templates/requests', 'original_name' => 'request.tpl', 'uploaded_by' => auth()->id(),
        ]);
        Livewire::test(FromTemplate::class, ['template' => $template])
            ->assertSee('Insert variable')->assertSee('Type { for request values.')
            ->assertSee('body.name')->assertSee('body.phone')->assertSee('query.*')->assertSee('headers.user-agent')
            ->assertSet('values.title', 'Hello {body.name}');
        $this->assertSame(['title', 'message', 'content', 'notes'], array_column($definition['sections'][0]['fields'], 'name'));
    }

    public function test_settings_suggestions_collect_companion_declarations_and_ignore_php_strings(): void
    {
        $suggestions = app(TemplateMacroSuggestions::class)->forDefinition([
            'html' => "@validation query\n@param subid String required\n@endvalidation\n<h1>{query.subid}</h1>",
            'pages' => ['success.php' => "@validation body\n@param customer.name String min=4\n@endvalidation\n<?php echo '{body.hidden}'; ?>"],
            'partials' => ['notice' => '<p>{query.notice}</p>'],
            'sections' => [['fields' => [['name' => 'title', 'default' => 'Hello {body.firstName}']]]],
        ]);
        $tokens = array_column($suggestions, 'token');
        $this->assertContains('{query.subid}', $tokens);
        $this->assertContains('{body.customer.name}', $tokens);
        $this->assertContains('{body.*}', $tokens);
        $this->assertContains('{query.notice}', $tokens);
        $this->assertContains('{body.firstName}', $tokens);
        $this->assertNotContains('{body.hidden}', $tokens);
        $this->assertSame(count($tokens), count(array_unique($tokens)));
    }
}
