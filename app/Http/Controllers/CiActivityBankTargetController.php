<?php

namespace App\Http\Controllers;

use App\Actions\ClientFolders\SaveCiActivityBankTarget;
use App\Enums\ActivityStatus;
use App\Http\Requests\ClientFolders\StoreCiActivityBankTargetRequest;
use App\Http\Requests\ClientFolders\UpdateCiActivityBankTargetRequest;
use App\Models\ActivityDefinition;
use App\Models\CiActivity;
use App\Models\CiActivityBankTarget;
use App\Models\ClientFolder;
use App\Services\ClientFolders\ActivePersonResolver;
use App\Services\ClientFolders\BankInstitutionPrefill;
use App\Services\ClientFolders\CiActivityHistoryFeed;
use App\Services\ClientFolders\CiActivityScheduleSummary;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class CiActivityBankTargetController extends Controller
{
    public function show(
        ClientFolder $clientFolder,
        CiActivity $ciActivity,
        BankInstitutionPrefill $prefill,
    ): View {
        Gate::authorize('view', $clientFolder);
        Gate::authorize('update', $ciActivity);
        $activePerson = ActivePersonResolver::resolveFromQuery($clientFolder, request());
        $this->assertExactBankActivity($clientFolder, $ciActivity, $activePerson?->id);
        $ciActivity->load([
            'definition:id,name,code',
            'updater:id,full_name',
            'bankTargets' => fn ($query) => $query->with(['creator:id,full_name', 'updater:id,full_name'])->oldest('id'),
        ]);
        $newHistoryWatermark = session()->pull('ci_history_watermark');
        $newHistoryEntries = is_int($newHistoryWatermark)
            ? CiActivityHistoryFeed::renderHtml(CiActivityHistoryFeed::since($clientFolder, $newHistoryWatermark, $activePerson?->id))
            : [];
        $scheduleSummary = CiActivityScheduleSummary::fromCurrentTargets(
            $ciActivity->bankTargets->filter(fn (CiActivityBankTarget $target): bool => $target->status === ActivityStatus::Scheduled && $target->scheduled_at !== null),
        );

        return view('client-folders.activities.bank-coop-show', [
            'clientFolder' => $clientFolder,
            'activity' => $ciActivity,
            'activePerson' => $activePerson,
            'statuses' => ActivityStatus::cases(),
            'bankInstitutionPrefillCandidates' => $prefill->bankTargetsFromCibi(
                $clientFolder,
                $activePerson,
                $ciActivity->bankTargets,
            ),
            'newHistoryEntries' => $newHistoryEntries,
            'scheduleSummary' => $scheduleSummary,
        ]);
    }

    public function store(
        StoreCiActivityBankTargetRequest $request,
        ClientFolder $clientFolder,
        CiActivity $ciActivity,
        SaveCiActivityBankTarget $save,
    ): RedirectResponse {
        $watermark = CiActivityHistoryFeed::watermark();
        $save->create($request->user(), $clientFolder, $ciActivity, $request->validated());

        return $this->redirectToDetail($clientFolder, $ciActivity, 'Bank / Coop target added successfully.', $watermark);
    }

    public function update(
        UpdateCiActivityBankTargetRequest $request,
        ClientFolder $clientFolder,
        CiActivity $ciActivity,
        CiActivityBankTarget $bankTarget,
        SaveCiActivityBankTarget $save,
    ): RedirectResponse {
        $watermark = CiActivityHistoryFeed::watermark();
        $save->update($request->user(), $clientFolder, $ciActivity, $bankTarget, $request->validated());

        return $this->redirectToDetail($clientFolder, $ciActivity, 'Bank / Coop target updated successfully.', $watermark);
    }

    public function destroy(
        Request $request,
        ClientFolder $clientFolder,
        CiActivity $ciActivity,
        CiActivityBankTarget $bankTarget,
        SaveCiActivityBankTarget $save,
    ): RedirectResponse {
        Gate::authorize('update', $clientFolder);
        Gate::authorize('update', $ciActivity);
        $validated = $request->validate([
            'co_maker_id' => ActivePersonResolver::rule($clientFolder),
        ]);
        $activePerson = ActivePersonResolver::resolve($clientFolder, $validated['co_maker_id'] ?? null);
        $this->assertExactBankActivity($clientFolder, $ciActivity, $activePerson?->id);
        abort_unless($bankTarget->ci_activity_id === $ciActivity->id, 404);
        $watermark = CiActivityHistoryFeed::watermark();
        $save->delete($request->user(), $clientFolder, $ciActivity, $bankTarget);

        return $this->redirectToDetail($clientFolder, $ciActivity, 'Bank / Coop target deleted successfully.', $watermark);
    }

    public function complete(
        Request $request,
        ClientFolder $clientFolder,
        CiActivity $ciActivity,
        CiActivityBankTarget $bankTarget,
        SaveCiActivityBankTarget $save,
    ): RedirectResponse {
        Gate::authorize('update', $clientFolder);
        Gate::authorize('update', $ciActivity);
        $validated = $request->validate([
            'co_maker_id' => ActivePersonResolver::rule($clientFolder),
        ]);
        $activePerson = ActivePersonResolver::resolve($clientFolder, $validated['co_maker_id'] ?? null);
        $this->assertExactBankActivity($clientFolder, $ciActivity, $activePerson?->id);
        abort_unless($bankTarget->ci_activity_id === $ciActivity->id, 404);
        $watermark = CiActivityHistoryFeed::watermark();
        $save->complete($request->user(), $clientFolder, $ciActivity, $bankTarget);

        return $this->redirectToDetail($clientFolder, $ciActivity, 'Bank / Coop target marked as completed.', $watermark);
    }

    public function completeMany(
        Request $request,
        ClientFolder $clientFolder,
        CiActivity $ciActivity,
        SaveCiActivityBankTarget $save,
    ): RedirectResponse {
        Gate::authorize('update', $clientFolder);
        Gate::authorize('update', $ciActivity);
        $validated = $request->validate([
            'co_maker_id' => ActivePersonResolver::rule($clientFolder),
            'bank_target_ids' => ['required', 'array', 'min:1'],
            'bank_target_ids.*' => ['integer'],
        ]);
        $activePerson = ActivePersonResolver::resolve($clientFolder, $validated['co_maker_id'] ?? null);
        $this->assertExactBankActivity($clientFolder, $ciActivity, $activePerson?->id);

        $ids = array_values(array_unique(array_map('intval', $validated['bank_target_ids'])));
        $targets = $ciActivity->bankTargets()->whereKey($ids)->get();
        abort_unless($targets->count() === count($ids), 404);

        $watermark = CiActivityHistoryFeed::watermark();
        foreach ($targets as $target) {
            $save->complete($request->user(), $clientFolder, $ciActivity, $target);
        }

        return $this->redirectToDetail($clientFolder, $ciActivity, 'Selected bank checks marked as completed.', $watermark);
    }

    private function assertExactBankActivity(ClientFolder $folder, CiActivity $activity, ?int $coMakerId): void
    {
        abort_unless($activity->client_folder_id === $folder->id, 404);
        abort_unless($activity->co_maker_id === $coMakerId, 404);
        abort_unless($activity->definition()->where('code', ActivityDefinition::BANK_COOP_CHECK_CODE)->exists(), 404);
    }

    private function redirectToDetail(ClientFolder $folder, CiActivity $activity, string $message, int $historyWatermark): RedirectResponse
    {
        $activePerson = ActivePersonResolver::resolve($folder, $activity->co_maker_id);

        return redirect()->route(
            'client-folders.activities.bank-coop.show',
            [$folder, $activity] + ActivePersonResolver::queryParams($activePerson),
        )->with('status', $message)->with('ci_history_watermark', $historyWatermark);
    }
}
