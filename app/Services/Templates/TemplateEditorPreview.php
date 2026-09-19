<?php

namespace App\Services\Templates;

use App\Models\LandingRelease;
use App\Models\LandingTemplate;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Throwable;
use TrafficOps\TemplateDsl\TemplateEngine;

/** Builds an inert, authenticated editor preview without publishing a release. */
final class TemplateEditorPreview
{
    public function __construct(private TemplateEngine $engine) {}

    public function render(LandingTemplate $template, array $values, ?LandingRelease $release = null): ?string
    {
        if (! $template->supportsLocalPreview()) {
            return null;
        }

        try {
            $values = $this->engine->validateValues($template->definition, $values);
            $pages = $this->engine->renderPages($template->definition, $values);
            $entrypoint = $template->definition['entrypoint'] ?? 'index.html';
            $html = $pages[$entrypoint] ?? null;
            if (! is_string($html)) {
                return null;
            }

            $assetBase = str_replace('__PATH__', '', route('templates.asset', [
                'template' => $template,
                'path' => '__PATH__',
            ]));
            $base = '<base href="'.e($assetBase).'">';
            $html = preg_replace('/<head(?=\s|>)([^>]*)>/i', '<head$1>'.$base, $html, 1, $count);
            if (($count ?? 0) === 0) {
                $html = $base.$html;
            }

            foreach (Arr::flatten($values) as $value) {
                if (! is_string($value) || $value === '') {
                    continue;
                }
                $url = null;
                if (str_starts_with($value, '_media/')) {
                    $url = route('templates.media', ['filename' => substr($value, 7), 'release' => $release?->id]);
                } elseif ($release && str_starts_with($value, '_uploads/')) {
                    $url = route('landings.release-image', ['release' => $release, 'path' => $value]);
                }
                if ($url !== null) {
                    $html = str_replace($value, $url, $html);
                }
            }

            return $html;
        } catch (ValidationException) {
            return null;
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }
}
