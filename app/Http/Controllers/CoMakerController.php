<?php

namespace App\Http\Controllers;

use App\Actions\ClientFolders\RemoveCoMaker;
use App\Actions\ClientFolders\SaveCoMaker;
use App\Http\Requests\ClientFolders\SaveCoMakerRequest;
use App\Models\ClientFolder;
use App\Models\CoMaker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class CoMakerController extends Controller
{
    public function store(SaveCoMakerRequest $request, ClientFolder $clientFolder, SaveCoMaker $action): RedirectResponse|JsonResponse
    {
        $isEdit = filled($request->validated('co_maker_id'));
        $coMaker = $action->execute($request->user(), $clientFolder, $request->validated());
        $message = $isEdit ? 'Co-Maker updated successfully.' : 'Co-Maker added successfully.';

        if ($request->expectsJson()) {
            return response()->json([
                'message' => $message,
                'coMaker' => [
                    'id' => $coMaker->id,
                    'full_name' => $coMaker->full_name,
                    'first_name' => $coMaker->first_name,
                    'middle_name' => $coMaker->middle_name,
                    'last_name' => $coMaker->last_name,
                    'suffix' => $coMaker->suffix,
                ],
            ]);
        }

        return redirect()->route('client-folders.show', $clientFolder)->with('status', $message);
    }

    public function destroy(Request $request, ClientFolder $clientFolder, CoMaker $coMaker, RemoveCoMaker $action): RedirectResponse|JsonResponse
    {
        Gate::authorize('update', $clientFolder);

        $action->execute($request->user(), $clientFolder, $coMaker);
        $message = 'Co-Maker removed successfully.';

        if ($request->expectsJson()) {
            return response()->json(['message' => $message]);
        }

        return redirect()->route('client-folders.show', $clientFolder)->with('status', $message);
    }
}
