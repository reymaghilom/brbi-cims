<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BusinessTenant extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['monthly_rent' => 'decimal:2', 'years_renting' => 'decimal:2', 'has_contract' => 'boolean'];
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(BusinessProperty::class, 'business_property_id');
    }
}
