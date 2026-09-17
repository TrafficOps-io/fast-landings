# Landing templates

На русском: [руководство по шаблонам](ru/templates.md), [справочник DSL v1](ru/template-dsl.md), [HTTP-макросы, формы и PHP](ru/template-runtime.md).

Templates are text files that combine typed settings, reusable HTML blocks, and page layouts. An administrator imports a template once; any active panel user can then choose **Create landing**, fill its generated settings form, and create a normal landing release containing HTML, assets and PHP scripts. Domains, delivery, activation, and rollback use the existing landing workflow.

Settings and uploaded images are saved with the generated landing. Removing a template leaves its generated landings and releases available.

## Updating a library template

On **Templates**, administrators can choose **Edit** on a template card to change its name and description. An optional **Replacement file** accepts the same HTML, PHP, TXT, TPL or ZIP formats as importing. Leave it empty to update only the details; when replacing the content, the name and description in the form take precedence over those declared in the uploaded file.

**Save template** preserves the template's identity and links to existing landings. Replacement packages are validated before switching; failed uploads leave the current template intact. Existing landing releases retain their HTML and assets. New landings and future edits use the updated definition, so changes to field names or types may require updating the saved landing settings before generating another release.

## AI-assisted content generation

In **Create landing**, open **Generate content with AI** before creating the landing:

1. Select an existing AI connection and describe the content, language, audience, and desired number of items in the prompt.
2. Optionally set exact counts for repeatable fields. These counts override the prompt, respect the template limits, and apply to every matching nested array. Leave a count blank to let AI infer it from the prompt.
3. Add multiple **Images for the site** for insertion into image fields or rich text. Add visual examples separately under **Reference images**; these guide generation and are never packaged into the landing.
4. Optionally select **Generate new images**. Selecting an image inside a repeatable block generates one image per resulting item. No images are generated when no fields are selected.
5. Choose **Generate content**. The request runs in the queue while the form stays editable. When ready, choose **Apply to editor**, review the template sections, and use **Create landing** to create the release.

Applying a result replaces the current template values. Pending uploads attached directly to template fields must be removed or moved to **Images for the site** before generating/applying. Generation does not publish a landing or alter existing releases. Requests cannot be duplicated while one is in progress for the same user and template; the result is restored when that user reopens the template. If the template changes, generate a fresh result.

The AI receives the user's prompt, current values, the complete typed schema with field/group/repeater `aiInstructions`, reusable block instructions, layout context, and TPL data rules. It returns values rather than editable template code. Unknown fields, invalid types, out-of-range values, wrong explicit array counts, and image sources that were not supplied are rejected before applying a result. Generated images are centered and cropped/resized to the field's first allowed size or aspect ratio.

Uploads accept JPEG, PNG, WebP, GIF, and AVIF: up to 8 files per purpose, 10 MB each, 20 MB total, and 25 megapixels per image. AI receives resized JPEG copies (up to 1568 pixels on the longest edge); the original website uploads are used for the landing, with cropping where required. Reference uploads are kept privately only while the job needs them. At most 8 images can be generated per request after arrays are expanded. Provider errors leave the form unchanged; retrying is an explicit action, because an interrupted provider call can still be billed.

### Providers and models

Text generation supports OpenAI, OpenRouter, Anthropic, Google Gemini, DeepSeek, and an OpenAI Chat Completions compatible custom endpoint. Image generation is supported by OpenAI and Gemini. The connection's saved API URL and encrypted key are used only on the server. Users can choose connections but never see their keys.

Default models and vision capabilities are defined in `config/ai.php`. Override them in the application environment if a model is unavailable to your account:

| Provider | Text model environment variable | Image model environment variable |
| --- | --- | --- |
| OpenAI | `AI_OPENAI_TEXT_MODEL` | `AI_OPENAI_IMAGE_MODEL` |
| OpenRouter | `AI_OPENROUTER_TEXT_MODEL` | — |
| Anthropic | `AI_ANTHROPIC_TEXT_MODEL` | — |
| Gemini | `AI_GEMINI_TEXT_MODEL` | `AI_GEMINI_IMAGE_MODEL` |
| DeepSeek | `AI_DEEPSEEK_TEXT_MODEL` | — |
| Custom | `AI_CUSTOM_TEXT_MODEL` (required) | — |

