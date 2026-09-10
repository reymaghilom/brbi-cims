<?php

namespace App\Http\Controllers;

use App\Actions\ClientFolders\AddActivityNote;
use App\Http\Requests\ClientFolders\StoreActivityNoteRequest;
use App\Models\CiActivity;
use App\Models\ClientFolder;
use App\Services\ClientFolders\ActivePersonResolver;
use Illuminate\Http\RedirectResponse;

class ActivityNoteController extends Controller
{
    public function store(StoreActivityNoteRequest $request, ClientFolder $clientFolder, CiActivity $ciActivity, AddActivityNote $add): RedirectResponse
    {
        $activePerson = ActivePersonResolver::resolveFromQuery($clientFolder, $request);
        ActivePersonResolver::assertOwnedBy($ciActivity, $activePerson);
        $add->execute($request->user(), $clientFolder, $ciActivity, $request->validated());
        $personParams = ActivePersonResolver::queryParams($activePerson);

        return redirect()
            ->route('client-folders.activities.edit', [$clientFolder, $ciActivity] + $personParams)
            ->with('status', 'Activity note added successfully.');
    }
}
