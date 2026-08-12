<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CompletionRule extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['source_condition' => 'array', 'is_required' => 'boolean', 'is_active' => 'boolean', 'weight' => 'decimal:4'];
    }

    public function results(): HasMany
    {
        return $this->hasMany(ClientCompletionResult::class);
    }
}
