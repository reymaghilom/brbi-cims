<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeclaredIncomeSourceItem extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['amount_contribution' => 'decimal:2'];
    }

    public function generalReport(): BelongsTo
    {
        return $this->belongsTo(GeneralIncomeSourceReport::class, 'general_income_source_report_id');
    }
}
