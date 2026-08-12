<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BusinessProperty extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_declared' => 'boolean', 'is_inspected' => 'boolean', 'has_contract' => 'boolean', 'area_square_meters' => 'decimal:2'];
    }

    public function businessReport(): BelongsTo
    {
        return $this->belongsTo(BusinessReport::class);
    }

    public function tenants(): HasMany
    {
        return $this->hasMany(BusinessTenant::class);
    }
}
