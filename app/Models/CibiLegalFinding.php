<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CibiLegalFinding extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['checked_at' => 'date'];
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(CibiReport::class, 'cibi_report_id');
    }
}
