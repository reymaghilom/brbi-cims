<?php

namespace App\Http\Controllers;

use App\Actions\ClientFolders\RestoreBusinessPair;
use App\Models\ClientFolder;
use App\Models\IncomeSource;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

class RecycleBinBusinessRestoreController extends Controller
{
    public function update(IncomeSource $incomeSource, RestoreBusinessPair $action): RedirectResponse
    {
        abort_unless($incomeSource->trashed(), 404);
        $clientFolder = ClientFolder::query()->findOrFail($incomeSource->client_folder_id);
        Gate::authorize('restore', $incomeSource);
        $action->execute(request()->user(), $clientFolder, $incomeSource);

        return redirect()->route('recycle-bin.index')
            ->with('status', 'Business Report and linked Business Check restored successfully.');
    }
}
