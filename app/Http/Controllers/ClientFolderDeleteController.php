<?php

namespace App\Http\Controllers;

use App\Actions\ClientFolders\PurgeClientFolder;
use App\Models\ClientFolder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Canonical (and only) Client Folder delete path. Deleting a folder is permanent: there is no
 * Recycle Bin to restore it from, so this authorizes the explicit 'forceDelete' policy ability
 * and defers to PurgeClientFolder, which only removes a folder that is still empty (no saved
 * records) and has no other user's unsaved work in progress.
 */
class ClientFolderDeleteController extends Controller
{
    public function destroy(Request $request, ClientFolder $clientFolder, PurgeClientFolder $action): RedirectResponse|JsonResponse
    {
        Gate::authorize('forceDelete', $clientFolder);

        try {
            $action->execute($request->user(), $clientFolder);
        } catch (ValidationException $exception) {
            // PurgeClientFolder refuses folders that already contain saved records (or have another
            // user's unsaved work). Surface that as a plain message for the dashboard's fetch-based
            // delete, which renders it inside the delete dialog.
            if ($request->expectsJson()) {
                return response()->json(['message' => $exception->validator->errors()->first()], 422);
            }

            throw $exception;
        }

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Client folder permanently deleted.']);
        }

        return redirect()->route('client-folders.index')
            ->with('status', 'Client folder permanently deleted.');
    }
}
