<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Spatie\Tags\HasTags;

class Landing extends Model
{
    use HasTags;
    use HasUlids;

    protected $fillable = ['name', 'slug', 'description', 'is_active', 'landing_template_id', 'template_values'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'template_values' => 'array'];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(LandingTemplate::class, 'landing_template_id');
    }

    public function releases(): HasMany
    {
        return $this->hasMany(LandingRelease::class);
    }

    public function activeRelease(): HasOne
    {
        // The database guarantees at most one active release per landing.
        // An aggregate over inactive releases can hide a just-reactivated one.
        return $this->hasOne(LandingRelease::class)->where('is_active', true);
    }

    public function domains(): HasMany
    {
        return $this->hasMany(Domain::class);
    }
}
