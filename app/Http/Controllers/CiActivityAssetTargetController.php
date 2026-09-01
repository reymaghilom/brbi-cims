<?php

namespace App\Http\Controllers;

use App\Actions\ClientFolders\SaveCiActivityAssetTarget;
use App\Enums\ActivityStatus;
use App\Http\Requests\ClientFolders\StoreCiActivityAssetTargetRequest;
use App\Http\Requests\ClientFolders\UpdateCiActivityAssetTargetRequest;
use App\Models\ActivityDefinition;
use App\Models\CiActivity;
use App\Models\CiActivityAssetTarget;
use App\Models\ClientFolder;
use App\Services\ClientFolders\ActivePersonResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class CiActivityAssetTargetController extends Controller
{
    public function show(ClientFolder $clientFolder, CiActivity $ciActivity): View
    {
        Gate::authorize('view', $clientFolder);
        Gate::authorize('update', $ciActivity);
        $activePerson = ActivePersonResolver::resolveFromQuery($clientFolder, request());
        $this->assertExactAssetActivity($clientFolder, $ciActivity, $activePerson?->id);
        $ciActivity->load([
            'definition:id,name,code',
            'assetTargets' => fn ($query) => $query->with(['creator:id,full_name', 'updater:id,full_name'])->oldest('id'),
        ]);

        return view('client-folders.activities.asset-check-show', [
            'clientFolder' => $clientFolder,
            'activity' => $ciActivity,
            'activePerson' => $activePerson,
            'statuses' => ActivityStatus::cases(),
            'assessorTypes' => CiActivityAssetTarget::ASSESSOR_TYPES,
        ]);
    }

    public function store(StoreCiActivityAssetTargetRequest $request, ClientFolder $clientFolder, CiActivity $ciActivity, SaveCiActivityAssetTarget $save): RedirectResponse
    {
        $save->create($request->user(), $clientFolder, $ciActivity, $request->validated());

        return $this->redirectToDetail($clientFolder, $ciActivity, 'Assessor target added successfully.');
    }

    public function update(UpdateCiActivityAssetTargetRequest $request, ClientFolder $clientFolder, CiActivity $ciActivity, CiActivityAssetTarget $assetTarget, SaveCiActivityAssetTarget $save): RedirectResponse
    {
        $save->update($request->user(), $clientFolder, $ciActivity, $assetTarget, $request->validated());

        return $this->redirectToDetail($clientFolder, $ciActivity, 'Assessor target updated successfully.');
    }

    public function destroy(Request $request, ClientFolder $clientFolder, CiActivity $ciActivity, CiActivityAssetTarget $assetTarget, SaveCiActivityAssetTarget $save): RedirectResponse
    {
        $this->authorizeMutation($request, $clientFolder, $ciActivity, $assetTarget);
        $save->delete($request->user(), $clientFolder, $ciActivity, $assetTarget);

        return $this->redirectToDetail($clientFolder, $ciActivity, 'Assessor target deleted successfully.');
    }

    public function complete(Request $request, ClientFolder $clientFolder, CiActivity $ciActivity, CiActivityAssetTarget $assetTarget, SaveCiActivityAssetTarget $save): RedirectResponse
    {
        $this->authorizeMutation($request, $clientFolder, $ciActivity, $assetTarget);
        $save->complete($request->user(), $clientFolder, $ciActivity, $assetTarget);

        return $this->redirectToDetail($clientFolder, $ciActivity, 'Assessor target marked as completed.');
    }

    private function authorizeMutation(Request $request, ClientFolder $folder, CiActivity $activity, CiActivityAssetTarget $target): void
    {
        Gate::authorize('update', $folder);
        Gate::authorize('update', $activity);
        $validated = $request->validate(['co_maker_id' => ActivePersonResolver::rule($folder)]);
        $activePerson = ActivePersonResolver::resolve($folder, $validated['co_maker_id'] ?? null);
        $this->assertExactAssetActivity($folder, $activity, $activePerson?->id);
        abort_unless($target->ci_activity_id === $activity->id, 404);
    }

    private function assertExactAssetActivity(ClientFolder $folder, CiActivity $activity, ?int $coMakerId): void
    {
        abort_unless($activity->client_folder_id === $folder->id, 404);
        abort_unless($activity->co_maker_id === $coMakerId, 404);
        abort_unless($activity->definition()->where('code', ActivityDefinition::ASSET_CHECK_CODE)->exists(), 404);
    }

    private function redirectToDetail(ClientFolder $folder, CiActivity $activity, string $message): RedirectResponse
    {
        $activePerson = ActivePersonResolver::resolve($folder, $activity->co_maker_id);

        return redirect()->route(
            'client-folders.activities.asset-check.show',
            [$folder, $activity] + ActivePersonResolver::queryParams($activePerson),
        )->with('status', $message);
    }
}
