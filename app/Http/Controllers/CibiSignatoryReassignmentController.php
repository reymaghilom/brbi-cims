<?php

namespace App\Http\Controllers;

use App\Actions\ClientFolders\ReassignCibiSignatory;
use App\Http\Requests\ClientFolders\ReassignCibiSignatoryRequest;
use App\Models\AuditLog;
use App\Models\CibiReport;
use App\Models\ClientFolder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class CibiSignatoryReassignmentController extends Controller
{
    public function store(ReassignCibiSignatoryRequest $request, ClientFolder $clientFolder, CibiReport $cibiReport, ReassignCibiSignatory $reassign): RedirectResponse
    {
        $report = $reassign->execute(
            $request->user(),
            $clientFolder,
            $cibiReport,
            (int) $request->validated('new_signatory_id'),
            $request->validated('reason'),
        );

        $personParams = $report->co_maker_id ? ['person' => 'co-maker', 'co_maker_id' => $report->co_maker_id] : [];

        return redirect()->route('client-folders.show', [$clientFolder] + $personParams)
            ->with('status', 'Signatory reassigned — '.$report->investigator?->full_name.' is now the official Prepared By / Signatory.');
    }

    public function history(ClientFolder $clientFolder, CibiReport $cibiReport): View
    {
        Gate::authorize('viewHistory', $cibiReport);

        $entries = AuditLog::query()
            ->where('client_folder_id', $clientFolder->id)
            ->where('module', 'cibi_report')
            ->whereJsonContains('metadata->report_id', $cibiReport->id)
            ->with('user:id,full_name')
            ->latest('created_at')
            ->limit(50)
            ->get();

        return view('client-folders.cibi-report.history', compact('clientFolder', 'cibiReport', 'entries'));
    }
}
