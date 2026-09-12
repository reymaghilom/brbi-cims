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

        $data = $dashboard->for(
            $request->user(),
            $request->query('range'),
            $request->query('work_page'),
        );

        if ($request->ajax() && ! $request->header('X-Dashboard-Refresh')) {
            return view('dashboard._work-today', $data);
        }

        return view('dashboard.index', $data);
    }
}
