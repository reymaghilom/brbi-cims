<?php

namespace App\Http\Controllers;

use App\Actions\ClientFolders\SaveCibiReport;
use App\Enums\RecordState;
use App\Exceptions\NoChangesDetectedException;
use App\Http\Requests\ClientFolders\SaveCibiReportRequest;
use App\Models\ClientFolder;
use App\Services\ClientFolders\ActivePersonResolver;
use App\Services\ClientFolders\CibiReportFormData;
use App\Services\ClientFolders\ClientFolderOverview;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class CibiReportController extends Controller
{
    public function edit(ClientFolder $clientFolder, CibiReportFormData $formData): View
    {
        Gate::authorize('update', $clientFolder);
        $activePerson = ActivePersonResolver::resolveFromQuery($clientFolder, request());

        return view('client-folders.cibi-report.edit', [
            'clientFolder' => $clientFolder,
            'activePerson' => $activePerson,
        ] + $formData->for($clientFolder, $activePerson));
    }

    public function update(SaveCibiReportRequest $request, ClientFolder $clientFolder, SaveCibiReport $save, ClientFolderOverview $overview): RedirectResponse|JsonResponse
    {
        $activePerson = ActivePersonResolver::resolve($clientFolder, $request->validated('co_maker_id'));
        $personParams = ActivePersonResolver::queryParams($activePerson);
        $wasCompleted = $clientFolder->cibiReport()->where('co_maker_id', $activePerson?->id)->where('state', RecordState::Complete)->exists();

        try {
            $report = $save->execute($request->user(), $clientFolder, $request->validated());
        } catch (NoChangesDetectedException $e) {
            if ($request->expectsJson()) {
                return response()->json(['message' => $e->getMessage(), 'no_change' => true]);
            }

            return redirect(route('client-folders.cibi-report.edit', [$clientFolder] + $personParams))
                ->with('status', $e->getMessage())->with('statusType', 'info');
        }

        $message = $wasCompleted ? 'CI/BI Report updated successfully.' : 'CI/BI Report saved successfully.';

        if ($request->expectsJson()) {
            $clientFolder->refresh();

            return response()->json([
                'message' => $message,
                'return_url' => route('client-folders.show', [$clientFolder] + $personParams),
                'report' => [
                    'state' => $report->state->value,
                    'was_completed' => $wasCompleted,
                    'submit_label' => 'Update',
                    'revision' => $report->revision,
                    'institutions_checked' => $report->summary_totals['institutions_checked'] ?? $report->creditChecks()->whereNotNull('institution')->count(),
                    'institutions_declared' => $report->summary_totals['institutions_declared'] ?? $report->creditChecks()->where('is_declared', true)->count(),
                    'loan_records_found' => $report->summary_totals['loan_records_found'] ?? $report->loanRecords()->whereNotNull('institution')->count(),
                    'child_ids' => [
                        'bank_accounts' => $report->bankAccounts()->orderBy('sort_order')->pluck('id')->values(),
                        'loan_records' => $report->loanRecords()->orderBy('sort_order')->pluck('id')->values(),
                        'credit_checks' => $report->creditChecks()->orderBy('sort_order')->pluck('id')->values(),
                        'income_summaries' => $report->incomeSourceSummaries()->orderBy('sort_order')->pluck('id')->values(),
                        'legal_findings' => $report->legalFindings()->orderBy('sort_order')->pluck('id')->values(),
                    ],
                    'child_row_ids' => [
                        'bank_accounts' => $report->bankAccounts()
                            ->orderBy('sort_order')
                            ->get(['id', 'sort_order'])
                            ->mapWithKeys(fn ($row): array => [(string) max(0, $row->sort_order - 1) => $row->id]),
                    ],
                ],
                'folder' => [
                    'status' => $clientFolder->status->value,
                    'progress_percentage' => (float) $clientFolder->progress_percent,
                ],
                // Client Folder Contents' own CI/BI module card AUTO-UPDATEs from this exact same
                // authoritative response — canonical state derivation (report presence/completion,
                // export/reassign eligibility), never guessed client-side. $report is already scoped
                // to the exact Applicant/Co-Maker that owns this save.
                'cibi_module_html' => view('client-folders.partials.cibi-module-card', [
                    'clientFolder' => $clientFolder,
                    'cibiReport' => $report,
                    'activePerson' => $activePerson,
                    'displayTimezone' => config('cims.display_timezone'),
                ])->render(),
                // Client Folder Contents' own Recent Activity panel (client-folders/show.blade.php)
                // AUTO-UPDATEs from this exact same authoritative save response — no second GET,
                // same canonical AuditLog-backed source (ClientFolderOverview::recentPersonActivity)
                // the initial page render itself uses, same Applicant/exact-Co-Maker isolation.
                'recent_activity_html' => view('client-folders.partials.recent-activity-body', [
                    'recentPersonActivity' => $overview->recentPersonActivity($clientFolder, $activePerson),
                    'coMakers' => $clientFolder->coMakers,
                    'viewingLabel' => $activePerson ? 'Co-Maker — '.mb_strtoupper($activePerson->full_name) : 'Applicant',
                    'displayTimezone' => config('cims.display_timezone'),
                ])->render(),
            ]);
        }

        return redirect(route('client-folders.cibi-report.edit', [$clientFolder] + $personParams))
            ->with('status', $message);
    }
}
