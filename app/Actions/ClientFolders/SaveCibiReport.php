<?php

namespace App\Actions\ClientFolders;

use App\Enums\PartyType;
use App\Enums\RecordState;
use App\Exceptions\NoChangesDetectedException;
use App\Models\AuditLog;
use App\Models\CibiReport;
use App\Models\ClientFolder;
use App\Models\User;
use App\Services\ClientFolders\CibiReportCompletionEvaluator;
use App\Services\Progress\ClientProgressService;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SaveCibiReport
{
    private const REPORT_FIELDS = [
        'start_date', 'submitted_date', 'party_type', 'branch_name', 'account_officer_name', 'amount_applied',
        'ci_risk_level', 'personal_snapshot', 'summary_totals', 'purpose_codes', 'purpose_other', 'purpose_remarks', 'negative_credit_findings',
        'other_remarks', 'prepared_by_name', 'noted_by_name',
    ];

    private const CHILDREN = [
        'bank_accounts' => ['bankAccounts', ['institution', 'branch', 'year_opened', 'adb_level', 'capital_share_amount', 'capital_share_text', 'relevant_remarks']],
        'loan_records' => ['loanRecords', ['institution', 'original_amount', 'remaining_balance', 'amortization_amount', 'granted_date', 'maturity_date', 'cycle_number', 'cycle_label', 'security_type', 'payment_performance', 'remarks']],
        'credit_checks' => ['creditChecks', ['institution', 'branch', 'is_declared', 'check_status', 'checked_date', 'key_information', 'remarks']],
        'income_summaries' => ['incomeSourceSummaries', ['income_source_id', 'source_name', 'source_type', 'stability_result', 'validation_status', 'key_information', 'monthly_amount']],
        'legal_findings' => ['legalFindings', ['source_level', 'result', 'details', 'checked_at']],
    ];

    public function __construct(
        private readonly CibiReportCompletionEvaluator $completion,
        private readonly ClientProgressService $progress,
    ) {}

    public function execute(User $actor, ClientFolder $folder, array $data): CibiReport
    {
        return DB::transaction(function () use ($actor, $folder, $data): CibiReport {
            $report = $folder->cibiReport()->firstOrNew(['co_maker_id' => $data['co_maker_id'] ?? null]);
            $created = ! $report->exists;

            if (! $created) {
                // A blank expected_revision means the form was opened when no report existed yet
                // (firstOrNew found nothing at page-load time). If a report now exists here, someone
                // else's save created it in the meantime — that is itself a conflict, since this
                // submission's baseline (an empty report) is no longer the true baseline.
                $baselineConflict = blank($data['expected_revision'] ?? null)
                    || (int) $data['expected_revision'] !== $report->revision;

                if ($baselineConflict) {
                    $editorName = $report->lastEditor?->full_name;
                    $message = $editorName
                        ? "{$editorName} updated this report while you were editing. Please review the latest version before saving again."
                        : 'This CI/BI Report was updated by another user while you were editing. Please review the latest version before saving again.';

                    throw ValidationException::withMessages(['expected_revision' => $message]);
                }
            }

            $report->fill(Arr::only($data, self::REPORT_FIELDS));
            $report->party_type = $data['party_type'] ?? PartyType::Borrower->value;

            $changes = [];
            if (! $created) {
                // Existing report: it already has an id, so children can be synced (and their
                // real changes measured) before deciding whether to touch the parent record at
                // all — sync() only ever writes a child row that's actually dirty, so running it
                // here is safe regardless of the outcome below.
                $fieldsChanged = $report->isDirty(array_merge(self::REPORT_FIELDS, ['party_type']));
                foreach (self::CHILDREN as $input => [$relation, $fields]) {
                    if (array_key_exists($input, $data)) {
                        $changes[$input] = $this->sync($input, $report->{$relation}(), $data[$input], $fields);
                    }
                }
                $childrenChanged = collect($changes)->contains(fn (array $c): bool => $c['created'] > 0 || $c['updated'] > 0 || $c['deleted'] > 0);

                if (! $fieldsChanged && ! $childrenChanged) {
                    throw new NoChangesDetectedException;
                }
            }

            // The CI who first saves the report becomes its official signatory; later saves by
            // other investigators must never reassign it (only an Administrator reassignment can).
            $report->ci_in_charge_id = $report->ci_in_charge_id ?: $actor->id;
            $report->created_by = $report->created_by ?: $actor->id;
            $report->last_edited_by = $actor->id;
            $report->revision = $created ? 1 : $report->revision + 1;
            $report->state = RecordState::Complete;
            $report->save();

            if ($created) {
                // A brand-new report has no prior state to compare against — no-change detection
                // never applies to creates, so children are simply synced normally here instead.
                foreach (self::CHILDREN as $input => [$relation, $fields]) {
                    if (array_key_exists($input, $data)) {
                        $changes[$input] = $this->sync($input, $report->{$relation}(), $data[$input], $fields);
                    }
                }
            }

            $this->completion->evaluate($report);
            $this->progress->recalculate($folder);

            // Present Address / Start Date may prefill a new, not-yet-saved Residence Check, but
            // saving CI/BI must never rewrite an already-saved Residence Check's own Location or
            // CI Date — prefill-before-save, independent-after-save, same as the reverse direction.

            AuditLog::create([
                'user_id' => $actor->id,
                'client_folder_id' => $folder->id,
                'action' => $created ? 'cibi_report.created' : 'cibi_report.updated',
                'module' => 'cibi_report',
                'description' => $created ? 'A CI / BI report was created.' : 'A CI / BI report was updated.',
                'metadata' => ['report_id' => $report->id, 'co_maker_id' => $report->co_maker_id, 'revision' => $report->revision, 'state' => $report->state->value, 'child_changes' => $changes],
                'ip_address' => request()?->ip(),
                'user_agent' => request()?->userAgent(),
            ]);

            return $report->refresh();
        });
    }

    private function sync(string $input, HasMany $relation, array $rows, array $fields): array
    {
        $changes = ['created' => 0, 'updated' => 0, 'deleted' => 0];
        $existing = $relation->get()->keyBy(fn ($model): int => (int) $model->getKey());

        foreach ($rows as $index => $row) {
            $id = isset($row['id']) && filled($row['id']) ? (int) $row['id'] : null;
            $delete = (bool) ($row['_delete'] ?? false);

            if ($id !== null) {
                $model = $existing->get($id);
                if (! $model) {
                    if ($input !== 'bank_accounts') {
                        throw ValidationException::withMessages([
                            "$input.$index.id" => 'This entry is no longer available. Reload the report and try again.',
                        ]);
                    }
                    $existsOnAnotherReport = $relation->getRelated()->newQuery()->whereKey($id)->exists();
                    if ($existsOnAnotherReport) {
                        throw ValidationException::withMessages([
                            "$input.$index.id" => 'This entry does not belong to this CI / BI report.',
                        ]);
                    }
                    if ($delete) {
                        continue;
                    }
                    $id = null;
                }
                if ($model && $delete) {
                    $model->delete();
                    $changes['deleted']++;
                } elseif ($model) {
                    $model->fill(Arr::only($row, $fields) + ['sort_order' => $index + 1]);
                    if ($model->isDirty()) {
                        $model->save();
                        $changes['updated']++;
                    }
                }

                if ($model) {
                    continue;
                }
            }

            $payload = Arr::only($row, $fields);
            if (! $delete && collect($payload)->except(['is_declared'])->contains(fn ($value): bool => filled($value))) {
                $relation->create($payload + ['sort_order' => $index + 1]);
                $changes['created']++;
            }
        }

        return $changes;
    }
}
