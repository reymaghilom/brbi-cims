<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BusinessReport extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['start_date' => 'date', 'submitted_date' => 'date', 'template_data' => 'array'];
    }

    public function incomeSource(): BelongsTo
    {
        return $this->belongsTo(IncomeSource::class);
    }

    public function properties(): HasMany
    {
        return $this->hasMany(BusinessProperty::class);
    }

    public function branches(): HasMany
    {
        return $this->hasMany(BusinessBranch::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(BusinessProduct::class);
    }

    public function suppliers(): HasMany
    {
        return $this->hasMany(BusinessSupplier::class);
    }

    public function observations(): HasMany
    {
        return $this->hasMany(BusinessObservation::class);
    }

    public function competitors(): HasMany
    {
        return $this->hasMany(BusinessCompetitor::class);
    }
}
