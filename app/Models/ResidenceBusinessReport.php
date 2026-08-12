<?php

namespace App\Models;

use App\Enums\RecordState;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ResidenceBusinessReport extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['state' => RecordState::class, 'report_date' => 'date', 'completed_at' => 'datetime'];
    }

    public function clientFolder(): BelongsTo
    {
        return $this->belongsTo(ClientFolder::class);
    }

    public function investigator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ci_user_id');
    }

    public function sections(): HasMany
    {
        return $this->hasMany(PhotoReportSection::class);
    }
}
