# UI

Repository-local design system for Fast Landings. It contains the Tailwind CSS entry point, the prefixed DaisyUI theme, optional Flux overrides, namespaced anonymous Blade components, and the shared design tokens used by the application.

[Гайд по брендингу и визуальной айдентике](BRAND_GUIDELINES.md) — палитра, типографика, знак, продуктовые логотипы и правила применения на других носителях на основе этого пакета.

## JavaScript/CSS setup

Add the workspace dependency to an application:

```json
{
  "dependencies": {
    "@trafficops/fast-landings-ui": "*"
  }
}
```

Import the shared styles before application-specific styles:

```css
@import '@trafficops/fast-landings-ui/styles.css';
```

Laravel applications using Flux can load its stylesheet and then the shared overrides:

```css
@import '../../vendor/livewire/flux/dist/flux.css';
@import '@trafficops/fast-landings-ui/flux.css';
```

Keep each application's `@source` directives in its own CSS entry point so Tailwind scans application templates. The UI entry point already scans the package's Blade components.

Add `ui-grid` to an authentication page body or a full-width hero section for the shared 32px background grid. Its lines use the theme's primary color at 8% opacity and fade out toward the bottom with a radial mask. It reserves `::before` for a decorative layer behind the content, preserving the element's background and leaving controls clickable.

## Laravel setup

The root Composer manifest installs this repository-local package as `trafficops/fast-landings-ui`. Laravel package discovery registers its components under the `ui` namespace:

```blade
<x-ui::auth-header :title="$title" :description="$description" />
<x-ui::feedback />
<x-ui::text-link href="/login">Log in</x-ui::text-link>
```

### Application shell

| Component | Props | Slots |
| --- | --- | --- |
| `topbar` | Required: `home`, `title`. Optional: `brand` (`trafficops`, `pwapps`, `hookroute`), `subtitle`, `navLabel`, `navigate` (default `true`). | Optional: `brandExtra`, `utilities`, `nav`, `profile`, in that order. |
| `nav-link` | Required: `href`. Optional: `active` (default `false`), `navigate` (default `true`). | Link label. |
| `section-link` | Same as `nav-link`, for navigation within a record. | Link label. |
| `record-shell` | Required: `back`, `backLabel`, `title`. Optional: `nav`, `navLabel`, `orientation` (`horizontal` or `vertical`), `width` (default `max-w-6xl`). | Optional: `badge`, `meta`, `intro`; default slot for content. |

Pass `nav` as an array of `['url' => string, 'label' => string, 'active' => bool]` items. The application calculates URLs and active states. Use `navLabel` to name each navigation landmark. Active links receive `aria-current="page"`; pass `:navigate="false"` to links or the topbar to disable Livewire navigation.

Vertical record navigation sits beside the content on desktop and scrolls horizontally below `md`. Navigation items can have an optional `group` label to place related links under a non-clickable heading. With no navigation items, the content uses the full width. Back labels omit the arrow: `record-shell` and `page-header` render it themselves.

### Public header and theme

TrafficOps, HookRoute and PWApps share `resources/css/public-header.css`: a 72px row,
36px brand marks, 24px wordmarks, language and theme controls, secondary links and
one primary action. Navigation moves into a keyboard-accessible menu below 1280px.

Laravel uses `x-ui::public-header` with `home`, `brand`, `title`, `navLabel`, `menuLabel`,
`ctaHref`, `ctaLabel`, and a `language` slot. Its `nav` and `links` arrays contain
`href`, `label`, and optional `active` entries. Astro uses the same `ui-public-*`
classes. Import `@trafficops/fast-landings-ui/public-header` for menu dismissal behavior.

Render `x-ui::public-theme-script` in `<head>` with the application's `storageKey`,
or inline `@trafficops/fast-landings-ui/public-theme?raw` in Astro with `data-ui-theme-key`.
The bootstrap applies the saved light/dark preference before paint, follows the
system until a theme is chosen, and handles `[data-ui-theme-toggle]` buttons.
HookRoute retains `flux.appearance`; PWApps retains `pwapps-theme` so public pages
and their authenticated workspaces use the same preference. TrafficOps uses
`trafficops-theme`.

### Screen primitives

| Component | Props | Slots |
| --- | --- | --- |
| `page` | `width` (optional). | Content. |
| `page-header` | Required: `title`. Optional: `eyebrow`, `back`, `backLabel`. | Optional: `actions`. |
| `panel` | Optional: `title`, `description`. The header appears only when titled. | Content and optional header `actions`. |
| `list` | None. | List rows. |
| `list-row` | Optional: `href`, which makes the title a link with Livewire navigation. | Required: `title`. Optional: `meta`, `actions`. |
| `empty-state` | Required: `title`. Optional: `description`. | Optional: `action`. |
| `status-badge` | Required: `label`. `tone`: `success`, `warning`, `error`, or `neutral` (default and fallback). | None. |
| `data-table` | `density`: `normal` (default) or `compact`. | Required: `head`, `body`, containing table rows. |
| `json` | Required: `value`, a PHP value to JSON-encode. | None. |

`json` uses `TrafficOps\FastLandings\Ui\Support\JsonSyntaxHighlighter` to highlight and escape payloads. Pass arrays, objects, or scalar values rather than pre-encoded JSON. It returns an `HtmlString`, rendered through Blade's normal `{{ }}` output.

