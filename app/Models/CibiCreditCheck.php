<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CibiCreditCheck extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_declared' => 'boolean', 'checked_date' => 'date'];
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(CibiReport::class, 'cibi_report_id');
    }
}
