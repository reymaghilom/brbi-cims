<?php

namespace App\Models;

use App\Enums\PartyType;
use App\Enums\RecordState;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CibiReport extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['party_type' => PartyType::class, 'state' => RecordState::class, 'purpose_codes' => 'array', 'personal_snapshot' => 'array', 'summary_totals' => 'array', 'amount_applied' => 'decimal:2', 'start_date' => 'date', 'submitted_date' => 'date', 'completed_at' => 'datetime'];
    }

    public function clientFolder(): BelongsTo
    {
        return $this->belongsTo(ClientFolder::class);
    }

    public function investigator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ci_in_charge_id');
    }

    public function bankAccounts(): HasMany
    {
        return $this->hasMany(CibiBankAccount::class);
    }

    public function loanRecords(): HasMany
    {
        return $this->hasMany(CibiLoanRecord::class);
    }

    public function creditChecks(): HasMany
    {
        return $this->hasMany(CibiCreditCheck::class);
    }

    public function incomeSourceSummaries(): HasMany
    {
        return $this->hasMany(CibiIncomeSource::class);
    }

    public function legalFindings(): HasMany
    {
        return $this->hasMany(CibiLegalFinding::class);
    }
}
