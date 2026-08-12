<?php

namespace App\Http\Controllers;

use App\Actions\ClientFolders\RecycleClientFolder;
use App\Models\ClientFolder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ClientFolderRecycleController extends Controller
{
    public function destroy(Request $request, ClientFolder $clientFolder, RecycleClientFolder $action): RedirectResponse|JsonResponse
    {
        Gate::authorize('delete', $clientFolder);
        $action->execute($request->user(), $clientFolder);

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Client folder moved to the Recycle Bin.']);
        }

        return redirect()->route('client-folders.index')
            ->with('status', 'Client folder moved to the Recycle Bin.');
    }
}
