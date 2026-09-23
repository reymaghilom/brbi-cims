<?php

namespace App\Models;

use App\Enums\RecordState;
use App\Services\ClientFolders\Contracts\HasCiParticipants;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class IncomeSource extends Model implements HasCiParticipants
{
    use HasFactory, SoftDeletes;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['state' => RecordState::class, 'is_primary' => 'boolean', 'estimated_monthly_contribution' => 'decimal:2', 'completed_at' => 'datetime', 'business_report_deleted_at' => 'datetime', 'business_check_deleted_at' => 'datetime'];
    }

    public function clientFolder(): BelongsTo
    {
        return $this->belongsTo(ClientFolder::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function lastEditor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'last_edited_by');
    }

    public function contributors(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'income_source_contributors')->withPivot('position')->withTimestamps();
    }

    /**
     * Falls back to the owning ClientFolder's creator for legacy rows saved before this
     * IncomeSource had its own created_by column — a real, already-known actor rather than a
     * guess, so old business reports never end up with a blank primary CI.
     */
    public function ciPrimaryUserId(): ?int
    {
        return $this->created_by ?? $this->clientFolder?->created_by;
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

    public function businessCheck(): HasOne
    {
        return $this->hasOne(BusinessCheck::class);
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
        return IncomeSourceTemplate::defaultBusinessNameFor($this->template->template_type)
            ?: ($this->business_name ?: $this->source_name);
    }

    /**
     * The authoritative business name for this exact source, for every read path that needs one
     * (Business Check's dropdown and its one-way prefill, Reports, previews).
     *
     * For the six no-name-input templates the mapped default always wins, so a historical row whose
     * business_name was left blank — or was filled with the long template name before the default
     * existed — still resolves to the same value a freshly saved one stores. Every other template
     * keeps its own saved name, preferring the Business Report's copy exactly as before.
     *
     * Read-only: nothing here writes back to the IncomeSource or its Business Report.
     */
    public function resolvedBusinessName(): string
    {
        return IncomeSourceTemplate::defaultBusinessNameFor($this->template_type)
            ?: ($this->businessReport?->business_name ?: ($this->business_name ?: $this->source_name));
    }

    /**
     * The one-way name snapshot a newly linked Business Check should own.
     *
     * Other Business / Source of Income is a generic container, so its template name is never the
     * useful subject when its report has saved concrete checkbox selections. Resolve those stable
     * keys to their human-readable labels now; SaveBusinessCheck persists the result in its own
     * business_name column, keeping later Business Report edits and custom-category renames from
     * silently changing an already-saved Check. Every specific template retains its existing name.
     */
    public function businessCheckSnapshotName(): string
    {
        if ($this->template_type !== 'other_business_source_of_income') {
            return $this->resolvedBusinessName();
        }

        $selectedKeys = array_values(array_filter(
            (array) data_get($this->businessReport?->template_data, 'fields.income_sources', []),
            static fn (mixed $key): bool => is_string($key) && filled($key),
        ));
        if ($selectedKeys === []) {
            return $this->otherBusinessFallbackName();
        }

        $schema = CustomBusinessCategory::resolveOutputSchema(
            $this->template?->businessReportSchema() ?? [],
            $selectedKeys,
        );
        $labelsByKey = collect((array) data_get($schema, 'income_source_groups', []))
            ->flatten(1)
            ->filter(fn (mixed $choice): bool => is_array($choice) && filled($choice['key'] ?? null) && filled($choice['label'] ?? null))
            ->mapWithKeys(fn (array $choice): array => [(string) $choice['key'] => (string) $choice['label']]);
        $selectedLabel = collect($selectedKeys)
            ->map(fn (string $key): ?string => $labelsByKey->get($key))
            ->filter()
            ->first();

        return filled($selectedLabel)
            ? $selectedLabel
            : $this->otherBusinessFallbackName();
    }

    /** Meaningful saved name before the generic template text, matching Business Check precedence. */
    private function otherBusinessFallbackName(): string
    {
        $genericKeys = ['otherbusinesssourceofincome', 'otherbusinessincomesource'];
        $meaningful = collect([
            $this->businessReport?->business_name,
            $this->business_name,
            $this->source_name,
        ])->first(function (mixed $name) use ($genericKeys): bool {
            if (! is_string($name) || blank($name)) {
                return false;
            }

            $normalized = mb_strtolower((string) preg_replace('/[^a-z0-9]+/i', '', $name));

            return ! in_array($normalized, $genericKeys, true);
        });

        return $meaningful ?: (IncomeSourceTemplate::defaultBusinessNameFor($this->template_type) ?? 'Other Business / Source of Income');
    }
}
