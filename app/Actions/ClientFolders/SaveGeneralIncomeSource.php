<?php

namespace App\Actions\ClientFolders;

use App\Enums\RecordState;
use App\Models\AuditLog;
use App\Models\ClientFolder;
use App\Models\IncomeSource;
use App\Models\User;
use App\Services\ClientFolders\IncomeSourcesCompletionEvaluator;
use App\Services\Progress\ClientProgressService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class SaveGeneralIncomeSource
{
    private const SOURCE_FIELDS = ['source_name', 'applicant_name_snapshot', 'branch_name', 'amount_applied', 'account_officer_name'];

    public function __construct(private readonly IncomeSourcesCompletionEvaluator $completion, private readonly ClientProgressService $progress) {}

    public function execute(User $actor, ClientFolder $folder, IncomeSource $source, array $data): IncomeSource
    {
        return DB::transaction(function () use ($actor, $folder, $source, $data): IncomeSource {
            $source->fill(Arr::only($data, self::SOURCE_FIELDS));
            $source->state = $data['intent'] === 'complete' ? RecordState::Complete : RecordState::Draft;
            $source->last_edited_by = $actor->id;
            $source->revision++;
            $source->save();

            $report = $source->generalReport()->firstOrCreate();
            $report->update(['general_remarks' => $data['general_remarks'] ?? null]);
            $changes = $this->syncItems($report, $data['items'] ?? []);

            $this->completion->evaluateSource($source->load('template', 'generalReport.declaredItems'));
            $this->completion->evaluateFolder($folder);
            $this->progress->recalculate($folder);
            AuditLog::create([
                'user_id' => $actor->id, 'client_folder_id' => $folder->id,
                'action' => 'general_income_source_report.updated', 'module' => 'income_sources',
                'description' => 'A general income source validation record was updated.',
                'metadata' => ['income_source_id' => $source->id, 'revision' => $source->revision, 'state' => $source->state->value, 'item_changes' => $changes],
                'ip_address' => request()?->ip(), 'user_agent' => request()?->userAgent(),
            ]);
            AuditLog::create([
                'user_id' => $actor->id, 'client_folder_id' => $folder->id,
                'action' => 'income_source.updated', 'module' => 'income_sources',
                'description' => 'An income source was updated.',
                'metadata' => ['income_source_id' => $source->id, 'template_type' => $source->template_type, 'revision' => $source->revision, 'state' => $source->state->value],
                'ip_address' => request()?->ip(), 'user_agent' => request()?->userAgent(),
            ]);

            return $source->refresh();
        });
    }

    private function syncItems($report, array $rows): array
    {
        $changes = ['created' => 0, 'updated' => 0, 'deleted' => 0];
        $report->declaredItems()->whereIn('id', collect($rows)->pluck('id')->filter())->update(['contribution_rank' => null]);
        foreach ($rows as $index => $row) {
            $id = filled($row['id'] ?? null) ? (int) $row['id'] : null;
            $delete = (bool) ($row['_delete'] ?? false);
            $payload = Arr::only($row, ['source_name', 'source_type', 'description', 'amount_contribution', 'contribution_rank', 'remarks']) + ['sort_order' => $index + 1];
            if ($id) {
                $item = $report->declaredItems()->findOrFail($id);
                $delete ? $item->delete() : $item->update($payload);
                $changes[$delete ? 'deleted' : 'updated']++;
            } elseif (! $delete && collect($payload)->except('sort_order')->contains(fn ($value): bool => filled($value))) {
                $report->declaredItems()->create($payload);
                $changes['created']++;
            }
        }

        return $changes;
    }
}
