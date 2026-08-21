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
        $add->execute($request->user(), $clientFolder, $ciActivity, $request->validated());
        $personParams = ActivePersonResolver::queryParams($ciActivity->co_maker_id ? $clientFolder->coMakers()->find($ciActivity->co_maker_id) : null);

        return redirect()
            ->route('client-folders.activities.edit', [$clientFolder, $ciActivity] + $personParams)
            ->with('status', 'Activity note added successfully.');
    }
}
