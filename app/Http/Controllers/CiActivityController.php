<?php

namespace App\Http\Controllers;

use App\Actions\ClientFolders\CreateCiActivity;
use App\Actions\ClientFolders\DeactivateActivityDefinition;
use App\Actions\ClientFolders\DeleteCiActivity;
use App\Actions\ClientFolders\SeedCiActivities;
use App\Actions\ClientFolders\SubmitCiActivities;
use App\Actions\ClientFolders\SubmitCiActivity;
use App\Actions\ClientFolders\UpdateCiActivity;
use App\Actions\Media\AddCiActivityProofPhotos;
use App\Actions\Media\RemoveCiActivityProof;
use App\Actions\Media\ReplaceCiActivityProof;
use App\Enums\ActivityStatus;
use App\Http\Requests\ClientFolders\ReplaceCiActivityProofRequest;
use App\Http\Requests\ClientFolders\StoreCiActivityProofRequest;
use App\Http\Requests\ClientFolders\StoreCiActivityRequest;
use App\Http\Requests\ClientFolders\SubmitCiActivitiesRequest;
use App\Http\Requests\ClientFolders\SubmitCiActivityRequest;
use App\Http\Requests\ClientFolders\UpdateCiActivityRequest;
use App\Models\ActivityDefinition;
use App\Models\AuditLog;
use App\Models\CiActivity;
use App\Models\CiActivityAssetTarget;
use App\Models\CiActivityBankTarget;
use App\Models\ClientFolder;
use App\Models\MediaReference;
use App\Services\ClientFolders\ActivePersonResolver;
use App\Services\ClientFolders\BankInstitutionPrefill;
use App\Services\ClientFolders\CiActivityHistoryFeed;
use App\Services\Media\EvidenceStorageRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\View\View;

class CiActivityController extends Controller
{
    /**
     * One-shot confirmation shown in the Add Activity modal right after a custom Activity Type
     * is created. Deliberately name-free: it belongs to that single creation event, never to
     * whichever Activity Type the user selects next.
     */
    public const ACTIVITY_TYPE_CREATED_MESSAGE = 'Activity type created successfully.';