Each provider has an `AI_<PROVIDER>_VISION` switch; match it to the chosen model. Custom connections default to vision disabled. Configure `AI_MAX_OUTPUT_TOKENS` when larger templates need longer responses. `AI_REQUEST_TIMEOUT` and `AI_IMAGE_REQUEST_TIMEOUT` control individual requests. Provider requests do not retry automatically or follow redirects, and generated images must be returned as inline bytes.

Run migrations and keep the normal queue worker running. Jobs have a 30-minute maximum; database, Redis, and Beanstalk queue reservations are at least 31 minutes to prevent duplicate work. For deployments, allow workers to finish active jobs before stopping them. A terminated generation becomes retryable after its timeout. PHP GD with JPEG/WebP/AVIF support is included in the production image; rebuild the image when upgrading.

## Editing an existing landing

Open the landing's **Landing content** panel:

- **Edit template data** loads the current template's saved values, including repeatable blocks and image paths. Change any fields and choose **Save and activate**. Existing uploads are copied into the new release unless cleared or replaced.
- **Change template** lets you select another template and fill a fresh form with its defaults. Changing the selection clears unsaved fields and uploads; reselecting the current template loads its saved values.
- **Replace with ZIP archive** uploads ordinary website content and clears the active template association.
- For a ZIP landing, **Use a template** opens the same template chooser and replaces its content with a generated release.

These operations preserve the landing's identity, domains, and publication status. Changes go live only after successful validation and compilation. Previous releases retain their files and template settings; activating one restores those settings. An editor opened before another release was activated must be reloaded before saving.

If a template was deleted, its generated releases can still be served and activated, but editing their fields requires choosing an available template. Releases created before template snapshots were introduced have no recoverable historical settings; the migration copies the known settings into the active release only.

## Try the examples

- [Single-file article template](../public/examples/article-template.html): one editable text file containing declarations, blocks, HTML, CSS, and JavaScript.
- [Article template ZIP](../public/examples/article-template.zip): the same layout with a separate comment block, CSS, JavaScript, logo, and avatar. Its editable source is in [`examples/article-template`](examples/article-template).
- [Form website template ZIP](../public/examples/form-website-template.zip): an HTML form submitting to a PHP success page, with shared editable headings. Its source is in [`examples/form-website`](examples/form-website).
- [Runtime form template ZIP](../public/examples/runtime-form-template.zip): query/body validation and a success message with request macros editable in landing settings. Open the generated site with `?subid=demo&pixel=1234567890`; source is in [`examples/runtime-form`](examples/runtime-form).

Open **Templates** in the panel, import either file as an administrator, and choose **Create landing**. The form contains Appearance, Header, Article, Comments, and Footer sections. Each comment has its own name, text, avatar, date, and verification flag. Image fields accept a URL or an uploaded image.

## Upload format

Upload one UTF-8 `.html`, `.php`, `.txt`, or `.tpl` source file, or one ZIP containing exactly one entrypoint: `template.html`, `template.txt`, `template.tpl`, `index.html`, `index.php`, `index.tpl.html`, or `index.tpl.php`. The entrypoint must be at the root or inside a single wrapper folder containing all other files. Assets retain their relative paths. Existing `template.*` entrypoints continue to generate `index.html`.

```text
article-template.zip
├── template.html
├── blocks/
│   └── comment.tpl
└── assets/
    ├── style.css
    ├── app.js
    ├── logo.svg
    └── avatar.svg
```

Only one text source or ZIP is selected in the upload control. ZIP imports reject traversal, symbolic links, colliding paths, excessive extraction, and files outside the selected wrapper. Existing `FAST_LANDINGS_MAX_*` archive limits apply. The entrypoint, compiled page sources and included source fragments are excluded from the compiled release; generated pages and assets are copied into it. A generated page cannot overwrite a packaged asset.

## Multiple pages, forms and PHP

A template package can produce a complete website. Files ending in `.tpl.html` compile to `.html`; files ending in `.tpl.php` compile to `.php`, preserving their folders. For example:

```text
website.zip                       generated release
├── index.tpl.html                ├── index.html
├── success.tpl.php               ├── success.php
├── privacy.tpl.html              ├── privacy.html
└── assets/site.css               └── assets/site.css
```

