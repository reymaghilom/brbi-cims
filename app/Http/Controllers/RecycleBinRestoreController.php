<?php

namespace App\Http\Controllers;

use App\Actions\ClientFolders\RestoreClientFolder;
use App\Models\ClientFolder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

class RecycleBinRestoreController extends Controller
{
    public function update(ClientFolder $clientFolder, RestoreClientFolder $action): RedirectResponse
    {
        abort_unless($clientFolder->trashed(), 404);
        Gate::authorize('restore', $clientFolder);
        $action->execute(request()->user(), $clientFolder);

        return redirect()->route('client-folders.show', $clientFolder)
            ->with('status', 'Client folder restored successfully.');
    }
}
