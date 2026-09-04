<?php

namespace App\Http\Controllers;

use App\Models\ClientFolder;
use App\Services\Dashboard\DashboardData;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request, DashboardData $dashboard): View
    {
        Gate::authorize('viewAny', ClientFolder::class);

        // The only input this page takes is the trend window; DashboardData ignores anything that
        // is not one of its own range keys, so no unvalidated value ever reaches a query.
        return view('dashboard.index', $dashboard->for($request->user(), $request->query('range')));
    }
}
