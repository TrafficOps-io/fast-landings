<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LandingTemplate extends Model
{
    use HasUlids;

    protected $fillable = [
        'name', 'description', 'definition', 'asset_paths', 'storage_path', 'original_name', 'uploaded_by',
    ];

    protected function casts(): array
    {
        return [
            'definition' => 'array',
            'asset_paths' => 'array',
            'preview_requested_at' => 'immutable_datetime',
            'preview_generated_at' => 'immutable_datetime',
        ];
    }

    public function hasPreviewSource(): bool
    {
        return isset($this->definition['previewUrl']) || array_key_exists('previewData', $this->definition ?? []);
    }

    public function supportsLocalPreview(): bool
    {
        return ($this->definition['entrypoint'] ?? 'index.html') === 'index.html'
            && ! preg_match('/<\?(?:php\b|=)/i', $this->definition['html'] ?? '');
    }

    public function previewDirectory(): string
    {
        return "_templates/previews/{$this->id}";
    }

    public function landings(): HasMany
    {
        return $this->hasMany(Landing::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
