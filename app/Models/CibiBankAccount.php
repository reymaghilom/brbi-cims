<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CibiBankAccount extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['capital_share_amount' => 'decimal:2'];
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(CibiReport::class, 'cibi_report_id');
    }
}