All compiled pages share the same settings, author-defined types and reusable blocks. Declare each setting/type/block once, in any page or an included `.tpl` fragment. Settings declared in `success.tpl.php` appear in the same landing creation/edit form as settings declared in `index.tpl.html`; use `@section` in each page to group them under **Main page**, **Success page**, and other labels. Only the main page needs `@template`. Each page with declarations or layout directives uses its own `@layout`/`@endlayout` pair. A companion page containing only markup, `{{settings}}` and top-level `@validation` blocks can omit that pair; request validation is extracted before the importer adds the implicit layout.

The main file can contain:

```html
@template "Contact form"
@section index "Main page"
@param heading String = "Send a request"
@endsection
@layout
<h1>{{heading}}</h1>
<form action="success.php" method="post">
  <input name="name" required>
  <button type="submit">Send</button>
</form>
@endlayout
```

And `success.tpl.php` can contain:

```php
@section success "Success page"
@param successHeading String = "Thank you"
@endsection
@layout
<?php
header('Cache-Control: no-store');
$name = is_string($_POST['name'] ?? null) ? $_POST['name'] : '';
?>
<h1>{{successHeading}}</h1>
<p>Hello, <?= htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>.</p>
@endlayout
```

Link to generated names in `action`, links, redirects and PHP includes: `success.php`, not `success.tpl.php`. Pages without request macros remain static HTML; pages with runtime macros or validation compile to PHP automatically. Ordinary companion PHP files, such as `success.php` and `lib/helpers.php`, need no template directives. A plain `index.php` entrypoint is accepted without declarations or `@layout`.

PHP blocks (`<?php … ?>` and `<?= … ?>`) remain literal during template compilation: their code runs only when requested through the configured landing PHP runtime. Template settings interpolate in the surrounding HTML; `{{heading}}` inside a PHP string remains that literal string. A PHP page may leave its last PHP block open; put the template's closing `@endlayout` directive on its own line. Templates are compiled once when creating or updating a release; form values and PHP request handling run separately on each visitor request. See [deployment instructions](../deploy/README.md) for the PHP runtime configuration.

The private screenshot worker does not execute PHP. PHP entrypoint templates need a deployed `previewUrl` to produce a library screenshot. HTML entrypoints can still use `previewData` when they have PHP companion pages; those PHP scripts are omitted from the screenshot document root.

## Request macros and validation

Single braces read the current visitor request: `{query.subid}`, `{headers.user-agent}`, `{body.name}`. Dotted paths traverse nested form/JSON values, for example `{body.customer.name}` or `{body.items.0.name}`. `{query.*}`, `{headers.*}` and `{body.*}` produce the entire source as JSON. Query and body values are separate; body reads form submissions or JSON, and header names are case-insensitive. Missing values render as empty text. Values are HTML-escaped and are never evaluated as PHP or as another macro. Write `\{body.name}` to show a literal macro.

`{{title}}` still reads a saved template setting. That setting can itself contain `Thank you, {body.name}!`: the setting is compiled at publication, while the request value is inserted on every visit. Text, multiline text, Wysiwyg and Markdown settings support **Insert variable** and suggestions after typing `{`. Suggestions include validation parameters from all template pages. URL settings allow macros within a valid static URL and must retain its scheme and host. A complete dynamic URL such as `{query.url}` is not accepted as a URL setting. Email settings can contain a whole macro such as `{body.email}` or a macro within an address.

Declare request rules outside `@layout`, separately on each page. These parameters do not create fields in the landing settings form:

```html
@validation query fallback="/error.html"
  @param subid String required
  @param pixel String length=10 required
@endvalidation
@layout
<form action="/success.php" method="post">
  <input type="hidden" name="subid" value="{query.subid}">
  <input name="name" required minlength="4">
  <input name="phone" required placeholder="+380 123 456 789">
  <button type="submit">Send</button>
</form>
@endlayout
```

In `success.tpl.php`:

```html
@validation body fallback="submit-error.html"
  @param phone String required mask="+380 ... ... ..."
  @param name String required min=4
@endvalidation
@layout
<p>Спасибо за заказ, {body.name}! Вам поступит звонок в течение 5 минут на номер {body.phone}.</p>
@endlayout
```

Include the fallback pages in the website. Rules run before page output and before its PHP code. Validation redirects to the declared local absolute or relative URL (`302` for GET/HEAD, `303` for other methods). Without `fallback`, invalid requests return `422`. Validation is scoped to the requested page: rules on the success page do not require a body when opening the main page. Validated parameters retain their original request representation when rendered.

