<?php

namespace App\Http\Controllers;

use App\Actions\ClientFolders\AddActivityNote;
use App\Http\Requests\ClientFolders\StoreActivityNoteRequest;
use App\Models\CiActivity;
use App\Models\ClientFolder;
use Illuminate\Http\RedirectResponse;

class ActivityNoteController extends Controller
{
    public function store(StoreActivityNoteRequest $request, ClientFolder $clientFolder, CiActivity $ciActivity, AddActivityNote $add): RedirectResponse
    {
        $add->execute($request->user(), $clientFolder, $ciActivity, $request->validated());

        return redirect()
            ->route('client-folders.activities.edit', [$clientFolder, $ciActivity])
            ->with('status', 'Activity note added successfully.');
    }
}
