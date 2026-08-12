<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CibiLoanRecord extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['original_amount' => 'decimal:2', 'remaining_balance' => 'decimal:2', 'amortization_amount' => 'decimal:2', 'granted_date' => 'date', 'maturity_date' => 'date'];
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(CibiReport::class, 'cibi_report_id');
    }
}