    public function index(
        ClientFolder $clientFolder,
        BankInstitutionPrefill $prefill,
        SeedCiActivities $seedActivities,
    ): View {
        Gate::authorize('view', $clientFolder);
        $activePerson = ActivePersonResolver::resolveFromQuery($clientFolder, request());
        $seedActivities->execute($clientFolder, $activePerson, request()->user());

        $activities = $clientFolder->activities()
            ->where('ci_activities.co_maker_id', $activePerson?->id)
            ->join('activity_definitions', 'activity_definitions.id', '=', 'ci_activities.activity_definition_id')
            ->select('ci_activities.*')
            ->addSelect([
                'bank_remarks_preview' => CiActivityBankTarget::query()->select('remarks')
                    ->whereColumn('ci_activity_id', 'ci_activities.id')
                    ->whereNotNull('remarks')->where('remarks', '!=', '')
                    ->oldest('id')->limit(1),
                'asset_remarks_preview' => CiActivityAssetTarget::query()->select('remarks')
                    ->whereColumn('ci_activity_id', 'ci_activities.id')
                    ->whereNotNull('remarks')->where('remarks', '!=', '')
                    ->oldest('id')->limit(1),
            ])
            ->with([
                'definition:id,name,code,is_required,is_active,sort_order',
                'creator:id,full_name',
                'updater:id,full_name',
                'submitter:id,full_name',
                'assignedInvestigator:id,full_name',
                'mediaReferences' => fn ($query) => $query
                    ->select(['media_references.id', 'media_references.client_folder_id', 'media_references.co_maker_id', 'media_references.file_name', 'media_references.mime_type', 'media_references.media_type'])
                    ->where('media_references.client_folder_id', $clientFolder->id)
                    ->where('media_references.co_maker_id', $activePerson?->id)
                    ->oldest('activity_media.created_at'),
                // Presentation-only: the main table's Schedule column for Bank/Asset checks is
                // derived from these live, currently-Scheduled targets — never from the parent's
                // own scheduled_at, which intentionally stays NULL for these two activity types.
                'bankTargets' => fn ($query) => $query
                    ->where('status', ActivityStatus::Scheduled->value)
                    ->whereNotNull('scheduled_at')
                    ->select(['id', 'ci_activity_id', 'institution_name', 'branch_location', 'status', 'scheduled_at', 'scheduled_has_time']),
                'assetTargets' => fn ($query) => $query
                    ->where('status', ActivityStatus::Scheduled->value)
                    ->whereNotNull('scheduled_at')
                    ->select(['id', 'ci_activity_id', 'assessor_type', 'office_location', 'status', 'scheduled_at', 'scheduled_has_time']),
            ])
            ->withCount([
                'notes',
                'bankTargets',
                'bankTargets as completed_bank_targets_count' => fn ($query) => $query->where('status', ActivityStatus::Completed->value),
                'bankTargets as bank_remarks_count' => fn ($query) => $query->whereNotNull('remarks')->where('remarks', '!=', ''),
                'assetTargets',
                'assetTargets as completed_asset_targets_count' => fn ($query) => $query->where('status', ActivityStatus::Completed->value),
                'assetTargets as asset_remarks_count' => fn ($query) => $query->whereNotNull('remarks')->where('remarks', '!=', ''),
                'mediaReferences' => fn ($query) => $query
                    ->where('media_references.client_folder_id', $clientFolder->id)
                    ->where('media_references.co_maker_id', $activePerson?->id),
            ])
            ->orderBy('activity_definitions.sort_order')
            ->orderBy('ci_activities.created_at')
            ->get();
        $activities = $activities
            ->filter(fn (CiActivity $activity): bool => $activity->definition->is_active || $activity->definition->isCustom())
            ->values();

        $counts = [
            'pending' => $activities->where('status', ActivityStatus::Pending)->count(),
            'scheduled_today' => $activities->filter(fn (CiActivity $activity): bool => $activity->status === ActivityStatus::Scheduled && $activity->scheduled_at?->timezone(config('cims.display_timezone'))->isToday())->count(),
            'follow_up' => $activities->where('status', ActivityStatus::FollowUp)->count(),
            'completed' => $activities->where('status', ActivityStatus::Completed)->count(),
            'all' => $activities->count(),
        ];
        $filter = request()->query('status', 'all');
        if (! in_array($filter, array_keys($counts), true)) {
            $filter = 'all';
        }
        $visibleActivities = (match ($filter) {
            'pending' => $activities->where('status', ActivityStatus::Pending),
            'scheduled_today' => $activities->filter(fn (CiActivity $activity): bool => $activity->status === ActivityStatus::Scheduled && $activity->scheduled_at?->timezone(config('cims.display_timezone'))->isToday()),
            'follow_up' => $activities->where('status', ActivityStatus::FollowUp),
            'completed' => $activities->where('status', ActivityStatus::Completed),
            default => $activities,
        })->values();

        $proofMediaIds = DB::table('activity_media')
            ->whereIn('ci_activity_id', $activities->pluck('id'))
            ->pluck('media_reference_id');
        $proofNames = MediaReference::query()->whereKey($proofMediaIds)->pluck('file_name', 'id');
        $historyEvents = AuditLog::query()
            ->where('client_folder_id', $clientFolder->id)
            ->where(function ($query): void {
                $query
                    ->whereIn('module', ['ci_activities', 'activity_definitions'])
                    ->orWhere('action', 'media.uploaded');
            })
            ->with('user:id,full_name')
            ->latest('created_at')
            ->latest('id')
            ->get()
            ->filter(function (AuditLog $event) use ($activePerson, $proofMediaIds): bool {
                if ($event->module === 'activity_definitions') {
                    return true;
                }

                $metadata = (array) $event->metadata;
                if ($event->action === 'media.uploaded') {
                    return $proofMediaIds->contains((int) data_get($metadata, 'media_reference_id'));
                }

                return array_key_exists('co_maker_id', $metadata)
                    && $metadata['co_maker_id'] === $activePerson?->id;
            })
            ->map(fn (AuditLog $event): object => CiActivityHistoryFeed::mapUsingProofNames($event, $proofNames))
            ->values();
        $history = $historyEvents->take(5)->values();

        return view('client-folders.activities.index', [
            'clientFolder' => $clientFolder,
            'activities' => $activities,
            'visibleActivityIds' => $visibleActivities->pluck('id'),
            'existingDefinitionIds' => $activities->pluck('activity_definition_id')->unique(),
            'activePerson' => $activePerson,
            'bankInstitutionPrefillCandidates' => $prefill->bankTargetsFromCibi($clientFolder, $activePerson),
            'coMakers' => $clientFolder->coMakers()->oldest('id')->get(),
            'definitions' => ActivityDefinition::query()
                ->select(['id', 'name', 'code'])
                ->withCount('activities')
                ->where('is_active', true)
                ->where(function ($query): void {
                    $query
                        ->whereIn('code', [
                            ActivityDefinition::ASSET_CHECK_CODE,
                            ActivityDefinition::BANK_COOP_CHECK_CODE,
                        ])
                        ->orWhere('code', 'like', ActivityDefinition::CUSTOM_CODE_PREFIX.'%');
                })
                ->orderBy('sort_order')
                ->get(),
            // Activity Type Management list: the canonical/system types (always protected and
            // active) plus every user-created type, active or not, with an authoritative usage
            // count taken from the ActivityDefinition -> CiActivity relationship itself.
            'manageableDefinitions' => ActivityDefinition::query()
                ->select(['id', 'name', 'code', 'is_active', 'sort_order'])
                ->withCount('activities')
                ->where(function ($query): void {
                    $query
                        ->whereIn('code', [
                            ActivityDefinition::BARANGAY_CHECK_CODE,
                            ActivityDefinition::NEIGHBOR_CHECK_CODE,
                            ActivityDefinition::ASSET_CHECK_CODE,
                            ActivityDefinition::BANK_COOP_CHECK_CODE,
                        ])
                        ->orWhere('code', 'like', ActivityDefinition::CUSTOM_CODE_PREFIX.'%');
                })
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(),
            'counts' => $counts,
            'filter' => $filter,
            'history' => $history,
            'allHistory' => $historyEvents,
        ]);
    }

