<?php

namespace App\Models;

use App\Enums\PhotoSectionCategory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PhotoReportSection extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['category' => PhotoSectionCategory::class];
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(ResidenceBusinessReport::class, 'residence_business_report_id');
    }

    public function incomeSource(): BelongsTo
    {
        return $this->belongsTo(IncomeSource::class);
    }

    public function mediaItems(): HasMany
    {
        return $this->hasMany(PhotoReportMedia::class);
    }
}
