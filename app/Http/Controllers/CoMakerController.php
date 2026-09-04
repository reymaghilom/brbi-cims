<?php

namespace App\Http\Controllers;

use App\Actions\ClientFolders\RemoveCoMaker;
use App\Actions\ClientFolders\SaveCoMaker;
use App\Exceptions\NoChangesDetectedException;
use App\Http\Requests\ClientFolders\SaveCoMakerRequest;
use App\Models\ClientFolder;
use App\Models\CoMaker;
use App\Services\ClientFolders\ActivePersonResolver;
use App\Services\ClientFolders\ClientFolderOverview;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class CoMakerController extends Controller
{
    public function store(SaveCoMakerRequest $request, ClientFolder $clientFolder, SaveCoMaker $action, ClientFolderOverview $overview): RedirectResponse|JsonResponse
    {
        $isEdit = filled($request->validated('co_maker_id'));

        try {
            $coMaker = $action->execute($request->user(), $clientFolder, $request->validated());
        } catch (NoChangesDetectedException $e) {
            if ($request->expectsJson()) {
                return response()->json(['message' => $e->getMessage(), 'no_change' => true]);
            }

            return redirect()->route('client-folders.show', $clientFolder)
                ->with('status', $e->getMessage())->with('statusType', 'info');
        }

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
            ] + $this->folderContentsFragments($request, $clientFolder, $overview));
        }

        return redirect()->route('client-folders.show', $clientFolder)->with('status', $message);
    }

    public function destroy(Request $request, ClientFolder $clientFolder, CoMaker $coMaker, RemoveCoMaker $action, ClientFolderOverview $overview): RedirectResponse|JsonResponse
    {
        Gate::authorize('update', $clientFolder);

        $action->execute($request->user(), $clientFolder, $coMaker);
        $message = 'Co-Maker removed successfully.';

        if ($request->expectsJson()) {
            return response()->json(['message' => $message] + $this->folderContentsFragments($request, $clientFolder, $overview));
        }

        return redirect()->route('client-folders.show', $clientFolder)->with('status', $message);
    }

    /**
     * Client Folder Contents' own person-switch tabs (Applicant/Co-Maker strip) and Recent
     * Activity panel AUTO-UPDATE from this exact same authoritative response — no second GET.
     * "Active" person for re-rendering the tabs/highlight state is resolved from the currently
     * open Folder Contents page's own query string (carried by the caller onto this request, see
     * the [data-co-maker-form]/[data-co-maker-remove-form] submit handlers in app.js), never
     * guessed from the co-maker record that was just added/edited/removed — that person is not
     * necessarily who the CI is currently viewing.
     */
    private function folderContentsFragments(Request $request, ClientFolder $clientFolder, ClientFolderOverview $overview): array
    {
        $clientFolder->refresh();
        // The co-maker referenced by the page's own ?co_maker_id could be the one just removed
        // (ModelNotFoundException) — the caller already detects that exact case client-side and
        // navigates away instead of applying these fragments, so falling back to the Applicant
        // view here is only ever a safety net, never the value actually used in that scenario.
        try {
            $activeCoMaker = ActivePersonResolver::resolveFromQuery($clientFolder, $request);
        } catch (ModelNotFoundException) {
            $activeCoMaker = null;
        }

        return [
            'person_switch_html' => view('client-folders.partials.person-switch', [
                'clientFolder' => $clientFolder,
                'coMakers' => $clientFolder->coMakers,
                'activeCoMaker' => $activeCoMaker,
                'canManageCoMakers' => $request->user()->can('update', $clientFolder),
            ])->render(),
            'recent_activity_html' => view('client-folders.partials.recent-activity-body', [
                'recentPersonActivity' => $overview->recentPersonActivity($clientFolder, $activeCoMaker),
                'coMakers' => $clientFolder->coMakers,
                'viewingLabel' => $activeCoMaker ? 'Co-Maker — '.mb_strtoupper($activeCoMaker->full_name) : 'Applicant',
                'displayTimezone' => config('cims.display_timezone'),
            ])->render(),
        ];
    }
}
