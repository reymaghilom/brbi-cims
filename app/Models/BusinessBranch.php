<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BusinessBranch extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_declared' => 'boolean', 'is_inspected' => 'boolean', 'is_air_conditioned' => 'boolean'];
    }

    public function businessReport(): BelongsTo
    {
        return $this->belongsTo(BusinessReport::class);
    }
}
