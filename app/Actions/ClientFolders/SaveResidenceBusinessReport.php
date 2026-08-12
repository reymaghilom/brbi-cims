<?php

namespace App\Actions\ClientFolders;

use App\Enums\RecordState;
use App\Models\AuditLog;
use App\Models\ClientFolder;
use App\Models\PhotoReportSection;
use App\Models\ResidenceBusinessReport;
use App\Models\User;
use App\Services\ClientFolders\ResidenceBusinessReportCompletionEvaluator;
use App\Services\Progress\ClientProgressService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class SaveResidenceBusinessReport
{
    private const REPORT_FIELDS = ['report_date', 'default_location', 'default_subject', 'residence_remarks', 'business_remarks'];

    private const SECTION_FIELDS = ['income_source_id', 'category', 'subject_party', 'subject_name', 'heading_subject', 'location', 'business_name', 'google_maps_link', 'remarks', 'sort_order'];

    public function __construct(
        private readonly ResidenceBusinessReportCompletionEvaluator $completion,
        private readonly ClientProgressService $progress,
    ) {}

    public function execute(User $actor, ClientFolder $folder, array $data): ResidenceBusinessReport
    {
        return DB::transaction(function () use ($actor, $folder, $data): ResidenceBusinessReport {
            $report = $folder->residenceBusinessReport()->firstOrNew();
            $created = ! $report->exists;
            $report->fill(Arr::only($data, self::REPORT_FIELDS));
            $report->ci_user_id = $report->ci_user_id ?: $folder->assigned_ci_id;
            $report->state = $data['intent'] === 'complete' ? RecordState::Complete : RecordState::Draft;
            $report->revision = $created ? 1 : $report->revision + 1;
            $report->save();

            $changes = ['sections' => ['created' => 0, 'updated' => 0, 'deleted' => 0], 'media' => ['linked' => 0, 'updated' => 0, 'unlinked' => 0]];
            foreach ($data['sections'] ?? [] as $row) {
                $id = filled($row['id'] ?? null) ? (int) $row['id'] : null;
                $delete = (bool) ($row['_delete'] ?? false);
                if ($id !== null) {
                    $section = $report->sections()->findOrFail($id);
                    if ($delete) {
                        $changes['media']['unlinked'] += $section->mediaItems()->count();
                        $section->delete();
                        $changes['sections']['deleted']++;

                        continue;
                    }
                    $section->update(Arr::only($row, self::SECTION_FIELDS));
                    $changes['sections']['updated']++;
                } else {
                    if ($delete) {
                        continue;
                    }
                    $section = $report->sections()->create(Arr::only($row, self::SECTION_FIELDS));
                    $changes['sections']['created']++;
                }

                $this->syncMedia($section, $row['media'] ?? [], $changes['media']);
            }

            $this->completion->evaluate($report->load('clientFolder'));
            $this->progress->recalculate($folder);
            AuditLog::create([
                'user_id' => $actor->id,
                'client_folder_id' => $folder->id,
                'action' => $created ? 'residence_business_report.created' : 'residence_business_report.updated',
                'module' => 'residence_business_report',
                'description' => $created ? 'A Residence & Business report was created.' : 'A Residence & Business report was updated.',
                'metadata' => ['report_id' => $report->id, 'revision' => $report->revision, 'state' => $report->state->value, 'structural_changes' => $changes],
                'ip_address' => request()?->ip(),
                'user_agent' => request()?->userAgent(),
            ]);

            return $report->refresh();
        });
    }

    private function syncMedia(PhotoReportSection $section, array $rows, array &$changes): void
    {
        $selected = collect($rows)->filter(fn (array $row): bool => (bool) ($row['selected'] ?? false));
        $selectedIds = $selected->pluck('media_reference_id')->map(fn ($id): int => (int) $id)->all();
        $removed = $section->mediaItems()->whereNotIn('media_reference_id', $selectedIds)->count();
        $section->mediaItems()->whereNotIn('media_reference_id', $selectedIds)->delete();
        $changes['unlinked'] += $removed;

        foreach ($selected as $row) {
            $item = $section->mediaItems()->firstOrNew(['media_reference_id' => (int) $row['media_reference_id']]);
            $new = ! $item->exists;
            $item->fill(Arr::only($row, ['output_label', 'caption', 'sort_order']));
            if ($new || $item->isDirty()) {
                $item->save();
                $changes[$new ? 'linked' : 'updated']++;
            }
        }
    }
}
