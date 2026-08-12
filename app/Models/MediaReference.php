<?php

namespace App\Models;

use App\Enums\MediaCategory;
use App\Enums\MediaType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class MediaReference extends Model
{
    use HasFactory, SoftDeletes;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['media_type' => MediaType::class, 'category' => MediaCategory::class, 'captured_at' => 'datetime', 'temporary_expires_at' => 'datetime'];
    }

    public function clientFolder(): BelongsTo
    {
        return $this->belongsTo(ClientFolder::class);
    }

    public function incomeSource(): BelongsTo
    {
        return $this->belongsTo(IncomeSource::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function activities(): BelongsToMany
    {
        return $this->belongsToMany(CiActivity::class, 'activity_media')->withPivot('label')->withTimestamps();
    }

    public function photoReportItems(): HasMany
    {
        return $this->hasMany(PhotoReportMedia::class);
    }
}
