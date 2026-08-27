<?php

namespace App\Actions\ClientFolders;

use App\Models\AuditLog;
use App\Models\CibiReport;
use App\Models\ClientFolder;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ReassignCibiSignatory
{
    public function execute(User $actor, ClientFolder $folder, CibiReport $report, int $newSignatoryId, string $reason): CibiReport
    {
        return DB::transaction(function () use ($actor, $folder, $report, $newSignatoryId, $reason): CibiReport {
            $oldSignatoryId = $report->ci_in_charge_id;
            // Name snapshots, not just ids — Recent Activity and any future audit rendering must
            // never depend on the old/new signatory's user record still existing or being loaded.
            $oldSignatoryName = $report->investigator?->full_name;
            $newSignatoryName = User::find($newSignatoryId)?->full_name;

            $report->ci_in_charge_id = $newSignatoryId;
            // prepared_by_name is a separate stored column that official output (Preview/Print/
            // PDF/Excel) reads directly — SaveCibiReportRequest only re-syncs it from the current
            // signatory on the next normal encoding-form save. Without updating it here too, the
            // official "Prepared By" would keep showing the OLD signatory until someone happened
            // to save the report again after this reassignment.
            $report->prepared_by_name = $newSignatoryName;
            $report->save();

            AuditLog::create([
                'user_id' => $actor->id,
                'client_folder_id' => $folder->id,
                'action' => 'cibi_report.signatory_reassigned',
                'module' => 'cibi_report',
                'description' => 'The CI / BI report signatory was reassigned by an Administrator.',
                'metadata' => [
                    'report_id' => $report->id,
                    'co_maker_id' => $report->co_maker_id,
                    'old_signatory_id' => $oldSignatoryId,
                    'old_signatory_name' => $oldSignatoryName,
                    'new_signatory_id' => $newSignatoryId,
                    'new_signatory_name' => $newSignatoryName,
                    'reason' => $reason,
                    'reassigned_by' => $actor->id,
                ],
                'ip_address' => request()?->ip(),
                'user_agent' => request()?->userAgent(),
            ]);

            return $report->refresh();
        });
    }
}