    public function store(StoreCiActivityRequest $request, ClientFolder $clientFolder, CreateCiActivity $create): RedirectResponse|JsonResponse
    {
        $validated = $request->validated();
        $activePerson = ActivePersonResolver::resolve($clientFolder, $validated['co_maker_id'] ?? null);
        $destination = route(
            'client-folders.activities.index',
            [$clientFolder] + ActivePersonResolver::queryParams($activePerson) + ['status' => 'all'],
        );

        if ($validated['create_new_activity_type']) {
            $definition = $create->createDefinition($request->user(), $clientFolder, $validated['new_activity_type']);
            $oldInput = [
                'activity_definition_id' => $definition->id,
                'status' => ActivityStatus::Pending->value,
            ];
            $message = self::ACTIVITY_TYPE_CREATED_MESSAGE;

            if ($request->expectsJson()) {
                $request->session()->flashInput($oldInput);
                $request->session()->flash('status', $message);
                $request->session()->flash('ci_activity_modal_open', true);

                return response()->json([
                    'activity_created' => false,
                    'redirect' => $destination,
                ]);
            }

            return redirect($destination)
                ->withInput($oldInput)
                ->with('status', $message)
                ->with('ci_activity_modal_open', true);
        }

        $create->execute($request->user(), $clientFolder, $validated);
        $message = 'Activity added successfully.';

        if ($request->expectsJson()) {
            $request->session()->flash('status', $message);

            return response()->json([
                'activity_created' => true,
                'redirect' => $destination,
            ]);
        }

        return redirect($destination)
            ->with('status', $message);
    }

