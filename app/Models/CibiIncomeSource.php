<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CibiIncomeSource extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['monthly_amount' => 'decimal:2'];
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(CibiReport::class, 'cibi_report_id');
    }

    public function incomeSource(): BelongsTo
    {
        return $this->belongsTo(IncomeSource::class);
    }
}
