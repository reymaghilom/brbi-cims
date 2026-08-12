<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BusinessObservation extends Model
{
    protected $guarded = [];

    public function businessReport(): BelongsTo
    {
        return $this->belongsTo(BusinessReport::class);
    }
}
