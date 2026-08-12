<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BusinessSupplier extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_confirmed' => 'boolean', 'years_transacting' => 'decimal:2'];
    }

    public function businessReport(): BelongsTo
    {
        return $this->belongsTo(BusinessReport::class);
    }
}
