<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class TemplateMedia extends Model
{
    use HasUlids;

    protected $table = 'template_media';

    protected $fillable = ['filename', 'disk', 'path', 'mime_type', 'uploaded_by'];
}
