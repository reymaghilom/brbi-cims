<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BusinessBranch extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_declared' => 'boolean', 'is_inspected' => 'boolean', 'is_air_conditioned' => 'boolean', 'average_sales_per_shift' => 'decimal:2', 'monthly_rent' => 'decimal:2'];
    }

    public function businessReport(): BelongsTo
    {
        return $this->belongsTo(BusinessReport::class);
    }
}