The original six primitives remain available: `action-message`, `auth-header`, `auth-session-status`, `feedback`, `placeholder-pattern`, and `text-link`.

### Package boundaries

Components never call `route()`; URLs and active states come from the application. Write class names as literals so Tailwind can discover them, including custom `width` values in application templates.

`.ui-container` owns width and horizontal padding: max-width 1280px with 16/24/32px padding at 0/640/1024px breakpoints, padding applied inside the max-width so the topbar and `<main>` share one box model. The application places the class on `<main>`; the topbar consumes it internally, so both line up without either side hardcoding the other's numbers. `page` owns vertical rhythm (`space-y-6`), not width. `record-shell` owns the record's own width (`max-w-6xl` by default) as a separate concern from the shared marketing/panel container. Applications still own the document (`html`, `head`, fonts, Vite, and scripts). Profile menus and utility controls belong to the application and are passed as slots.

`.ui-container` deliberately owns only horizontal rhythm, not vertical. Vertical spacing differs by context — panel `<main>` uses ordinary spacing utilities, marketing sections use `--ui-section-rhythm` — so a single class enforcing vertical spacing would fight one of those contexts. Keep vertical padding out of `.ui-container` rather than folding it in later.

The shared stylesheet includes `app-chrome.css`, with `ui-*` classes (including `.ui-container`) and `--ui-*` variables (including `--ui-section-rhythm`) for the shell, panels, links, and light/dark JSON highlighting. `.ui-inline-link` is a standalone chrome class; the existing `text-link` Blade component remains a separate authentication primitive.

## Development

Run these commands from the Fast Landings repository root:

```sh
composer install --working-dir=packages/ui --no-interaction
composer check --working-dir=packages/ui
npm run build
```

The package uses PHPUnit 11 and Orchestra Testbench 10. It is copied into this repository under the same MIT license as Fast Landings and is not published separately.

## Brand assets

### Transactional email

HookRoute and PWApps share the `trafficops` Markdown mail theme in `resources/views/mail`.
Each app opts in through `config/mail.php`: set `mail.brand` to `hookroute` or `pwapps`,
set `mail.markdown.theme` to `trafficops`, and add `TrafficOps\FastLandings\Ui\Support\MailTheme::path()`
to `mail.markdown.paths` after any app-specific overrides.

The app's `vendor/notifications/email.blade.php` includes `ui-mail::notification`, so
Laravel and Filament authentication notifications use the same header, button and footer.
The beta welcome mailable uses `markdown:` and includes `ui-mail::welcome-to-beta`;
its existing explicit plain-text view retains the literal credentials and links.
For additional Markdown mailables, pass `:message="$message ?? null"` to `<x-mail::message>`
to embed the product mark as a CID attachment. Direct browser previews without a mail
message use a data URL. Standard Markdown tables, panels, subcopy and text fallbacks remain available.

The email CSS uses literal equivalents of `theme.css` and `brand.css`, because mail
clients cannot rely on Tailwind, CSS variables or application stylesheets. The Markdown
renderer inlines it. Colors follow the light theme; dark text on the coral button follows
the contrast guidance in `BRAND_GUIDELINES.md`. Keep these values aligned when changing
the UI palette. Layout uses presentation tables and adapts down to narrow mobile widths.

Onest font faces reuse the Latin and Cyrillic assets from each app's `public/build/manifest.json`
and absolute `APP_URL` / `ASSET_URL` URLs. No Vite development-server URL is included in mail.
Run the app's normal frontend build to make those font files available. Missing build assets
or clients without web-font support fall back to system fonts / Arial; Outlook uses Arial.

The small, opaque PNG marks in `resources/brand/mail` are generated at 3× display resolution
from the shared SVGs and travel inside the email, without a public asset-publishing step.
After editing the SVGs, regenerate them with `python3 packages/ui/scripts/build-mail-logos.py`
from the repository root (`rsvg-convert` required only for regeneration).

### Web assets

The SVG marks in `resources/brand/` form the TrafficOps product family. Fast Landings uses the `fastlandings` mark; the remaining assets are retained as upstream design-system references.

- `<x-ui::brand-mark brand="pwapps" />` renders the shared SVG as a decorative icon.
- `<x-ui::brand-wordmark brand="pwapps" />` renders the two-colour product name.
- `<x-ui::topbar ... brand="hookroute" title="HookRoute" />` keeps an accessible name when the wordmark is hidden on mobile.
- `<x-ui::topbar ... brand="fastlandings" title="Fast Landings" />` renders the Fast Landings mark and wordmark.
- Astro imports the same SVG source with `?raw`.

`resources/css/brand.css` supplies dimensions and light/dark colours. Neutral paths inherit `currentColor`; coral paths use `--ui-brand-accent` and `--ui-brand-highlight`. SVGs have no IDs, so repeated header/footer marks cannot collide. Favicon SVGs use an opaque rounded background and follow the browser colour scheme; ICOs are light-theme fallbacks.

## Error pages

`resources/css/error-page.css` defines the shared `ui-error-*` layout, grid, typography, brand placement and actions for TrafficOps, HookRoute and PWApps. Astro imports it as `@trafficops/fast-landings-ui/error-page.css?raw`; Blade uses `TrafficOps\FastLandings\Ui\Support\ErrorPage::styles()`. Embed these critical styles in the HTML so errors remain readable when the asset server or connection is unavailable. The layout uses the existing UI theme tokens with standalone light-theme defaults and the shared SVG brand marks.
