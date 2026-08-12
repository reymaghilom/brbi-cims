<?php

namespace App\Http\Controllers;

use App\Actions\ClientFolders\CreateClientFolder;
use App\Http\Requests\ClientFolders\StoreClientFolderRequest;
use App\Models\ClientFolder;
use App\Services\ClientFolders\ClientFolderCreationOptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class ClientFolderController extends Controller
{
    public function create(ClientFolderCreationOptions $options): View
    {
        Gate::authorize('create', ClientFolder::class);

        $creditInvestigators = $options->creditInvestigatorsFor(request()->user());

        return view('client-folders.create', compact('creditInvestigators'));
    }

    public function store(StoreClientFolderRequest $request, CreateClientFolder $action): RedirectResponse|JsonResponse
    {
        $folder = $action->execute($request->user(), $request->validated());

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Client folder created successfully.',
                'folder' => [
                    'id' => $folder->id,
                    'display_name' => $folder->display_name,
                    'status' => $folder->status->value,
                    'open_url' => route('client-folders.show', $folder),
                ],
            ], 201);
        }

        return redirect()->route('client-folders.show', $folder)
            ->with('status', "Client folder {$folder->folder_number} created successfully.");
    }
}
