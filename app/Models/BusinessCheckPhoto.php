<?php

namespace App\Models;

use App\Enums\BusinessCheckPhotoCategory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BusinessCheckPhoto extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['category' => BusinessCheckPhotoCategory::class];
    }

    public function businessCheck(): BelongsTo
    {
        return $this->belongsTo(BusinessCheck::class);
    }

    /** Null for a historical photo saved before Photo Groups existed — see BusinessCheckController::form()'s legacy-fallback grouping. */
    public function photoGroup(): BelongsTo
    {
        return $this->belongsTo(BusinessCheckPhotoGroup::class, 'business_check_photo_group_id');
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
