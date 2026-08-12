<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GeneralIncomeSourceReport extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['completion_metadata' => 'array'];
    }

    public function incomeSource(): BelongsTo
    {
        return $this->belongsTo(IncomeSource::class);
    }

    public function declaredItems(): HasMany
    {
        return $this->hasMany(DeclaredIncomeSourceItem::class);
    }
}
