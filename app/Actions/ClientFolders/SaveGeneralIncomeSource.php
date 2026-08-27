<?php

namespace App\Actions\ClientFolders;

use App\Enums\RecordState;
use App\Exceptions\NoChangesDetectedException;
use App\Models\AuditLog;
use App\Models\ClientFolder;
use App\Models\IncomeSource;
use App\Models\User;
use App\Services\ClientFolders\IncomeSourcesCompletionEvaluator;
use App\Services\Progress\ClientProgressService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SaveGeneralIncomeSource
{
    private const SOURCE_FIELDS = ['source_name', 'applicant_name_snapshot', 'branch_name', 'amount_applied', 'account_officer_name'];

    public function __construct(private readonly IncomeSourcesCompletionEvaluator $completion, private readonly ClientProgressService $progress) {}

    public function execute(User $actor, ClientFolder $folder, IncomeSource $source, array $data): IncomeSource
    {
        return DB::transaction(function () use ($actor, $folder, $source, $data): IncomeSource {
            if (filled($data['expected_revision'] ?? null) && (int) $data['expected_revision'] !== $source->revision) {
                throw ValidationException::withMessages([
                    'expected_revision' => 'This record has been updated by another CI. Please review the latest version before saving.',
                ]);
            }

            $isFirstSave = $source->wasRecentlyCreated;

            $source->fill(Arr::only($data, self::SOURCE_FIELDS));
            $sourceFieldsChanged = $source->isDirty(self::SOURCE_FIELDS);

            $report = $source->generalReport()->firstOrCreate();
            $remarksChanged = $report->general_remarks !== ($data['general_remarks'] ?? null);
            $report->update(['general_remarks' => $data['general_remarks'] ?? null]);
            $changes = $this->syncItems($report, $data['items'] ?? []);
            $itemsChanged = $changes['created'] > 0 || $changes['updated'] > 0 || $changes['deleted'] > 0;

            if (! $isFirstSave && ! $sourceFieldsChanged && ! $remarksChanged && ! $itemsChanged) {
                throw new NoChangesDetectedException();
            }

            $source->state = $data['intent'] === 'complete' ? RecordState::Complete : RecordState::Draft;
            $source->last_edited_by = $actor->id;
            $source->revision++;
            $source->save();

            $this->completion->evaluateSource($source->load('template', 'generalReport.declaredItems'));
            $this->completion->evaluateFolder($folder);
            $this->progress->recalculate($folder);
            AuditLog::create([
                'user_id' => $actor->id, 'client_folder_id' => $folder->id,
                'action' => 'general_income_source_report.updated', 'module' => 'income_sources',
                'description' => 'A general income source validation record was updated.',
                'metadata' => ['income_source_id' => $source->id, 'co_maker_id' => $source->co_maker_id, 'revision' => $source->revision, 'state' => $source->state->value, 'item_changes' => $changes, 'display_name' => $source->displayName()],
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

    private function syncItems($report, array $rows): array
    {
        $changes = ['created' => 0, 'updated' => 0, 'deleted' => 0];
        // contribution_rank has to be cleared first so two items can safely swap ranks without a
        // unique-constraint collision — captured here (before that happens) so a same-rank
        // resubmission can still be told apart from a real rank change once it's set back below.
        $originalRanks = $report->declaredItems()->pluck('contribution_rank', 'id')->all();
        $report->declaredItems()->whereIn('id', collect($rows)->pluck('id')->filter())->update(['contribution_rank' => null]);
        foreach ($rows as $index => $row) {
            $id = filled($row['id'] ?? null) ? (int) $row['id'] : null;
            $delete = (bool) ($row['_delete'] ?? false);
            $payload = Arr::only($row, ['source_name', 'source_type', 'description', 'amount_contribution', 'contribution_rank', 'remarks']) + ['sort_order' => $index + 1];
            if ($id) {
                $item = $report->declaredItems()->findOrFail($id);
                if ($delete) {
                    $item->delete();
                    $changes['deleted']++;
                    continue;
                }
                $item->fill($payload);
                $meaningfullyChanged = $item->isDirty(array_diff(array_keys($payload), ['contribution_rank']))
                    || ($originalRanks[$id] ?? null) !== ($payload['contribution_rank'] ?? null);
                $item->save();
                if ($meaningfullyChanged) {
                    $changes['updated']++;
                }
            } elseif (! $delete && collect($payload)->except('sort_order')->contains(fn ($value): bool => filled($value))) {
                $report->declaredItems()->create($payload);
                $changes['created']++;
            }
        }

        return $changes;
    }
}