| Declaration | Meaning |
| --- | --- |
| `String` | A string; arrays/objects are rejected |
| `Number` / `Integer` | A finite numeric value / an integer |
| `Boolean` | A boolean or an accepted boolean representation |
| `required` | Must be present and nonempty |
| `min=4 max=120` | Character count for String; numeric bounds for Number/Integer |
| `length=10` | Exact String length (`lenght` is accepted as an alias) |
| `mask="+380 ... ... ..."` | Each dot matches one ASCII digit; every other character matches literally |

Runtime values are supported in HTML text and quoted HTML attributes. URLs need a fixed safe prefix. JavaScript/CSS bodies, event attributes, `style`, `srcdoc`, `srcset`, and unquoted attributes reject macros: pass values through quoted `data-*` attributes and read them from JavaScript. PHP blocks are opaque to macro interpolation; use normal PHP request APIs inside PHP code. Validation rules are authored in template/file source, not in editable text settings.

The VS Code extension and the panel code editor provide validation directive/rule completion, page-specific request parameter completion, hover help and syntax highlighting. The landing settings form aggregates available request parameters across pages. Ordinary ZIP landings can use these same validation blocks and macros in HTML/PHP pages without a template settings schema.

At publication, a dynamic `index.html` becomes `index.php`; a dynamic `success.html` becomes `success.php`. Requests to the original `.html` URL are internally routed to PHP, preserving query parameters and POST bodies. A conflicting PHP output path fails publication. The PHP runtime must be configured, including the updated Caddy routing in production. Dynamic responses are private and are not cached as static pages. Original macro sources are retained privately for subsequent code edits; opening a landing file workspace restores those sources. Panel previews do not evaluate visitor requests or PHP and remove validation declarations from their HTML preview.

## A complete minimal template

```html
@template "Simple article" description="An article with configurable content." version=1

@section content "Article"
@param title String = "Hello" label="Title" required=true
@param body Text = "Write something useful." label="Article text"
@endsection

@block article(title: String, body: Text)
<article>
  <h1>{{title}}</h1>
  <p style="white-space:pre-line">{{body}}</p>
</article>
@endblock

@layout
<!doctype html>
<html>
<head><meta charset="utf-8"><title>{{title}}</title></head>
<body>
  @render article(title, body)
</body>
</html>
@endlayout
```

Put each directive on its own line. `{{path}}` interpolation can appear anywhere within an HTML line. Quote string literals; `\n` in a quoted default represents a line break. A template requires one `@layout`/`@endlayout` pair. `@template` sets its name, optional description, and language version.

## Library previews

Templates can optionally supply sample values for a screenshot in the template library. Add one top-level `@previewData` block containing a JSON object whose keys match the declared settings:

```html
@template "Simple article" version=1

@previewData
{
  "title": "A practical guide to a brighter everyday",
  "body": "Small changes can make a difference.\nStart with one useful habit."
}
@endpreviewData

@param title String = "New article" label="Title" required
@param body Text = "" label="Article text"

@layout
<!doctype html>
<html><head><meta charset="utf-8"><title>{{title}}</title></head>
<body><article><h1>{{title}}</h1><p style="white-space:pre-line">{{body}}</p></article></body></html>
@endlayout
```

Nested groups use JSON objects and repeaters use arrays of objects. For a template with `article` and `comments` settings, sample values can look like this:

```text
@previewData
{
  "article": {"title": "A sample article", "cover": "assets/cover.jpg"},
  "comments": [
    {"name": "Alex", "body": "A useful read."},
    {"name": "Sam", "body": "I will try this."}
  ]
}
@endpreviewData
```

Omitted values use their normal field defaults, including defaults inside groups and repeater items. Values must satisfy the same type, required-field and item-count rules as a published landing; unknown settings are rejected. Preview values do not change editor defaults or the values of landings created from the template. Bundled relative assets are available to the generated preview. An empty object (`{}`) opts into a preview using only defaults. Without either preview option, the template has no generated preview.

For small objects, the header also accepts `previewData='{"title":"Demo"}'`. Use the multiline block for text with JSON escapes or larger nested data. Declare preview data only once, using either form; option and directive names are case-sensitive.

If the template already has a public demo page, supply its URL instead:

```text
@template "Editorial article" previewUrl="https://templates.example.com/editorial/demo"
```

