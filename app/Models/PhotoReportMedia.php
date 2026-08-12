<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PhotoReportMedia extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['crop_orientation_metadata' => 'array'];
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(PhotoReportSection::class, 'photo_report_section_id');
    }

    public function mediaReference(): BelongsTo
    {
        return $this->belongsTo(MediaReference::class)->withTrashed();
    }
}
