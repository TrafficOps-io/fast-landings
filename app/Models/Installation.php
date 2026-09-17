<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use TrafficOps\Cloudflare\Traits\HasCloudflareIntegrations;

class Installation extends Model
{
    use HasCloudflareIntegrations, HasUlids;

    protected $guarded = [];

    public function aiIntegrations(): HasMany
    {
        return $this->hasMany(AiIntegration::class);
    }

    /**
     * Return the installation configured by the installer.
     *
     * Using sole() makes an accidental second installation row fail loudly instead
     * of silently selecting credentials belonging to the wrong installation.
     */
    public static function singleton(): self
    {
        return static::query()->sole();
    }
}