`previewUrl` points to the public page to capture. It must be an absolute HTTP(S) URL without credentials, using port 80 or 443. Local and private network addresses are rejected, and network destinations and redirects are checked again during capture. When both options are present, the public URL is used for the screenshot; `previewData` is still validated. Normalized definitions retain the optional `previewUrl` and `previewData` fields through import and template edits.

## Settings and sections

Declare a setting with `@param name Type`, followed by an optional default and options:

```text
@section appearance "Appearance"
@param primary Color = "#0f766e" label="Primary color"
@param fontSize Range = 18 label="Font size" min=14 max=24 step=1
@param category Select = "news" label="Category" options="news:News|story:Story"
@param logo Image label="Logo" help="Enter an image URL or upload a logo."
@endsection
```

Sections organize the form without changing the path of a value: `primary` above is referenced as `{{primary}}`. Use custom types to group related values into a block.

| Type | Generated control |
| --- | --- |
| `String` | Single-line input. |
| `Text` | Textarea; values remain plain text. |
| `Wysiwyg` | Visual editor with headings, formatting, lists, quotes, code, links and images. Stores sanitized HTML. |
| `Markdown` | Markdown source editor with formatting shortcuts, images and server-rendered preview. Stores Markdown source. |
| `Color` | Color picker. |
| `Number` | Numeric input. |
| `Range` | Slider. |
| `Boolean` | Checkbox. |
| `Select` | Select control using `options="value:Label|other:Other label"`. |
| `Image` | Image URL or bundled asset path, plus file upload. |
| `Url`, `Email` | Typed URL and email inputs. |
| Custom type, such as `Header` | Group of nested fields. |
| Custom type array, such as `Comment[]` | Repeatable group with add/remove controls. |

Common options are `label`, `help`, `aiInstructions`, and `required=true`. `Number` and `Range` accept `min`, `max`, and `step`. Arrays accept `min_items` and `max_items`; the minimum count supplies initial rows using their field defaults. Defaults and submitted values are validated by type before compilation.

For a custom-type group, `label` controls the fieldset: `@param article Article` displays its nested fields without a border or group heading; `@param article Article label="Article details"` adds both. An empty or whitespace-only group label also omits the fieldset. Individual controls and repeatable lists use their parameter name when `label` is omitted.

### AI instructions

Use `aiInstructions="…"` to attach AI guidance to any `@param` (including fields inside `@type`, custom-type groups, and repeatable groups) or to a reusable `@block` after its argument list:

```text
@type Article
@param heading String aiInstructions="Write a short, concrete heading."
@param body Text aiInstructions="Use plain language.\nKeep paragraphs short."
@endtype
@param article Article aiInstructions="Keep the heading and body consistent."
@param related Article[] aiInstructions="Cover a different topic in each entry."

@block articleBody(value: Article) aiInstructions="Introduce the topic (briefly), then explain the details."
<article><h1>{{value.heading}}</h1><p>{{value.body}}</p></article>
@endblock
```

The annotation is an optional UTF-8 string of at most 10,000 bytes; an empty string is allowed. Single and double quotes and the usual quoted-string escapes (`\n`, `\"`, `\\`) work as in other options. Keep the declaration on one physical line. Duplicate options and missing or unclosed values are rejected. The option name is case-sensitive.

Normalized field objects retain `aiInstructions`; annotated reusable blocks retain it under `blocks.<blockName>.aiInstructions`. Both survive includes, import, template edits, and schema validation. Instructions stay attached to their own declaration; group and block instructions are not copied into child fields. The content generator sends these annotations to the selected AI connection with the schema and TPL rules. The annotation alone does not invoke AI, appear as form help, or change defaults and rendered HTML. Expressions such as `{{title}}` inside the instructions remain literal text. Unannotated templates need no changes.

### Image crop and output sizes

An `Image` value can be an HTTP(S) URL, a relative path to a bundled asset, or an uploaded image. An upload takes precedence over the text value and is copied into the landing release. Use the value in a quoted `src` attribute. Optional images can be hidden with `@if`.

Image fields provide a drop zone, preview and crop editor with zoom, positioning, fit/fill and transparent backgrounds. Choose one optional constraint:

```html
@param cover Image label="Cover" aspect_ratio="16:9"
@param card Image label="Card" sizes="1200x630|1080x1080"
@param avatar Image label="Avatar" sizes="256x256|512x512"
```

