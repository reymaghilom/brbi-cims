<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ResidenceCheckPhoto extends Model
{
    protected $guarded = [];

    public function residenceCheck(): BelongsTo
    {
        return $this->belongsTo(ResidenceCheck::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /** True once this photo was uploaded to Cloudinary (new uploads only — historical photos keep resolving through `path`/`thumbnail_path` on local storage instead). */
    public function isCloud(): bool
    {
        return filled($this->cloud_public_id);
    }
}
