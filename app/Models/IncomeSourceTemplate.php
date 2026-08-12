<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class IncomeSourceTemplate extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['compatibility_tags' => 'array', 'is_fallback' => 'boolean', 'is_active' => 'boolean'];
    }

    public function incomeSources(): HasMany
    {
        return $this->hasMany(IncomeSource::class);
    }
}