In the text DSL, `aspect_ratio` uses `width:height`, such as `"16:9"`. The normalized JSON schema also accepts a numeric ratio such as `1.5`; a decimal option in a DSL declaration remains a string and is rejected. The initial crop keeps the largest area within the source, without upscaling, and the output width can be changed while retaining the ratio. `sizes` offers exact output dimensions; the editor initially selects the closest aspect ratio. Use either option, never both. Sizes allow 1–10 distinct entries, with each dimension between 1 and 4096 pixels. Ratios must be between 0.01 and 100.

The normalized JSON schema uses `"aspect_ratio": "16:9"` or `"sizes": [{"width": 1200, "height": 630, "label": "Landscape"}, {"width": 1080, "height": 1080, "label": "Square"}]`. Labels are optional. These options also work inside custom types and repeaters.

Without either option, the crop uses the original proportions and allows resizing. **Use original** uploads the file unchanged, preserving animation. Applying a crop exports a static PNG, preserves transparency by default, and limits each output dimension to 4096 pixels. Cancel keeps the current field unchanged. Uploads are limited to 10 MB by default (`fast-landings.templates.max_image_kb` in application configuration). The server checks new uploads against template dimensions or aspect ratio; existing assets and URL values are retained as supplied and are not fetched or resized by the server. Cropping an external URL requires that image server to allow cross-origin access.

## Rich text and Markdown

Try the [rich text article example](../public/examples/rich-text-template.tpl) to see both editors, image insertion and repeatable Markdown comments in the generated form.

Both editors work at the top level and inside custom types and repeaters. For example:

```html
@param article Wysiwyg = "<p>Write your <strong>article</strong>.</p>" label="Article" required
@param details Markdown = "## Details\n\nA **Markdown** description." label="Details"

@layout
<article>{{& article}}</article>
<section>{{& details}}</section>
@endlayout
```

Use `{{& path}}` for formatted editor content. It accepts only `Wysiwyg` and `Markdown` fields (including aliases mapped to their `wysiwyg` and `markdown` controls). It also works in typed blocks, loops and normalized JSON partials. Use it inside HTML body containers such as `div`, `section` or `article`, never in attributes, scripts, styles or a surrounding `p`. Ordinary `{{path}}` continues to escape the stored string. Triple braces and arbitrary raw HTML expressions remain unsupported.

Markdown uses CommonMark. Its **Preview** button uses the same server renderer as publication. Raw HTML in Markdown is stripped. Both formats pass through an HTML allowlist: paragraphs, headings, emphasis, lists, quotes, code, links, images and horizontal rules. Scripts, event handlers, styles, iframes, SVG markup and unsafe URLs are removed. Each field accepts at most 100,000 UTF-8 bytes; required fields reject empty markup, while an image counts as content. The published template supplies its own typography and responsive image styles, for example `article img { max-width: 100%; height: auto; }`.

Choose **Image** in either toolbar to insert an HTTP(S) URL or upload a JPEG, PNG, GIF, WebP or AVIF file (up to 10 MB). Markdown can also contain `![Description](https://example.com/photo.jpg)` or reference a bundled image with `![Description](assets/photo.jpg)`. Uploads use Livewire's native temporary upload flow and Laravel Filesystem; no base64 data is stored in the field.

### Image storage disks

Set `FAST_LANDINGS_MEDIA_DISK` to any disk configured in `config/filesystems.php`. It defaults to `FILESYSTEM_DISK` (`local`). For example:

```dotenv
FAST_LANDINGS_MEDIA_DISK=s3
AWS_ACCESS_KEY_ID=...
AWS_SECRET_ACCESS_KEY=...
AWS_DEFAULT_REGION=eu-central-1
AWS_BUCKET=landing-images
# Optional for S3-compatible providers:
AWS_ENDPOINT=https://your-storage-endpoint.example
AWS_USE_PATH_STYLE_ENDPOINT=true
```

The S3 Flysystem adapter is included. Local, public, S3 and custom Laravel disks are accessed through `Storage::disk()`, `putFileAs()` and `readStream()`. Private disks work: editor image previews are streamed through an authenticated panel route, so no public bucket or `storage:link` is required. The `template_media` table records each original's disk and path, so changing the configured disk affects new uploads only. Run `php artisan migrate` when upgrading.

On publication, referenced uploads are copied from their disk into the static release under `_media/`; both HTML and saved Markdown retain these portable relative paths. Subsequent edits retain referenced images from the previous release, and rollback uses each release's existing files. Template packages cannot provide files under `_media/` or `_uploads/`. Generated releases continue to use the application's existing local landing storage; `FAST_LANDINGS_MEDIA_DISK` configures editor image originals. Originals are retained even if an editing form is abandoned, allowing future media housekeeping without deleting release assets.

