<?php

namespace App\Http\Controllers;

use App\Models\ClientFolder;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class RecycleBinController extends Controller
{
    public function __invoke(): View
    {
        Gate::authorize('viewAny', ClientFolder::class);

        $clientFolders = ClientFolder::onlyTrashed()
            ->accessibleTo(request()->user())
            ->with(['assignedInvestigator:id,full_name', 'deletedBy:id,full_name'])
            ->latest('deleted_at')
            ->paginate(15);

        return view('recycle-bin.index', compact('clientFolders'));
    }
}
