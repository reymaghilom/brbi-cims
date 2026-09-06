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
use App\Services\ClientFolders\CiActivityHistoryFeed;
use App\Services\ClientFolders\CiActivityScheduleSummary;
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
            'updater:id,full_name',
            'assetTargets' => fn ($query) => $query->with(['creator:id,full_name', 'updater:id,full_name'])->oldest('id'),
        ]);
        $newHistoryWatermark = session()->pull('ci_history_watermark');
        $newHistoryEntries = is_int($newHistoryWatermark)
            ? CiActivityHistoryFeed::renderHtml(CiActivityHistoryFeed::since($clientFolder, $newHistoryWatermark, $activePerson?->id))
            : [];
        $scheduleSummary = CiActivityScheduleSummary::fromCurrentTargets(
            $ciActivity->assetTargets->filter(fn (CiActivityAssetTarget $target): bool => $target->status === ActivityStatus::Scheduled && $target->scheduled_at !== null),
        );

        return view('client-folders.activities.asset-check-show', [
            'clientFolder' => $clientFolder,
            'activity' => $ciActivity,
            'activePerson' => $activePerson,
            'statuses' => ActivityStatus::cases(),
            'assessorTypes' => CiActivityAssetTarget::ASSESSOR_TYPES,
            'newHistoryEntries' => $newHistoryEntries,
            'scheduleSummary' => $scheduleSummary,
        ]);
    }

    public function store(StoreCiActivityAssetTargetRequest $request, ClientFolder $clientFolder, CiActivity $ciActivity, SaveCiActivityAssetTarget $save): RedirectResponse
    {
        $watermark = CiActivityHistoryFeed::watermark();
        $save->create($request->user(), $clientFolder, $ciActivity, $request->validated());

        return $this->redirectToDetail($clientFolder, $ciActivity, 'Assessor target added successfully.', $watermark);
    }

    public function update(UpdateCiActivityAssetTargetRequest $request, ClientFolder $clientFolder, CiActivity $ciActivity, CiActivityAssetTarget $assetTarget, SaveCiActivityAssetTarget $save): RedirectResponse
    {
        $watermark = CiActivityHistoryFeed::watermark();
        $save->update($request->user(), $clientFolder, $ciActivity, $assetTarget, $request->validated());

        return $this->redirectToDetail($clientFolder, $ciActivity, 'Assessor target updated successfully.', $watermark);
    }

    public function destroy(Request $request, ClientFolder $clientFolder, CiActivity $ciActivity, CiActivityAssetTarget $assetTarget, SaveCiActivityAssetTarget $save): RedirectResponse
    {
        $this->authorizeMutation($request, $clientFolder, $ciActivity, $assetTarget);
        $watermark = CiActivityHistoryFeed::watermark();
        $save->delete($request->user(), $clientFolder, $ciActivity, $assetTarget);

        return $this->redirectToDetail($clientFolder, $ciActivity, 'Assessor target deleted successfully.', $watermark);
    }

    public function complete(Request $request, ClientFolder $clientFolder, CiActivity $ciActivity, CiActivityAssetTarget $assetTarget, SaveCiActivityAssetTarget $save): RedirectResponse
    {
        $this->authorizeMutation($request, $clientFolder, $ciActivity, $assetTarget);
        $watermark = CiActivityHistoryFeed::watermark();
        $save->complete($request->user(), $clientFolder, $ciActivity, $assetTarget);

        return $this->redirectToDetail($clientFolder, $ciActivity, 'Assessor target marked as completed.', $watermark);
    }

    /**
     * Bulk "Mark Selected as Completed", the exact counterpart of
     * CiActivityBankTargetController::completeMany() — same validation shape, same per-target
     * completion action, same history watermark, so one Select All can never take a shortcut the
     * single-target route does not already allow.
     *
     * Every id is re-fetched through this activity's own relation and the count is compared, so a
     * forged id belonging to another activity, another person or another folder aborts the whole
     * request rather than silently completing a subset.
     */
    public function completeMany(Request $request, ClientFolder $clientFolder, CiActivity $ciActivity, SaveCiActivityAssetTarget $save): RedirectResponse
    {
        Gate::authorize('update', $clientFolder);
        Gate::authorize('update', $ciActivity);
        $validated = $request->validate([
            'co_maker_id' => ActivePersonResolver::rule($clientFolder),
            'asset_target_ids' => ['required', 'array', 'min:1'],
            'asset_target_ids.*' => ['integer'],
        ]);
        $activePerson = ActivePersonResolver::resolve($clientFolder, $validated['co_maker_id'] ?? null);
        $this->assertExactAssetActivity($clientFolder, $ciActivity, $activePerson?->id);

        $ids = array_values(array_unique(array_map('intval', $validated['asset_target_ids'])));
        $targets = $ciActivity->assetTargets()->whereKey($ids)->get();
        abort_unless($targets->count() === count($ids), 404);

        $watermark = CiActivityHistoryFeed::watermark();
        foreach ($targets as $target) {
            $save->complete($request->user(), $clientFolder, $ciActivity, $target);
        }

        return $this->redirectToDetail($clientFolder, $ciActivity, 'Selected assessor targets marked as completed.', $watermark);
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

    private function redirectToDetail(ClientFolder $folder, CiActivity $activity, string $message, int $historyWatermark): RedirectResponse
    {
        $activePerson = ActivePersonResolver::resolve($folder, $activity->co_maker_id);

        return redirect()->route(
            'client-folders.activities.asset-check.show',
            [$folder, $activity] + ActivePersonResolver::queryParams($activePerson),
        )->with('status', $message)->with('ci_history_watermark', $historyWatermark);
    }
}