    public function deactivateDefinition(
        Request $request,
        ClientFolder $clientFolder,
        ActivityDefinition $activityDefinition,
        DeactivateActivityDefinition $deactivate,
    ): RedirectResponse {
        Gate::authorize('update', $clientFolder);
        $validated = $request->validate([
            'co_maker_id' => ActivePersonResolver::rule($clientFolder),
            'status' => ['nullable', 'in:pending,scheduled_today,follow_up,completed,all'],
            'selected_activity_definition_id' => ['nullable', 'string', 'max:64'],
        ]);
        $activePerson = ActivePersonResolver::resolve($clientFolder, $validated['co_maker_id'] ?? null);
        $destination = route(
            'client-folders.activities.index',
            [$clientFolder] + ActivePersonResolver::queryParams($activePerson) + ['status' => $validated['status'] ?? 'all'],
        );
        $name = $activityDefinition->name;

        $result = $deactivate->execute($request->user(), $clientFolder, $activityDefinition);

        $selectedDefinitionId = $validated['selected_activity_definition_id'] ?? '';
        if ($selectedDefinitionId === (string) $activityDefinition->id) {
            $selectedDefinitionId = '';
        } elseif ($selectedDefinitionId !== ActivityDefinition::NEW_TYPE_VALUE) {
            $selectedDefinitionId = ctype_digit($selectedDefinitionId)
                && ActivityDefinition::query()->whereKey((int) $selectedDefinitionId)->where('is_active', true)->exists()
                    ? $selectedDefinitionId
                    : '';
        }

        return redirect($destination)
            ->withInput([
                'activity_definition_id' => $selectedDefinitionId,
                'create_new_activity_type' => $selectedDefinitionId === ActivityDefinition::NEW_TYPE_VALUE,
            ])
            ->with('status', $result === DeactivateActivityDefinition::RESULT_DELETED
                ? $name.' activity type permanently deleted.'
                : $name.' activity type removed.')
            ->with('ci_activity_modal_open', true);
    }

    public function edit(ClientFolder $clientFolder, CiActivity $ciActivity): View
    {
        Gate::authorize('update', $ciActivity);
        $activePerson = ActivePersonResolver::resolveFromQuery($clientFolder, request());
        ActivePersonResolver::assertOwnedBy($ciActivity, $activePerson);

        $ciActivity->load([
            'definition:id,name,code,is_required,is_active,sort_order',
            'updater:id,full_name',
            'creator:id,full_name',
            'assignedInvestigator:id,full_name',
            'notes' => fn ($query) => $query->with('author:id,full_name')->oldest('created_at'),
            'mediaReferences' => fn ($query) => $query->select('media_references.id', 'media_references.file_name', 'media_references.media_type', 'media_references.category', 'media_references.captured_at'),
        ])->loadCount('mediaReferences');

        return view('client-folders.activities.edit', [
            'clientFolder' => $clientFolder,
            'activity' => $ciActivity,
            'statuses' => ActivityStatus::cases(),
            'activePerson' => $activePerson,
        ]);
    }

    public function showDefaultCheck(ClientFolder $clientFolder, CiActivity $ciActivity): View
    {
        Gate::authorize('view', $clientFolder);
        Gate::authorize('update', $ciActivity);
        $activePerson = ActivePersonResolver::resolveFromQuery($clientFolder, request());
        ActivePersonResolver::assertOwnedBy($ciActivity, $activePerson);
        abort_unless($ciActivity->client_folder_id === $clientFolder->id, 404);

        $ciActivity->load([
            'definition:id,name,code',
            'updater:id,full_name',
            'creator:id,full_name',
        ]);
        abort_unless(ActivityDefinition::isMandatoryDefaultCode($ciActivity->definition->code), 404);

        return view('client-folders.activities.default-check-show', [
            'clientFolder' => $clientFolder,
            'activity' => $ciActivity,
            'statuses' => ActivityStatus::cases(),
            'activePerson' => $activePerson,
        ]);
    }

