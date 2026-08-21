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

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
