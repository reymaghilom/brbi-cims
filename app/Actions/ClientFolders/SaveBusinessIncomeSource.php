<?php

namespace App\Actions\ClientFolders;

use App\Enums\RecordState;
use App\Exceptions\NoChangesDetectedException;
use App\Models\AuditLog;
use App\Models\BusinessReport;
use App\Models\ClientFolder;
use App\Models\IncomeSource;
use App\Models\IncomeSourceTemplate;
use App\Models\User;
use App\Services\ClientFolders\CiParticipantService;
use App\Services\ClientFolders\IncomeSourcesCompletionEvaluator;
use App\Services\Progress\ClientProgressService;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SaveBusinessIncomeSource
{
    private const SOURCE_FIELDS = ['source_name', 'business_name', 'contribution_rank', 'estimated_monthly_contribution', 'is_primary', 'branch_name', 'account_officer_name'];

    private const REPORT_FIELDS = ['business_name', 'report_category', 'start_date', 'submitted_date', 'main_business_address', 'previous_business_address', 'previous_business_address_length_of_stay', 'reason_for_transfer', 'registered_owner', 'relationship_to_borrower', 'year_established', 'length_of_stay_months', 'monthly_rent', 'ownership_type', 'rented_from', 'business_type', 'scale', 'informant', 'report_remarks', 'template_data', 'branches_declared', 'branches_inspected', 'branches_not_inspected', 'branches_reason_not_inspected'];

    /**
     * Field-name-only allowlist for the "Updated: ..." changed-fields note on the Business Recent
     * Activity feed (see ClientFolderOverview::CHANGED_FIELD_LABELS for the display labels).
     * template_data is a JSON blob of the whole template's custom section, not a discrete field, so
     * it's deliberately excluded from this note even though it's a legitimate REPORT_FIELDS entry.
     */
    private const CHANGED_FIELDS_ALLOWLIST = ['business_name', 'report_category', 'start_date', 'submitted_date', 'main_business_address', 'previous_business_address', 'previous_business_address_length_of_stay', 'reason_for_transfer', 'registered_owner', 'relationship_to_borrower', 'year_established', 'length_of_stay_months', 'monthly_rent', 'ownership_type', 'rented_from', 'business_type', 'scale', 'informant', 'report_remarks'];

    private const SECTIONS = [
        'branches' => ['branches', ['location', 'is_declared', 'is_inspected', 'reason_not_inspected', 'frontage_meters', 'total_area_square_meters', 'is_air_conditioned', 'operating_days_hours', 'shifts_count', 'employees_per_shift', 'average_sales_per_shift', 'inventory_level', 'monthly_rent', 'years_in_area', 'nearby_brands'], 'location'],
        'products' => ['products', ['product_name', 'unit_size', 'selling_price', 'stock_level', 'is_top_seller'], 'product_name'],
        'suppliers' => ['suppliers', ['supplier_name', 'office_location', 'contact_information', 'is_confirmed', 'years_transacting', 'payment_performance', 'remarks'], 'supplier_name'],
        'observations' => ['observations', ['observation_code', 'question_snapshot', 'answer', 'remarks'], 'observation_code'],
        'competitors' => ['competitors', ['name', 'location', 'notes'], 'name'],
    ];

    public function __construct(
        private readonly IncomeSourcesCompletionEvaluator $completion,
        private readonly ClientProgressService $progress,
        private readonly CiParticipantService $participants,
        private readonly UpdateIncomeSourceContributors $updateContributors,
    ) {}

    public function execute(User $actor, ClientFolder $folder, IncomeSource $source, array $data): IncomeSource
    {
        return DB::transaction(function () use ($actor, $folder, $source, $data): IncomeSource {
            $wasFirstSave = $source->wasRecentlyCreated;
            $source = IncomeSource::query()
                ->whereKey($source->id)
                ->where('client_folder_id', $folder->id)
                ->when(
                    $source->co_maker_id === null,
                    fn ($query) => $query->whereNull('co_maker_id'),
                    fn ($query) => $query->where('co_maker_id', $source->co_maker_id),
                )
                ->lockForUpdate()
                ->firstOrFail();

            if (filled($data['expected_revision'] ?? null) && (int) $data['expected_revision'] !== $source->revision) {
                throw ValidationException::withMessages([
                    'expected_revision' => 'This record has been updated by another CI. Please review the latest version before saving.',
                ]);
            }

            // Present the moment this Action starts if it's running as part of the very first
            // save (right after CreateIncomeSource, same request) — no-change detection never
            // applies to that first save, only to later edits of an already-saved record.
            $isFirstSave = $wasFirstSave;

            // The six templates that have no Business Name input carry a derived name instead (see
            // IncomeSourceTemplate::DEFAULT_BUSINESS_NAMES). Applying it here — once, on the
            // authoritative save, before either fill() below — is what makes it real data on both
            // the IncomeSource and its Business Report rather than a per-screen display fallback,
            // so Business / Income Sources, the Business Check dropdown and prefill, Reports and
            // every preview/export all read the same value. Every other template keeps whatever
            // the CI typed into its own required Business Name field.
            $mappedBusinessName = IncomeSourceTemplate::defaultBusinessNameFor($source->template_type);
            if ($mappedBusinessName !== null) {
                $data['business_name'] = $mappedBusinessName;
            }

            $source->fill(Arr::only($data, self::SOURCE_FIELDS));
            $sourceFieldsChanged = $source->isDirty(self::SOURCE_FIELDS);

            // Business Report delete is now permanent (see DeleteBusinessReport) — a hard-deleted
            // report simply no longer exists here, so this always creates a fresh one on the same
            // income_source_id rather than needing to distinguish it from a Recycle Bin state.
            $report = BusinessReport::query()
                ->where('income_source_id', $source->id)
                ->lockForUpdate()
                ->first();

            if ($report === null) {
                $report = new BusinessReport(['income_source_id' => $source->id]);
            }

            $report->fill(Arr::only($data, self::REPORT_FIELDS));
            $reportFieldsChanged = $report->isDirty(self::REPORT_FIELDS);
            // Captured before save() (and only meaningful for a genuine edit, never the first save
            // on a brand-new report) so the Recent Activity feed can say exactly which fields
            // changed without ever storing old/new values or the raw request payload.
            $changedReportFields = $isFirstSave ? [] : array_values(array_intersect(array_keys($report->getDirty()), self::CHANGED_FIELDS_ALLOWLIST));
            $report->save();
            $tags = $source->template->businessReportSchema() !== [] ? [] : ($source->template->compatibility_tags ?? []);
            $changes = [];

            foreach (self::SECTIONS as $input => [$relation, $fields, $required]) {
                if (in_array($input, $tags, true)) {
                    $changes[$input] = $this->sync($report->{$relation}(), $data[$input] ?? [], $fields, $required);
                }
            }
            if (in_array('tenants', $tags, true)) {
                $changes['tenants'] = $this->syncTenants($report, $data['tenants'] ?? []);
            }
            if (in_array('properties', $tags, true)) {
                $changes['properties'] = $this->sync($report->properties(), $data['properties'] ?? [], ['property_type', 'is_declared', 'is_inspected', 'reason_not_inspected', 'units_available', 'units_with_tenants', 'location', 'area_square_meters', 'has_contract', 'remarks'], 'property_type');
            }

            $childrenChanged = collect($changes)->contains(fn (array $c): bool => $c['created'] > 0 || $c['updated'] > 0 || $c['deleted'] > 0);

            // The companion CI picker submits contributor_ids alongside every save (see
            // UpdateBusinessIncomeSourceRequest's contributor_ids_present marker), so the primary
            // creator's own no-change semantics must not fire just because the picker was present
            // in the request — only an actual add/remove counts as a change here. This is only a
            // read-only preview: the actual sync happens further below, after $source->save() —
            // UpdateIncomeSourceContributors::execute() ends with $source->refresh(), which would
            // otherwise silently discard the source_name/business_name/etc. fill() above before
            // they're ever persisted.
            $companionIds = array_key_exists('contributor_ids', $data) ? array_map('intval', (array) $data['contributor_ids']) : null;
            $participantsChanged = $companionIds !== null && $this->participants->wouldChangeCompanions($source, $companionIds);
            $intendedState = $data['intent'] === 'complete' ? RecordState::Complete : RecordState::Draft;
            // Finalizing an already-saved draft is itself a real change even when every form field
            // is unchanged. Treating it as a no-op used to skip the Draft -> Complete transition,
            // while the controller still returned the informational redirect consumed by the
            // modal's saved notification. The modal consequently closed and refreshed Reports with
            // the source still Draft, leaving Pending + Continue Report after a confirmed submit.
            $stateChanged = $source->state !== $intendedState;

            if (! $isFirstSave && ! $sourceFieldsChanged && ! $reportFieldsChanged && ! $childrenChanged && ! $participantsChanged && ! $stateChanged) {
                throw new NoChangesDetectedException('Nothing changed. No updates were saved to the database.');
            }

            $source->state = $intendedState;
            $source->last_edited_by = $actor->id;
            // Explicitly recreating/saving a Business Report for this business lifts any earlier
            // intentional deletion, so the work item legitimately returns to the Reports workspace.
            // Only a real save clears it — merely viewing Reports never does.
            $source->business_report_deleted_at = null;
            $source->revision++;
            $source->save();

            if ($companionIds !== null) {
                $this->updateContributors->execute($actor, $folder, $source, $companionIds);
            }

            $report->update([
                'properties_declared' => $report->properties()->where('is_declared', true)->count(),
                'properties_inspected' => $report->properties()->where('is_inspected', true)->count(),
            ]);
            $this->completion->evaluateSource($source->load('template', 'businessReport'));
            $this->completion->evaluateFolder($folder);
            $this->progress->recalculate($folder);
            AuditLog::create([
                'user_id' => $actor->id, 'client_folder_id' => $folder->id,
                'action' => 'business_report.updated', 'module' => 'income_sources',
                'description' => 'A dedicated business report was updated.',
                'metadata' => ['income_source_id' => $source->id, 'co_maker_id' => $source->co_maker_id, 'business_report_id' => $report->id, 'revision' => $source->revision, 'state' => $source->state->value, 'child_changes' => $changes, 'display_name' => $source->displayName(), 'changed_fields' => $changedReportFields],
                'ip_address' => request()?->ip(), 'user_agent' => request()?->userAgent(),
            ]);
            AuditLog::create([
                'user_id' => $actor->id, 'client_folder_id' => $folder->id,
                'action' => 'income_source.updated', 'module' => 'income_sources',
                'description' => 'An income source was updated.',
                'metadata' => ['income_source_id' => $source->id, 'co_maker_id' => $source->co_maker_id, 'template_type' => $source->template_type, 'revision' => $source->revision, 'state' => $source->state->value],
                'ip_address' => request()?->ip(), 'user_agent' => request()?->userAgent(),
            ]);

            return $source->refresh();
        });
    }

    private function sync(HasMany $relation, array $rows, array $fields, string $required): array
    {
        $changes = ['created' => 0, 'updated' => 0, 'deleted' => 0];
        $isObservations = $relation->getRelated()->getTable() === 'business_observations';
        $originalCodes = [];
        if ($isObservations) {
            // A row's observation_code has to move out of the way via a temporary value first so
            // two rows can safely swap codes without a unique-constraint collision — captured here
            // (before that happens) so real changes can still be told apart from a same-code
            // resubmission once the temp value is fixed back up below.
            $originalCodes = $relation->get()->pluck('observation_code', 'id')->all();
            (clone $relation)->whereIn('id', collect($rows)->pluck('id')->filter())->get()->each(fn ($row) => $row->update(['observation_code' => '__pending_'.$row->id]));
        }
        foreach ($rows as $index => $row) {
            $id = filled($row['id'] ?? null) ? (int) $row['id'] : null;
            $delete = (bool) ($row['_delete'] ?? false);
            $payload = Arr::only($row, $fields) + ['sort_order' => $index + 1];
            if ($id) {
                $model = (clone $relation)->findOrFail($id);
                if ($delete) {
                    $model->delete();
                    $changes['deleted']++;

                    continue;
                }
                $model->fill($payload);
                $meaningfullyChanged = $isObservations
                    ? $model->isDirty(array_diff($fields, ['observation_code'])) || ($originalCodes[$id] ?? null) !== ($payload['observation_code'] ?? null)
                    : $model->isDirty();
                $model->save();
                if ($meaningfullyChanged) {
                    $changes['updated']++;
                }
            } elseif (! $delete && filled($row[$required] ?? null)) {
                $relation->create($payload);
                $changes['created']++;
            }
        }

        return $changes;
    }

    private function syncTenants(BusinessReport $report, array $rows): array
    {
        $changes = ['created' => 0, 'updated' => 0, 'deleted' => 0];
        foreach ($rows as $index => $row) {
            $id = filled($row['id'] ?? null) ? (int) $row['id'] : null;
            $delete = (bool) ($row['_delete'] ?? false);
            $property = filled($row['business_property_id'] ?? null) ? $report->properties()->findOrFail((int) $row['business_property_id']) : null;
            $payload = Arr::only($row, ['tenant_name', 'monthly_rent', 'years_renting', 'has_contract', 'contact_details', 'remarks']) + ['sort_order' => $index + 1];
            if ($id) {
                $tenant = $report->properties()->with('tenants')->get()->pluck('tenants')->flatten()->firstWhere('id', $id);
                abort_if($tenant === null, 404);
                if ($delete) {
                    $tenant->delete();
                    $changes['deleted']++;
                } else {
                    $tenant->business_property_id = $property->id;
                    $tenant->fill($payload);
                    $meaningfullyChanged = $tenant->isDirty();
                    $tenant->save();
                    if ($meaningfullyChanged) {
                        $changes['updated']++;
                    }
                }
            } elseif (! $delete && $property && filled($row['tenant_name'] ?? null)) {
                $property->tenants()->create($payload);
                $changes['created']++;
            }
        }

        return $changes;
    }
}
