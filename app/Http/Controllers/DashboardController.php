<?php

namespace App\Http\Controllers;

use App\Http\Requests\ClientFolders\BrowseClientFoldersRequest;
use App\Models\ClientFolder;
use App\Services\Dashboard\DashboardData;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(BrowseClientFoldersRequest $request, DashboardData $dashboard): View
    {
        Gate::authorize('viewAny', ClientFolder::class);

        $filters = $request->safe()->only(['search', 'status', 'sort']);

        return view('dashboard.index', $dashboard->for($request->user(), $filters));
    }
}