    /**
     * User-created (custom) activity types reuse the Barangay / Neighbor Check editing
     * experience: the same compact Status / Schedule / Time / Short Remarks form, opened
     * from the activities table in the shared edit modal.
     */
    public function showCustomCheck(ClientFolder $clientFolder, CiActivity $ciActivity): View
    {
        Gate::authorize('view', $clientFolder);
        Gate::authorize('update', $ciActivity);
        $activePerson = ActivePersonResolver::resolveFromQuery($clientFolder, request());
        ActivePersonResolver::assertOwnedBy($ciActivity, $activePerson);
        abort_unless($ciActivity->client_folder_id === $clientFolder->id, 404);

        $ciActivity->load([
            'definition:id,name,code',
            'updater:id,full_name',
            'creator:id,full_name',
        ]);
        abort_unless($ciActivity->definition?->isCustom() ?? false, 404);

        return view('client-folders.activities.default-check-show', [
            'clientFolder' => $clientFolder,
            'activity' => $ciActivity,
            'statuses' => ActivityStatus::cases(),
            'activePerson' => $activePerson,
        ]);
    }

    public function update(UpdateCiActivityRequest $request, ClientFolder $clientFolder, CiActivity $ciActivity, UpdateCiActivity $update): JsonResponse|RedirectResponse
    {
        $watermark = CiActivityHistoryFeed::watermark();
        $update->execute($request->user(), $clientFolder, $ciActivity, $request->validated());
        $activePerson = ActivePersonResolver::resolve($clientFolder, $request->validated('co_maker_id'));
        $personParams = ActivePersonResolver::queryParams($activePerson);

        if ($request->expectsJson()) {
            $history = CiActivityHistoryFeed::since($clientFolder, $watermark, $activePerson?->id);

            return response()->json(['updated' => true, 'history' => CiActivityHistoryFeed::renderHtml($history)]);
        }

        $destination = $request->string('intent')->toString() === 'return'
            ? route('client-folders.activities.index', [$clientFolder] + $personParams)
            : route('client-folders.activities.edit', [$clientFolder, $ciActivity] + $personParams);

        return redirect($destination)->with('status', 'CI activity saved successfully.');
    }

    public function replaceProof(
        ReplaceCiActivityProofRequest $request,
        ClientFolder $clientFolder,
        CiActivity $ciActivity,
        MediaReference $mediaReference,
        ReplaceCiActivityProof $replace,
        EvidenceStorageRecorder $storage,
    ): RedirectResponse|JsonResponse {
        ActivePersonResolver::assertOwnedBy($ciActivity, ActivePersonResolver::resolveFromQuery($clientFolder, $request));
        $watermark = CiActivityHistoryFeed::watermark();
        $storage->reset();
        $replace->execute($request->user(), $clientFolder, $ciActivity, $mediaReference, $request->file('attachment'));

        if ($request->expectsJson()) {
            $history = CiActivityHistoryFeed::since($clientFolder, $watermark, $ciActivity->co_maker_id);

            return response()->json([
                'replaced' => true,
                'history' => CiActivityHistoryFeed::renderHtml($history),
                'storage_provider' => $storage->provider(),
                'storage_label' => $storage->label(),
            ]);
        }

        $activePerson = ActivePersonResolver::resolve($clientFolder, $ciActivity->co_maker_id);

        return redirect()->route(
            'client-folders.activities.edit',
            [$clientFolder, $ciActivity] + ActivePersonResolver::queryParams($activePerson),
        )->with('status', trim('Supporting Proof replaced successfully.'.($storage->label() === null ? '' : ' New file saved to '.$storage->label().'.')));
    }

    public function storeProof(
        StoreCiActivityProofRequest $request,
        ClientFolder $clientFolder,
        CiActivity $ciActivity,
        AddCiActivityProofPhotos $addPhotos,
        EvidenceStorageRecorder $storage,
    ): RedirectResponse|JsonResponse {
        ActivePersonResolver::assertOwnedBy($ciActivity, ActivePersonResolver::resolveFromQuery($clientFolder, $request));
        $watermark = CiActivityHistoryFeed::watermark();
        $storage->reset();
        $photos = $request->file('photos', []);
        $addPhotos->execute($request->user(), $clientFolder, $ciActivity, is_array($photos) ? $photos : []);

        if ($request->expectsJson()) {
            $history = CiActivityHistoryFeed::since($clientFolder, $watermark, $ciActivity->co_maker_id);

            return response()->json([
                'added' => true,
                'history' => CiActivityHistoryFeed::renderHtml($history),
                'storage_provider' => $storage->provider(),
                'storage_label' => $storage->label(),
            ]);
        }

        $activePerson = ActivePersonResolver::resolve($clientFolder, $ciActivity->co_maker_id);

        return redirect()->route(
            'client-folders.activities.edit',
            [$clientFolder, $ciActivity] + ActivePersonResolver::queryParams($activePerson),
        )->with('status', trim('Supporting Proof uploaded successfully.'.($storage->label() === null ? '' : ' Files saved to '.$storage->label().'.')));
    }

