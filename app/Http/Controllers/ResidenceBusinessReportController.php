<?php

namespace App\Http\Controllers;

use App\Actions\ClientFolders\DeleteBusinessCheck;
use App\Actions\ClientFolders\DeleteResidenceCheck;
use App\Enums\OfficialReportType;
use App\Models\ClientFolder;
use App\Services\ClientFolders\ActivePersonResolver;
use App\Services\Reports\OfficialReportDataBuilder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class ResidenceBusinessReportController extends Controller
{
    public function edit(ClientFolder $clientFolder): View
    {
        Gate::authorize('view', $clientFolder);
        $activePerson = ActivePersonResolver::resolveFromQuery($clientFolder, request());
        $personId = $activePerson?->id;

        $residenceChecks = $clientFolder->residenceChecks()->where('co_maker_id', $personId)->withCount('photos')->with(['photos', 'investigator:id,full_name'])->orderByDesc('ci_date')->orderByDesc('id')->get();
        $businessChecks = $clientFolder->businessChecks()->where('co_maker_id', $personId)->withCount(['businessPhotos', 'competitorPhotos'])->with(['photos', 'investigator:id,full_name', 'incomeSource:id,source_name,business_name,income_source_template_id', 'incomeSource.template:id,template_type'])->orderByDesc('ci_date')->orderByDesc('id')->get();

        return view('client-folders.residence-business.edit', [
            'clientFolder' => $clientFolder,
            'activePerson' => $activePerson,
            'residenceChecks' => $residenceChecks,
            'businessChecks' => $businessChecks,
        ]);
    }

    public function batchDelete(
        Request $request,
        ClientFolder $clientFolder,
        DeleteResidenceCheck $deleteResidenceCheck,
        DeleteBusinessCheck $deleteBusinessCheck,
    ): RedirectResponse {
        Gate::authorize('update', $clientFolder);

        $validated = $request->validate([
            'co_maker_id' => ActivePersonResolver::rule($clientFolder),
            'residence_check_ids' => ['sometimes', 'array'],
            'residence_check_ids.*' => ['integer', 'distinct'],
            'business_check_ids' => ['sometimes', 'array'],
            'business_check_ids.*' => ['integer', 'distinct'],
        ]);
        $residenceIds = array_map('intval', $validated['residence_check_ids'] ?? []);
        $businessIds = array_map('intval', $validated['business_check_ids'] ?? []);
        abort_if($residenceIds === [] && $businessIds === [], 422);

        $activePerson = ActivePersonResolver::resolve($clientFolder, $validated['co_maker_id'] ?? null);
        $personId = $activePerson?->id;
        $residenceChecks = $clientFolder->residenceChecks()->where('co_maker_id', $personId)->whereIn('id', $residenceIds)->get();
        $businessChecks = $clientFolder->businessChecks()->where('co_maker_id', $personId)->whereIn('id', $businessIds)->get();
        abort_unless($residenceChecks->count() === count($residenceIds) && $businessChecks->count() === count($businessIds), 404);

        DB::transaction(function () use ($request, $clientFolder, $residenceChecks, $businessChecks, $deleteResidenceCheck, $deleteBusinessCheck): void {
            foreach ($residenceChecks as $check) {
                $deleteResidenceCheck->execute($request->user(), $clientFolder, $check);
            }
            foreach ($businessChecks as $check) {
                $deleteBusinessCheck->execute($request->user(), $clientFolder, $check);
            }
        });

        return redirect()->route('client-folders.residence-business.edit', [$clientFolder] + ActivePersonResolver::queryParams($activePerson))
            ->with('status', 'Selected reports deleted successfully.')
            ->with('statusType', 'success');
    }

    public function preview(ClientFolder $clientFolder, OfficialReportDataBuilder $builder): View
    {
        Gate::authorize('view', $clientFolder);
        $activePerson = ActivePersonResolver::resolveFromQuery($clientFolder, request());
        $document = $builder->build($clientFolder, OfficialReportType::ResidenceBusinessPhoto, null, $activePerson);

        return view('reports.official.document', ['document' => $document, 'pdfMode' => false, 'clientFolder' => $clientFolder, 'type' => OfficialReportType::ResidenceBusinessPhoto, 'source' => null]);
    }
}
