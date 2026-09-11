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
 * and defers to PurgeClientFolder for the owned-graph removal plus its existing
 * file/external-reference safety block.
 */
class ClientFolderDeleteController extends Controller
{
    public function destroy(Request $request, ClientFolder $clientFolder, PurgeClientFolder $action): RedirectResponse|JsonResponse
    {
        Gate::authorize('forceDelete', $clientFolder);

        try {
            $action->execute($request->user(), $clientFolder);
        } catch (ValidationException $exception) {
            // PurgeClientFolder blocks folders that still carry file/external-integration
            // references. Surface that as a plain error for the dashboard's fetch-based delete,
            // which has no field-level error rendering for this form.
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
