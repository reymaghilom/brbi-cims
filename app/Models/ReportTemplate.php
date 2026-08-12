<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReportTemplate extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['margins_inches' => 'array', 'is_active' => 'boolean', 'published_at' => 'datetime', 'paper_width_inches' => 'decimal:2', 'paper_height_inches' => 'decimal:2'];
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }
}
