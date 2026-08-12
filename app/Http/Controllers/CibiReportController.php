<?php

namespace App\Http\Controllers;

use App\Actions\ClientFolders\SaveCibiReport;
use App\Enums\RecordState;
use App\Http\Requests\ClientFolders\SaveCibiReportRequest;
use App\Models\ClientFolder;
use App\Services\ClientFolders\CibiReportFormData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class CibiReportController extends Controller
{
    public function edit(ClientFolder $clientFolder, CibiReportFormData $formData): View
    {
        Gate::authorize('update', $clientFolder);

        return view('client-folders.cibi-report.edit', ['clientFolder' => $clientFolder] + $formData->for($clientFolder));
    }

    public function update(SaveCibiReportRequest $request, ClientFolder $clientFolder, SaveCibiReport $save): RedirectResponse|JsonResponse
    {
        $wasCompleted = $clientFolder->cibiReport()->where('state', RecordState::Complete)->exists();
        $report = $save->execute($request->user(), $clientFolder, $request->validated());
        $message = $wasCompleted ? 'CI/BI Report updated successfully.' : 'CI/BI Report saved successfully.';

        if ($request->expectsJson()) {
            $clientFolder->refresh();

            return response()->json([
                'message' => $message,
                'return_url' => route('client-folders.show', $clientFolder),
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
            ]);
        }

        return redirect(route('client-folders.cibi-report.edit', $clientFolder))
            ->with('status', $message);
    }
}
