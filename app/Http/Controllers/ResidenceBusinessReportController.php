<?php

namespace App\Http\Controllers;

use App\Enums\OfficialReportType;
use App\Models\ClientFolder;
use App\Services\ClientFolders\ActivePersonResolver;
use App\Services\Reports\OfficialReportDataBuilder;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class ResidenceBusinessReportController extends Controller
{
    public function edit(ClientFolder $clientFolder): View
    {
        Gate::authorize('view', $clientFolder);
        $activePerson = ActivePersonResolver::resolveFromQuery($clientFolder, request());
        $personId = $activePerson?->id;

        $residenceChecks = $clientFolder->residenceChecks()->where('co_maker_id', $personId)->withCount('photos')->with('photos')->orderByDesc('ci_date')->orderByDesc('id')->get();
        $businessChecks = $clientFolder->businessChecks()->where('co_maker_id', $personId)->withCount(['businessPhotos', 'competitorPhotos'])->with(['photos', 'incomeSource:id,source_name,business_name,income_source_template_id', 'incomeSource.template:id,template_type'])->orderByDesc('ci_date')->orderByDesc('id')->get();

        return view('client-folders.residence-business.edit', [
            'clientFolder' => $clientFolder,
            'activePerson' => $activePerson,
            'residenceChecks' => $residenceChecks,
            'businessChecks' => $businessChecks,
        ]);
    }

    public function preview(ClientFolder $clientFolder, OfficialReportDataBuilder $builder): View
    {
        Gate::authorize('view', $clientFolder);
        $activePerson = ActivePersonResolver::resolveFromQuery($clientFolder, request());
        $document = $builder->build($clientFolder, OfficialReportType::ResidenceBusinessPhoto, null, $activePerson);

        return view('reports.official.document', ['document' => $document, 'pdfMode' => false, 'clientFolder' => $clientFolder, 'type' => OfficialReportType::ResidenceBusinessPhoto, 'source' => null]);
    }
}
