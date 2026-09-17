<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LandingRelease extends Model
{
    use HasUlids;

    protected $fillable = [
        'landing_id', 'uploaded_by', 'original_name', 'storage_path', 'entrypoint',
        'size_bytes', 'file_count', 'checksum', 'is_active', 'activated_at',
        'landing_template_id', 'template_values',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'activated_at' => 'immutable_datetime',
            'template_values' => 'array',
            'preview_requested_at' => 'immutable_datetime',
            'preview_generated_at' => 'immutable_datetime',
        ];
    }

    public function previewDirectory(): string
    {
        return "{$this->landing_id}/previews/{$this->id}";
    }

    public function landing(): BelongsTo
    {
        return $this->belongsTo(Landing::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(LandingTemplate::class, 'landing_template_id');
    }
}
