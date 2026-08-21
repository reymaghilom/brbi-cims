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
}
