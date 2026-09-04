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

    /**
     * All active (non-trashed) folders are a shared CI team workspace: any known-role user
     * may see them, so this is a no-op passthrough kept for its existing call sites.
     * Trashed/recycled folders keep the original restrictive behavior — see scopeAccessibleToTrashed().
     */
    public function scopeAccessibleTo(Builder $query, User $user): Builder
    {
        return $query;
    }

    /**
     * Restrictive listing scope used only for trashed/recycled folders (recycle bin), which
     * intentionally did not adopt the shared-workspace access model.
     */
    public function scopeAccessibleToTrashed(Builder $query, User $user): Builder
    {
        if ($user->role === UserRole::CreditInvestigator) {
            $query->where($query->qualifyColumn('assigned_ci_id'), $user->id);
        }

        return $query;
    }

    /**
     * Single chokepoint for folder-level authorization, shared by ClientFolderPolicy and
     * ClientFolderResourcePolicy. Any Credit Investigator may access any active folder
     * (shared CI team workspace); trashed folders keep the original single-assignee rule.
     */
    public function isAccessibleBy(User $user): bool
    {
        if ($user->role === UserRole::Administrator) {
            return true;
        }

        if ($user->role !== UserRole::CreditInvestigator) {
            return false;
        }

        if ($this->trashed()) {
            return $this->assigned_ci_id === $user->id;
        }

        return true;
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

    /**
     * A folder can hold more than one CI/BI report (one per co-maker, via the composite
     * client_folder_id+co_maker_id unique key) — this plural relation exists so route
     * scopeBindings() can resolve {clientFolder}/{cibiReport} by Laravel's naming convention.
     */
    public function cibiReports(): HasMany
    {
        return $this->hasMany(CibiReport::class);
    }

    public function coMakers(): HasMany
    {
        return $this->hasMany(CoMaker::class)->orderBy('id');
    }

    public function incomeSources(): HasMany
    {
        return $this->hasMany(IncomeSource::class);
    }

    public function residenceBusinessReport(): HasOne
    {
        return $this->hasOne(ResidenceBusinessReport::class);
    }

    public function residenceChecks(): HasMany
    {
        return $this->hasMany(ResidenceCheck::class);
    }

    public function businessChecks(): HasMany
    {
        return $this->hasMany(BusinessCheck::class);
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

    public function residenceBusinessDocumentations(): HasMany
    {
        return $this->hasMany(ResidenceBusinessDocumentation::class);
    }

    public function documentationTelegramDeliveries(): HasMany
    {
        return $this->hasMany(DocumentationTelegramDelivery::class);
    }

    /** Alias matching Laravel's pluralized lookup for the {documentation} route parameter — required for Route::scopeBindings() to auto-scope that nested parameter to this folder. */
    public function documentations(): HasMany
    {
        return $this->residenceBusinessDocumentations();
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
