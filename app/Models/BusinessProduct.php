<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BusinessProduct extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['selling_price' => 'decimal:2', 'is_top_seller' => 'boolean'];
    }

    public function businessReport(): BelongsTo
    {
        return $this->belongsTo(BusinessReport::class);
    }
}