## Types, blocks, and lists

Define a reusable configuration type with `@type` and nested `@param` declarations. Then reference it in a top-level setting or another custom type:

```text
@type Comment
@param author String = "Guest" label="Author" required=true
@param body Text = "Your comment" label="Comment" required=true
@param avatar Image label="Avatar"
@endtype

@section discussion "Comments"
@param comments Comment[] label="Comments" min_items=1 max_items=20
@endsection
```

A block has a name and typed arguments. `@render` calls it with settings or the current loop item. `@each` renders every item in a list, and `@if` renders a truthy value:

```html
@block commentItem(comment: Comment)
<article>
  @if comment.avatar
  <img src="{{comment.avatar}}" alt="{{comment.author}}">
  @endif
  <h3>{{comment.author}}</h3>
  <p style="white-space:pre-line">{{comment.body}}</p>
</article>
@endblock

@block commentsList(comments: Comment[])
<section>
  <h2>Reader comments</h2>
  @each comment in comments:
    @render commentItem(comment)
  @endeach
</section>
@endblock

@layout
<!doctype html>
<html><head><meta charset="utf-8"><title>Reader stories</title></head><body>
  @render commentsList(comments)
</body></html>
@endlayout
```

Close each block, loop, and conditional explicitly using `@endblock`, `@endeach`, and `@endif`. Version 1 supports `@if` without `@else`. Use dotted paths for nested settings, such as `{{header.logo}}` and `{{comment.author}}`. Calls and loops can be nested to compose a comments list containing separately configured comment blocks.

## Splitting a ZIP into files

Put reusable declarations and blocks in source fragments and include them from the entrypoint:

```text
@include "blocks/comment.tpl"
```

Put includes containing declarations at the top level. Includes are expanded as source text before parsing, so markup fragments can also be included inside a layout or block, and field declarations inside a type. Their contents must be valid in that context. Paths are relative to the package root, including nested includes; repeated includes are not deduplicated. The main file still declares the page layout. Reference normal assets in HTML using relative paths, for example `<link rel="stylesheet" href="assets/style.css">`.

## Rendering and extension boundaries

Ordinary `{{path}}` interpolation HTML-escapes values, including values inserted into quoted attributes. `{{& path}}` renders sanitized rich text as described above. Textarea line breaks can be displayed with `white-space: pre-line`. Use typed numeric and color fields when inserting settings into CSS. Keep JavaScript in the template or asset files rather than interpolating text fields into script source.

The template language does not execute PHP, Blade, arbitrary expressions, or template-authored functions during import, compilation or screenshots. PHP is preserved as source in generated `.php` files for the landing runtime. HTML, JavaScript and PHP are trusted administrator-authored content served on landing hostnames; the configured landing PHP runtime is separate from the control panel.

Version 1 keeps text parsing, normalized field validation, and rendering separate. The text parser converts declarations into the form schema and block representation; the engine validates values and renders the page; the landing service packages the result using the existing release pipeline. Future syntax and field types can be introduced through these boundaries while keeping uploaded template versions explicit.

Application developers can register authoring types through the `TemplateFieldTypes` singleton, for example in a service provider's `boot` method:

```php
app(\TrafficOps\TemplateDsl\TemplateFieldTypes::class)->register(
    'Headline',
    fn (array $options): array => [
        'type' => 'text',
        'default' => 'New article',
        ...$options,
    ],
);
```

Templates can then declare `@param title Headline`. Factories map aliases to existing form controls and presets; they run in application code and are never supplied by an uploaded template. Adding a new underlying control also requires validation in `TemplateEngine` and a matching dynamic form control. Template authors can compose existing types into their own records using `@type`, without changing application code.

The shared `trafficops/template-dsl` package owns `TemplateSourceParser`, `TemplateMarkupCompiler`, `TemplateEngine`, field types, and rich-text handling. Fast Landings explicitly selects its application-owned `FastLandingsTemplateDialect`, which preserves trusted PHP, request macros, and `@validation`; the package default remains the non-executable `safe-html-v1` dialect used by other applications. A template cannot select or escalate its dialect. `TemplateLandingService` owns release creation and the isolated runtime remains an application boundary.
