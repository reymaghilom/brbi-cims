<?php

namespace App\Http\Controllers;

use App\Models\ClientFolder;
use App\Services\ClientFolders\ClientFolderBrowser;
use App\Services\ClientFolders\ClientFolderCreationOptions;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class ClientFolderLiveSearchController extends Controller
{
    public function __invoke(
        Request $request,
        ClientFolderBrowser $browser,
        ClientFolderCreationOptions $creationOptions,
    ): Response {
        Gate::authorize('viewAny', ClientFolder::class);

        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:150'],
            'context' => ['required', Rule::in(['dashboard', 'client_folders'])],
        ]);
        $filters = [
            'search' => filled($validated['search'] ?? null) ? trim($validated['search']) : null,
            'sort' => 'updated',
        ];
        $action = $validated['context'] === 'dashboard' ? route('home') : route('client-folders.index');
        $clientFolders = $browser->browse($request->user(), $filters);
        $clientFolders->setPath($action);

        return response(view('dashboard._folder-browser', [
            'clientFolders' => $clientFolders,
            'filters' => $filters,
            'folderBrowserAction' => $action,
            'folderBrowserContext' => $validated['context'],
            'creditInvestigators' => $creationOptions->creditInvestigatorsFor($request->user()),
        ])->render());
    }
}
