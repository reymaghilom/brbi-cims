<?php

namespace App\Services\ClientFolders;

use App\Models\ClientFolder;
use App\Models\CoMaker;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The single definition of a Co-Maker that "already contains saved investigation records" — and so
 * may no longer be permanently deleted by a Credit Investigator. A Senior CI or Administrator may
 * delete one with saved records. Always scoped to the
 * exact client_folder_id AND co_maker_id: Applicant records, other Co-Makers and folder-wide data
 * never make a Co-Maker non-empty.
 *
 * A Co-Maker is still EMPTY while it holds only its own row (identity fields, timestamps) and its
 * own identity history (co_maker.added / co_maker.updated).
 *
 * It is NON-EMPTY as soon as any record owned by that exact Co-Maker exists in a person-scoped
 * table (read directly, so soft-deleted rows count too): CI/BI report, businesses / income sources,
 * Residence/Business report, Residence and Business Checks, CI Activities of any type (which carry
 * their Bank/Asset targets, notes and Supporting Proof), media, generated reports — or any
 * operational audit entry recorded for that exact Co-Maker (metadata.co_maker_id). The history
 * clause keeps a Co-Maker non-deletable after its work was later removed or reset.
 *
 * Folder-level completion results carry no person scope, so they are not a Co-Maker blocker.
 */
class CoMakerSavedRecords
{
    private const OWNED_TABLES = [
        'cibi_reports', 'income_sources', 'residence_business_reports', 'residence_checks',
        'business_checks', 'ci_activities', 'media_references', 'generated_reports',
    ];

    private const IDENTITY_AUDIT_ACTIONS = ['co_maker.added', 'co_maker.updated', 'co_maker.removed'];

    public function hasSavedRecords(CoMaker $coMaker): bool
    {
        return $this->whereHasSavedRecords(CoMaker::query()->whereKey($coMaker->getKey()))->exists();
    }

    /** @return Collection<int, int> ids of this folder's Co-Makers that already contain saved records */
    public function idsWithSavedRecords(ClientFolder $folder): Collection
    {
        return $this->whereHasSavedRecords(CoMaker::query()->where('client_folder_id', $folder->id))
            ->pluck('co_makers.id')
            ->map(fn ($id): int => (int) $id);
    }

    private function whereHasSavedRecords(Builder $coMakers): Builder
    {
        return $coMakers->where(function (Builder $query): void {
            foreach (self::OWNED_TABLES as $table) {
                $query->orWhereExists(fn ($owned) => $owned->from($table)
                    ->whereColumn("{$table}.co_maker_id", 'co_makers.id')
                    ->whereColumn("{$table}.client_folder_id", 'co_makers.client_folder_id'));
            }

            $query->orWhereExists(fn ($audit) => $audit->from('audit_logs')
                ->whereColumn('audit_logs.client_folder_id', 'co_makers.client_folder_id')
                ->whereColumn('audit_logs.metadata->co_maker_id', 'co_makers.id')
                ->whereNotIn('audit_logs.action', self::IDENTITY_AUDIT_ACTIONS));
        });
    }
}
