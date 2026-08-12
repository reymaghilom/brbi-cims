<?php

namespace App\Http\Controllers;

use App\Http\Requests\ClientFolders\BrowseClientFoldersRequest;
use App\Models\ClientFolder;
use App\Models\IncomeSource;
use App\Services\ClientFolders\CibiReportFormData;
use App\Services\ClientFolders\ClientFolderBrowser;
use App\Services\ClientFolders\ClientFolderCreationOptions;
use App\Services\ClientFolders\ClientFolderOverview;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class ClientFolderAccessController extends Controller
{
    public function index(
        BrowseClientFoldersRequest $request,
        ClientFolderBrowser $browser,
        ClientFolderCreationOptions $creationOptions,
    ): View {
        Gate::authorize('viewAny', ClientFolder::class);

        $filters = $request->safe()->only(['search', 'status', 'sort']);

        $clientFolders = $browser->browse($request->user(), $filters);

        return view('client-folders.index', [
            'clientFolders' => $clientFolders,
            'filters' => $filters,
            'creditInvestigators' => $creationOptions->creditInvestigatorsFor($request->user()),
        ]);
    }

    public function show(ClientFolder $clientFolder, ClientFolderOverview $overview, CibiReportFormData $cibiFormData): View
    {
        Gate::authorize('view', $clientFolder);

        $data = $overview->for($clientFolder);

        return view('client-folders.show', $data + $cibiFormData->for($data['clientFolder']));
    }

    public function showIncomeSource(ClientFolder $clientFolder, IncomeSource $incomeSource): View
    {
        Gate::authorize('view', $clientFolder);
        Gate::authorize('view', $incomeSource);

        return view('client-folders.income-source-authorization-show', compact('clientFolder', 'incomeSource'));
    }
}
