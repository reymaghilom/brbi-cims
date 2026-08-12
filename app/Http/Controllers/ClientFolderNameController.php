<?php

namespace App\Http\Controllers;

use App\Actions\ClientFolders\RenameClientFolder;
use App\Http\Requests\ClientFolders\RenameClientFolderRequest;
use App\Models\ClientFolder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class ClientFolderNameController extends Controller
{
    public function edit(ClientFolder $clientFolder): View
    {
        Gate::authorize('update', $clientFolder);

        return view('client-folders.edit-name', compact('clientFolder'));
    }

    public function update(RenameClientFolderRequest $request, ClientFolder $clientFolder, RenameClientFolder $action): RedirectResponse|JsonResponse
    {
        $action->execute($request->user(), $clientFolder, $request->validated('display_name'));

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Client folder renamed successfully.',
                'folder' => ['display_name' => $clientFolder->fresh()->display_name],
            ]);
        }

        return redirect()->route('client-folders.show', $clientFolder)
            ->with('status', 'Client folder renamed successfully.');
    }
}
