<?php

namespace App\Http\Controllers;

use App\Actions\ClientFolders\UpdateCiActivity;
use App\Enums\ActivityStatus;
use App\Http\Requests\ClientFolders\UpdateCiActivityRequest;
use App\Models\CiActivity;
use App\Models\ClientFolder;
use App\Services\ClientFolders\ActivePersonResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class CiActivityController extends Controller
{
    public function index(ClientFolder $clientFolder): View
    {
        Gate::authorize('view', $clientFolder);
        $activePerson = ActivePersonResolver::resolveFromQuery($clientFolder, request());

        $activities = $clientFolder->activities()
            ->where('ci_activities.co_maker_id', $activePerson?->id)
            ->join('activity_definitions', 'activity_definitions.id', '=', 'ci_activities.activity_definition_id')
            ->select('ci_activities.*')
            ->with(['definition:id,name,code,is_required,is_active,sort_order', 'updater:id,full_name'])
            ->withCount(['notes', 'mediaReferences'])
            ->orderBy('activity_definitions.sort_order')
            ->get();

        return view('client-folders.activities.index', compact('clientFolder', 'activities', 'activePerson'));
    }

    public function edit(ClientFolder $clientFolder, CiActivity $ciActivity): View
    {
        Gate::authorize('update', $ciActivity);
        $activePerson = ActivePersonResolver::resolveFromQuery($clientFolder, request());
        ActivePersonResolver::assertOwnedBy($ciActivity, $activePerson);

        $ciActivity->load([
            'definition:id,name,code,is_required,is_active,sort_order',
            'updater:id,full_name',
            'notes' => fn ($query) => $query->with('author:id,full_name')->oldest('created_at'),
            'mediaReferences' => fn ($query) => $query->select('media_references.id', 'media_references.file_name', 'media_references.media_type', 'media_references.category', 'media_references.captured_at'),
        ])->loadCount('mediaReferences');

        return view('client-folders.activities.edit', [
            'clientFolder' => $clientFolder,
            'activity' => $ciActivity,
            'statuses' => ActivityStatus::cases(),
            'defaultVisitedBy' => $ciActivity->visited_by ?: request()->user()->full_name,
            'activePerson' => $activePerson,
        ]);
    }

    public function update(UpdateCiActivityRequest $request, ClientFolder $clientFolder, CiActivity $ciActivity, UpdateCiActivity $update): RedirectResponse
    {
        $update->execute($request->user(), $clientFolder, $ciActivity, $request->validated());
        $activePerson = ActivePersonResolver::resolve($clientFolder, $request->validated('co_maker_id'));
        $personParams = ActivePersonResolver::queryParams($activePerson);

        $destination = $request->string('intent')->toString() === 'return'
            ? route('client-folders.activities.index', [$clientFolder] + $personParams)
            : route('client-folders.activities.edit', [$clientFolder, $ciActivity] + $personParams);

        return redirect($destination)->with('status', 'CI activity saved successfully.');
    }
}
