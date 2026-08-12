<?php

namespace App\Http\Controllers;

use App\Actions\ClientFolders\SaveResidenceBusinessReport;
use App\Enums\OfficialReportType;
use App\Http\Requests\ClientFolders\SaveResidenceBusinessReportRequest;
use App\Models\ClientFolder;
use App\Models\ResidenceBusinessReport;
use App\Services\Reports\OfficialReportDataBuilder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class ResidenceBusinessReportController extends Controller
{
    public function edit(ClientFolder $clientFolder): View
    {
        $report = $clientFolder->residenceBusinessReport;
        $report ? Gate::authorize('update', $report) : Gate::authorize('create', [ResidenceBusinessReport::class, $clientFolder]);
        $clientFolder->load('assignedInvestigator:id,full_name');
        $report?->load(['sections' => fn ($query) => $query->orderBy('sort_order')->orderBy('id'), 'sections.mediaItems']);

        return view('client-folders.residence-business.edit', [
            'clientFolder' => $clientFolder,
            'report' => $report,
            'incomeSources' => $clientFolder->incomeSources()->orderBy('sort_order')->get(['id', 'source_name', 'business_name']),
            'mediaReferences' => $clientFolder->mediaReferences()->with('incomeSource:id,source_name')->orderBy('captured_at')->orderBy('id')->get(['id', 'income_source_id', 'media_type', 'category', 'file_name', 'label', 'captured_at', 'google_maps_link']),
        ]);
    }

    public function update(SaveResidenceBusinessReportRequest $request, ClientFolder $clientFolder, SaveResidenceBusinessReport $save): RedirectResponse
    {
        $save->execute($request->user(), $clientFolder, $request->validated());
        $route = $request->string('intent')->toString() === 'return'
            ? route('client-folders.show', $clientFolder)
            : route('client-folders.residence-business.edit', $clientFolder);

        return redirect($route)->with('status', 'Residence & Business report saved successfully.');
    }

    public function preview(ClientFolder $clientFolder, OfficialReportDataBuilder $builder): View
    {
        Gate::authorize('view', $clientFolder);
        $document = $builder->build($clientFolder, OfficialReportType::ResidenceBusinessPhoto);

        return view('reports.official.document', ['document' => $document, 'pdfMode' => false, 'clientFolder' => $clientFolder, 'type' => OfficialReportType::ResidenceBusinessPhoto, 'source' => null]);
    }
}
