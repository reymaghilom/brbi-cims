<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BusinessCheckPhotoGroup extends Model
{
    use HasFactory;

    protected $guarded = [];

    public function businessCheck(): BelongsTo
    {
        return $this->belongsTo(BusinessCheck::class);
    }

    public function photos(): HasMany
    {
        return $this->hasMany(BusinessCheckPhoto::class)->orderBy('sort_order')->orderBy('id');
    }
}
