<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClientCompletionResult extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_satisfied' => 'boolean', 'score' => 'decimal:4', 'evaluated_at' => 'datetime'];
    }

    public function clientFolder(): BelongsTo
    {
        return $this->belongsTo(ClientFolder::class);
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(CompletionRule::class, 'completion_rule_id');
    }
}