    public function removeProof(
        Request $request,
        ClientFolder $clientFolder,
        CiActivity $ciActivity,
        MediaReference $mediaReference,
        RemoveCiActivityProof $remove,
    ): RedirectResponse|JsonResponse {
        Gate::authorize('update', $clientFolder);
        Gate::authorize('update', $ciActivity);
        ActivePersonResolver::assertOwnedBy($ciActivity, ActivePersonResolver::resolveFromQuery($clientFolder, $request));
        abort_unless($ciActivity->client_folder_id === $clientFolder->id, 404);
        abort_unless($mediaReference->client_folder_id === $clientFolder->id, 404);
        abort_unless($mediaReference->co_maker_id === $ciActivity->co_maker_id, 404);
        abort_unless($ciActivity->mediaReferences()->whereKey($mediaReference->id)->exists(), 404);

        $watermark = CiActivityHistoryFeed::watermark();
        $remove->execute($request->user(), $clientFolder, $ciActivity, $mediaReference);

        if ($request->expectsJson()) {
            $history = CiActivityHistoryFeed::since($clientFolder, $watermark, $ciActivity->co_maker_id);

            return response()->json(['removed' => true, 'history' => CiActivityHistoryFeed::renderHtml($history)]);
        }

        $activePerson = ActivePersonResolver::resolve($clientFolder, $ciActivity->co_maker_id);

        return redirect()->route(
            'client-folders.activities.edit',
            [$clientFolder, $ciActivity] + ActivePersonResolver::queryParams($activePerson),
        )->with('status', 'Proof attachment removed successfully.');
    }

    public function destroy(Request $request, ClientFolder $clientFolder, CiActivity $ciActivity, DeleteCiActivity $delete): RedirectResponse
    {
        Gate::authorize('update', $clientFolder);
        Gate::authorize('update', $ciActivity);
        $activePerson = ActivePersonResolver::resolve($clientFolder, $request->input('co_maker_id'));
        ActivePersonResolver::assertOwnedBy($ciActivity, $activePerson);
        $successMessage = $ciActivity->name.' activity deleted.';
        $destination = route(
            'client-folders.activities.index',
            [$clientFolder] + ActivePersonResolver::queryParams($activePerson) + ['status' => 'all'],
        );

        $delete->execute($request->user(), $clientFolder, $ciActivity);

        return redirect($destination)->with('status', $successMessage);
    }

    public function submit(
        SubmitCiActivityRequest $request,
        ClientFolder $clientFolder,
        CiActivity $ciActivity,
        SubmitCiActivity $submit,
    ): RedirectResponse|JsonResponse {
        $validated = $request->validated();
        $activePerson = ActivePersonResolver::resolve($clientFolder, $validated['co_maker_id'] ?? null);
        ActivePersonResolver::assertOwnedBy($ciActivity, $activePerson);
        $destination = route(
            'client-folders.activities.index',
            [$clientFolder] + ActivePersonResolver::queryParams($activePerson) + ['status' => 'completed'],
        );

        $watermark = CiActivityHistoryFeed::watermark();
        $submit->execute($request->user(), $clientFolder, $ciActivity, $validated);

        if ($request->expectsJson()) {
            $ciActivity->refresh()->load([
                'mediaReferences' => fn ($query) => $query
                    ->where('media_references.client_folder_id', $clientFolder->id)
                    ->where('media_references.co_maker_id', $ciActivity->co_maker_id)
                    ->oldest('activity_media.created_at'),
            ]);
            $attachmentCount = $ciActivity->mediaReferences->count();
            $singleAttachment = $attachmentCount === 1 ? $ciActivity->mediaReferences->first() : null;
            $singleAttachmentIsPreviewable = $singleAttachment
                && (Str::startsWith($singleAttachment->mime_type, 'image/') || Str::startsWith($singleAttachment->mime_type, 'video/'));
            $history = CiActivityHistoryFeed::since($clientFolder, $watermark, $activePerson?->id);

            return response()->json([
                'submitted' => true,
                'cell' => view('client-folders.activities.partials.submission-cell', [
                    'activity' => $ciActivity,
                    'clientFolder' => $clientFolder,
                    'personParams' => $personParams,
                    'attachmentCount' => $attachmentCount,
                    'singleAttachment' => $singleAttachment,
                    'singleAttachmentIsPreviewable' => $singleAttachmentIsPreviewable,
                ])->render(),
                'history' => CiActivityHistoryFeed::renderHtml($history),
            ]);
        }

        return redirect($destination)->with('status', $ciActivity->name.' submission recorded.');
    }

