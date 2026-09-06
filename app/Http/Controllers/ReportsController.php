<?php

namespace App\Http\Controllers;

use App\Http\Requests\Reports\BrowseGlobalReportsRequest;
use App\Models\ClientFolder;
use App\Models\IncomeSourceTemplate;
use App\Services\Reports\ReportWorkspaceQuery;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * The global Reports workspace: one central work queue and completed-report library across every
 * Client Folder this user may access, so a CI never has to open folder after folder to find out
 * which report still needs work.
 *
 * It is a read surface only. Every row is derived from the four report-capable modules' own
 * authoritative records (see ReportWorkspaceQuery), and every action links back into that module's
 * existing workflow — nothing here generates, writes or mutates anything.
 */
class ReportsController extends Controller
{
    public function __invoke(BrowseGlobalReportsRequest $request, ReportWorkspaceQuery $workspace): View
    {
        Gate::authorize('viewAny', ClientFolder::class);

        $filters = $request->safe()->only(['search', 'client_folder_id', 'report_type', 'person', 'tab', 'from', 'to', 'sort', 'direction']);
        $filters['tab'] = $filters['tab'] ?? 'all';
        $user = $request->user();

        $data = [
            'items' => $workspace->paginate($user, $filters),
            'filters' => $filters,
            'sort' => $filters['sort'] ?? null,
            'direction' => ($filters['direction'] ?? null) === 'desc' ? 'desc' : 'asc',
        ];

        // A sort or pagination click asks for the listing alone, so the header, KPIs and toolbar
        // are neither re-rendered nor re-counted. Only a save asks for the counts too (they are
        // deliberately unfiltered, so nothing else can change them) via X-Reports-Summary.
        if ($request->ajax()) {
            if ($request->hasHeader('X-Reports-Summary')) {
                $data['summary'] = $workspace->summary($user);
            }

            return view('reports._fragment', $data);
        }

        return view('reports.index', $data + [
            'summary' => $workspace->summary($user),
            'reportTypes' => ReportWorkspaceQuery::KINDS,
            'businessTemplates' => IncomeSourceTemplate::query()
                ->activeBusiness()
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(['id', 'name']),
        ]);
    }
}
