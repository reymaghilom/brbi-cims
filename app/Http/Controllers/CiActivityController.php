<?php

namespace App\Http\Controllers;

use App\Actions\ClientFolders\CreateCiActivity;
use App\Actions\ClientFolders\DeactivateActivityDefinition;
use App\Actions\ClientFolders\DeleteCiActivity;
use App\Actions\ClientFolders\SubmitCiActivity;
use App\Actions\ClientFolders\UpdateCiActivity;
use App\Enums\ActivityStatus;
use App\Http\Requests\ClientFolders\StoreCiActivityRequest;
use App\Http\Requests\ClientFolders\SubmitCiActivityRequest;
use App\Http\Requests\ClientFolders\UpdateCiActivityRequest;
use App\Models\ActivityDefinition;
use App\Models\AuditLog;
use App\Models\CiActivity;
use App\Models\ClientFolder;
use App\Models\MediaReference;
use App\Services\ClientFolders\ActivePersonResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
            ])
            ->withCount([
                'notes',
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
                $query->where('module', 'ci_activities')->orWhere('action', 'media.uploaded');
            })
            ->with('user:id,full_name')
            ->latest('created_at')
            ->latest('id')
            ->get()
            ->filter(function (AuditLog $event) use ($activePerson, $proofMediaIds): bool {
                $metadata = (array) $event->metadata;
                if ($event->action === 'media.uploaded') {
                    return $proofMediaIds->contains((int) data_get($metadata, 'media_reference_id'));
                }

                return array_key_exists('co_maker_id', $metadata)
                    && $metadata['co_maker_id'] === $activePerson?->id;
            })
            ->map(function (AuditLog $event) use ($proofNames): object {
                $metadata = (array) $event->metadata;

                return (object) [
                    'label' => match ($event->action) {
                        'ci_activity.created' => data_get($metadata, 'activity_title').' created',
                        'ci_activity.scheduled' => data_get($metadata, 'activity_title').' scheduled',
                        'ci_activity.rescheduled' => data_get($metadata, 'activity_title').' rescheduled',
                        'ci_activity.completed' => data_get($metadata, 'activity_title').' completed',
                        'ci_activity.submitted' => data_get($metadata, 'activity_title').' submitted to Credit Analyst',
                        'ci_activity.reopened' => data_get($metadata, 'activity_title').' reopened',
                        'ci_activity.deleted' => data_get($metadata, 'activity_title').' deleted',
                        'ci_activity.assignment_changed' => data_get($metadata, 'activity_title', 'CI Activity').' assignment updated',
                        'media.uploaded' => 'Proof uploaded',
                        default => data_get($metadata, 'activity_title', 'CI Activity').' updated',
                    },
                    'detail' => match ($event->action) {
                        'media.uploaded' => $proofNames[(int) data_get($metadata, 'media_reference_id')] ?? null,
                        'ci_activity.submitted' => collect([
                            filled(data_get($metadata, 'submitted_to')) ? 'Submitted to: '.data_get($metadata, 'submitted_to') : null,
                            data_get($metadata, 'submission_note'),
                        ])->filter()->join(' — ') ?: null,
                        default => null,
                    },
                    'tone' => match ($event->action) {
                        'ci_activity.completed', 'ci_activity.submitted', 'media.uploaded' => 'success',
                        'ci_activity.scheduled', 'ci_activity.rescheduled', 'ci_activity.reopened' => 'progress',
                        default => 'neutral',
                    },
                    'user' => $event->user,
                    'created_at' => $event->created_at,
                ];
            })
            ->values();
        $history = $historyEvents->take(5)->values();

        return view('client-folders.activities.index', [
            'clientFolder' => $clientFolder,
            'activities' => $activities,
            'visibleActivityIds' => $visibleActivities->pluck('id'),
            'existingDefinitionIds' => $activities->pluck('activity_definition_id')->unique(),
            'activePerson' => $activePerson,
            'coMakers' => $clientFolder->coMakers()->oldest('id')->get(),
            'definitions' => ActivityDefinition::query()
                ->select(['id', 'name', 'code'])
                ->withCount('activities')
                ->where('is_active', true)
                ->orderBy('sort_order')
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
            $message = $definition->name.' activity type is ready to use.';

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

    public function submit(SubmitCiActivityRequest $request, ClientFolder $clientFolder, CiActivity $ciActivity, SubmitCiActivity $submit): RedirectResponse
    {
        $validated = $request->validated();
        $activePerson = ActivePersonResolver::resolve($clientFolder, $validated['co_maker_id'] ?? null);
        ActivePersonResolver::assertOwnedBy($ciActivity, $activePerson);
        $destination = route(
            'client-folders.activities.index',
            [$clientFolder] + ActivePersonResolver::queryParams($activePerson) + ['status' => 'completed'],
        );

        $submit->execute($request->user(), $clientFolder, $ciActivity, $validated);

        return redirect($destination)->with('status', $ciActivity->name.' submission recorded.');
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
            ->with('definition:id,name')
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
