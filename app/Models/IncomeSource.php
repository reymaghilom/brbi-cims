<?php

namespace App\Models;

use App\Enums\RecordState;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class IncomeSource extends Model
{
    use HasFactory, SoftDeletes;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['state' => RecordState::class, 'is_primary' => 'boolean', 'estimated_monthly_contribution' => 'decimal:2', 'completed_at' => 'datetime'];
    }

    public function clientFolder(): BelongsTo
    {
        return $this->belongsTo(ClientFolder::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(IncomeSourceTemplate::class, 'income_source_template_id');
    }

    public function generalReport(): HasOne
    {
        return $this->hasOne(GeneralIncomeSourceReport::class);
    }

    public function businessReport(): HasOne
    {
        return $this->hasOne(BusinessReport::class);
    }

    public function mediaReferences(): HasMany
    {
        return $this->hasMany(MediaReference::class);
    }

    public function generatedReports(): HasMany
    {
        return $this->hasMany(GeneratedReport::class);
    }

    public function cibiSummaries(): HasMany
    {
        return $this->hasMany(CibiIncomeSource::class);
    }

    public function photoReportSections(): HasMany
    {
        return $this->hasMany(PhotoReportSection::class);
    }

    /**
     * The label shown for this business/income source across the Saved Businesses list —
     * shared by the display view and its column sorting so the two can never disagree.
     * Requires the `template` relation to be loaded.
     */
    public function displayName(): string
    {
        return match ($this->template->template_type) {
            'leasing_agricultural' => 'LEASING OPERATIONS: AGRICULTURAL REAL ESTATE',
            'leasing_poultry_farm' => 'LEASING OF POULTRY FARM OPERATIONS',
            'farming_corn' => 'FARMING: CORN PRODUCTION',
            'farming_sugarcane' => 'FARMING: SUGARCANE PRODUCTION',
            'remittance_income' => 'REMITTANCE',
            default => $this->business_name ?: $this->source_name,
        };
    }
}
