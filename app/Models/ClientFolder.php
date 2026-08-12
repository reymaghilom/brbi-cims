<?php

namespace App\Models;

use App\Enums\ClientFolderStatus;
use App\Enums\UserRole;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class ClientFolder extends Model
{
    use HasFactory, SoftDeletes;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['status' => ClientFolderStatus::class, 'progress_percent' => 'decimal:2', 'completed_at' => 'datetime'];
    }

    public function scopeAccessibleTo(Builder $query, User $user): Builder
    {
        if ($user->role === UserRole::CreditInvestigator) {
            $query->where($query->qualifyColumn('assigned_ci_id'), $user->id);
        }

        return $query;
    }

    public function assignedInvestigator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_ci_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function deletedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deleted_by');
    }

    public function information(): HasOne
    {
        return $this->hasOne(ClientInformation::class);
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(ClientAddress::class);
    }

    public function cibiReport(): HasOne
    {
        return $this->hasOne(CibiReport::class);
    }

    public function incomeSources(): HasMany
    {
        return $this->hasMany(IncomeSource::class);
    }

    public function residenceBusinessReport(): HasOne
    {
        return $this->hasOne(ResidenceBusinessReport::class);
    }

    public function activities(): HasMany
    {
        return $this->hasMany(CiActivity::class);
    }

    public function ciActivities(): HasMany
    {
        return $this->activities();
    }

    public function mediaReferences(): HasMany
    {
        return $this->hasMany(MediaReference::class);
    }

    public function generatedReports(): HasMany
    {
        return $this->hasMany(GeneratedReport::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class);
    }

    public function driveReferences(): HasMany
    {
        return $this->hasMany(GoogleDriveReference::class);
    }

    public function telegramMessages(): HasMany
    {
        return $this->hasMany(TelegramMessage::class);
    }

    public function completionResults(): HasMany
    {
        return $this->hasMany(ClientCompletionResult::class);
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class);
    }
}