    public function submitBatch(SubmitCiActivitiesRequest $request, ClientFolder $clientFolder, SubmitCiActivities $submitBatch): RedirectResponse
    {
        $validated = $request->validated();
        $activePerson = ActivePersonResolver::resolve($clientFolder, $validated['co_maker_id'] ?? null);
        $activities = $clientFolder->activities()->whereKey($validated['activity_ids'])->get();
        $proofs = $request->file('proofs', []);

        $submitBatch->execute(
            $request->user(),
            $clientFolder,
            $activePerson,
            $activities,
            is_array($proofs) ? $proofs : [],
            $validated['submitted_to'] ?? null,
            $validated['submission_note'] ?? null,
        );

        $destination = route(
            'client-folders.activities.index',
            [$clientFolder] + ActivePersonResolver::queryParams($activePerson) + ['status' => 'completed'],
        );

        return redirect($destination)->with('status', 'Selected CI activities submitted to the Credit Analyst.');
    }

    public function bulkDestroy(Request $request, ClientFolder $clientFolder, DeleteCiActivity $delete): RedirectResponse
    {
        Gate::authorize('update', $clientFolder);
        $validated = $request->validate([
            'co_maker_id' => ActivePersonResolver::rule($clientFolder),
            'activity_ids' => ['required', 'array', 'min:1'],
            'activity_ids.*' => ['required', 'integer', 'distinct'],
            'status' => ['nullable', 'in:pending,scheduled_today,follow_up,completed,all'],
        ]);
        $activePerson = ActivePersonResolver::resolve($clientFolder, $validated['co_maker_id'] ?? null);
        $activityIds = collect($validated['activity_ids'])->map(fn (mixed $id): int => (int) $id)->values();
        $activities = $clientFolder->activities()
            ->where('co_maker_id', $activePerson?->id)
            ->whereKey($activityIds)
            ->with('definition:id,name,code')
            ->get();
        abort_unless($activities->count() === $activityIds->count(), 404);
        $filter = $validated['status'] ?? 'all';
        abort_unless($activities->every(fn (CiActivity $activity): bool => match ($filter) {
            'pending' => $activity->status === ActivityStatus::Pending,
            'scheduled_today' => $activity->status === ActivityStatus::Scheduled
                && $activity->scheduled_at?->timezone(config('cims.display_timezone'))->isToday(),
            'follow_up' => $activity->status === ActivityStatus::FollowUp,
            'completed' => $activity->status === ActivityStatus::Completed,
            default => true,
        }), 404);
        $activities->each(fn (CiActivity $activity) => Gate::authorize('update', $activity));

        $count = $activities->count();
        $redirectParameters = ['clientFolder' => $clientFolder->getRouteKey()]
            + ActivePersonResolver::queryParams($activePerson)
            + ['status' => $filter];
        $destination = route(
            'client-folders.activities.index',
            $redirectParameters,
        );
        $successMessage = $count.' '.str('activity')->plural($count).' permanently deleted.';
        $delete->executeMany($request->user(), $clientFolder, $activities);

        return redirect()->to($destination)->with('status', $successMessage);
    }
}
