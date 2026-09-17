<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class TemplateGeneration extends Model
{
    use HasUlids;

    protected $guarded = [];

    protected $hidden = ['options'];

    protected function casts(): array
    {
        return [
            'options' => 'array',
            'result' => 'array',
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'applied_at' => 'immutable_datetime',
        ];
    }

    public static function definitionHash(LandingTemplate $template): string
    {
        return hash('sha256', json_encode($template->definition, JSON_THROW_ON_ERROR));
    }

    public function isRunning(): bool
    {
        return in_array($this->status, ['pending', 'running'], true);
    }

    public function clearStagedImages(): void
    {
        Storage::disk('local')->deleteDirectory('ai-generations/'.$this->id);
        $options = $this->options ?? [];
        unset($options['site_images'], $options['reference_images']);
        $this->forceFill(['options' => $options])->save();
    }
}
