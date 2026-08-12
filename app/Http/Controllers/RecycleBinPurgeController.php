<?php

namespace App\Http\Controllers;

use App\Actions\ClientFolders\PurgeClientFolder;
use App\Http\Requests\ClientFolders\PurgeClientFolderRequest;
use App\Models\ClientFolder;
use Illuminate\Http\RedirectResponse;

class RecycleBinPurgeController extends Controller
{
    public function destroy(PurgeClientFolderRequest $request, ClientFolder $clientFolder, PurgeClientFolder $action): RedirectResponse
    {
        abort_unless($clientFolder->trashed(), 404);
        $action->execute($request->user(), $clientFolder);

        return redirect()->route('recycle-bin.index')
            ->with('status', 'Client folder permanently deleted.');
    }
}
