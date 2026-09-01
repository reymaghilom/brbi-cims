@extends('layouts.app')

@section('title', 'CI Activities')

@section('content')
    @php
        $personParams = \App\Services\ClientFolders\ActivePersonResolver::queryParams($activePerson ?? null);
        $tabs = ['all' => 'All Activities', 'pending' => 'Pending', 'scheduled_today' => 'Scheduled Today', 'follow_up' => 'For Follow-up', 'completed' => 'Completed'];
        $addingNewActivityType = (bool) old('create_new_activity_type');
        $selectedActivityDefinitionId = $addingNewActivityType ? App\Models\ActivityDefinition::NEW_TYPE_VALUE : (string) old('activity_definition_id', '');
        $selectedActivityDefinition = $definitions->firstWhere('id', (int) $selectedActivityDefinitionId);
        $addingBankCoopCheck = ! $addingNewActivityType && $selectedActivityDefinition?->code === App\Models\ActivityDefinition::BANK_COOP_CHECK_CODE;
        $addingAssetCheck = ! $addingNewActivityType && $selectedActivityDefinition?->code === App\Models\ActivityDefinition::ASSET_CHECK_CODE;
        $bankTargetRowsFromOldInput = is_array(old('bank_targets'));
        $bankTargetRows = old('bank_targets');
        if (! is_array($bankTargetRows) || $bankTargetRows === []) {
            $bankTargetRows = [['inquiry_type' => '', 'institution_name' => '', 'branch_location' => '', 'status' => 'pending', 'scheduled_at' => '', 'scheduled_time' => '', 'remarks' => '']];
        }
        $assetTargetRows = old('asset_targets');
        if (! is_array($assetTargetRows) || $assetTargetRows === []) {
            $assetTargetRows = [['assessor_type' => '', 'office_location' => '', 'status' => 'pending', 'scheduled_at' => '', 'scheduled_time' => '', 'remarks' => '']];
        }
        $addActivityStatus = $addingBankCoopCheck
            ? App\Models\CiActivityBankTarget::deriveParentStatus(array_column($bankTargetRows, 'status'))->value
            : ($addingAssetCheck ? App\Models\CiActivityAssetTarget::deriveParentStatus(array_column($assetTargetRows, 'status'))->value : old('status', App\Enums\ActivityStatus::Pending->value));
        $addScheduleEnabled = ! $addingBankCoopCheck && ! $addingAssetCheck && in_array($addActivityStatus, [App\Enums\ActivityStatus::Scheduled->value, App\Enums\ActivityStatus::FollowUp->value], true);
        $addActivityProofEnabled = ! $addingNewActivityType && $addActivityStatus === App\Enums\ActivityStatus::Completed->value;
        $builtInActivityDefinitions = $definitions->reject->isCustom();
        $customActivityDefinitions = $definitions->filter->isCustom();
        $activityModalStatus = session('status');
        $activityTypeCreated = is_string($activityModalStatus) && str_ends_with($activityModalStatus, ' activity type is ready to use.');
        $activityModalHasErrors = $errors->getBag('default')->any();
        $activityModalShouldOpen = $activityModalHasErrors || session('ci_activity_modal_open');
        $activityModalSuccess = $activityTypeCreated ? $activityModalStatus : null;
    @endphp

    <script>
        (function () {
            try {
                if (localStorage.getItem('brbi-ci-activities-history-collapsed') === 'true') {
                    document.documentElement.setAttribute('data-ci-activities-history-collapsed', '');
                }
            } catch (e) {}
        })();
    </script>

    <style>
        [data-ci-activity-search]::-webkit-search-decoration,
        [data-ci-activity-search]::-webkit-search-cancel-button,
        [data-ci-activity-search]::-webkit-search-results-button,
        [data-ci-activity-search]::-webkit-search-results-decoration {
            -webkit-appearance: none;
            appearance: none;
        }

        html[data-ci-activities-history-collapsed] [data-ci-activities-layout] {
            gap: 0;
        }

        html[data-ci-activities-history-collapsed] [data-ci-history-shell] {
            display: none;
        }

        [data-ci-activities-layout] {
            transition: gap 200ms ease-in-out;
        }

        [data-ci-history-shell] {
            display: grid;
            min-width: 0;
            grid-template-rows: minmax(0, 1fr);
            transition: grid-template-rows 200ms ease-in-out;
        }

        [data-ci-history-panel] {
            min-height: 0;
            overflow: hidden;
            opacity: 1;
            transform: translateX(0) scale(1);
            transform-origin: right center;
            transition: opacity 160ms ease-out, transform 200ms ease-out;
        }

        [data-ci-activity-dialog] {
            opacity: 0;
            transform: translateY(0.25rem) scale(0.98);
            transition: opacity 170ms ease-out, transform 180ms ease-out;
        }

        [data-ci-activity-dialog]::backdrop {
            opacity: 0;
            transition: opacity 170ms ease-out;
        }

        [data-ci-activity-dialog][data-modal-state="open"] {
            opacity: 1;
            transform: translateY(0) scale(1);
        }

        [data-ci-activity-dialog][data-modal-state="open"]::backdrop {
            opacity: 1;
        }

        [data-ci-activity-dialog][data-modal-state="closing"] {
            opacity: 0;
            transform: translateY(0.125rem) scale(0.98);
        }

        [data-ci-activity-dialog][data-modal-state="closing"]::backdrop {
            opacity: 0;
        }

        [data-ci-activities-layout][data-history-state="collapsed"] {
            gap: 0;
        }

        [data-ci-activities-layout][data-history-state="collapsed"] [data-ci-history-shell] {
            grid-template-rows: minmax(0, 0fr);
        }

        [data-ci-activities-layout][data-history-state="collapsed"] [data-ci-history-panel] {
            pointer-events: none;
            opacity: 0;
            transform: translateX(0.375rem) scale(0.99);
            transition-timing-function: ease-in;
        }

        @media (min-width: 1280px) {
            html[data-ci-activities-history-collapsed] [data-ci-activities-layout] {
                grid-template-columns: minmax(0, 1fr);
            }

            [data-ci-activities-layout] {
                grid-template-columns: minmax(0, 4fr) minmax(15rem, 1fr);
                transition: grid-template-columns 200ms ease-in-out, gap 200ms ease-in-out;
            }

            [data-ci-activities-layout][data-history-state="collapsed"] {
                grid-template-columns: minmax(0, 1fr) minmax(0, 0fr);
            }
        }

        @media (prefers-reduced-motion: reduce) {
            [data-ci-activities-layout],
            [data-ci-history-shell],
            [data-ci-history-panel] {
                transition-duration: 1ms !important;
            }

            [data-ci-history-panel] {
                transform: none !important;
            }

            [data-ci-activity-dialog],
            [data-ci-activity-dialog]::backdrop {
                transition-duration: 1ms !important;
            }

            [data-ci-activity-dialog] {
                transform: none !important;
            }
        }
    </style>

    <x-ui.breadcrumb :items="[
        ['label' => 'Client Folders', 'url' => route('client-folders.index')],
        ['label' => $clientFolder->display_name, 'url' => route('client-folders.show', [$clientFolder] + $personParams)],
        ['label' => 'CI Activities'],
    ]" />

    <div class="grid items-start gap-5 xl:grid-cols-[minmax(0,4fr)_minmax(15rem,1fr)]" data-ci-activities-layout data-history-state="expanded">
        <section class="ui-panel min-w-0 p-4 sm:p-5 lg:p-6" aria-labelledby="ci-activities-title" data-ci-activities-panel>
            <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                <div class="flex min-w-0 items-start gap-3"><span class="mt-0.5 text-brand-primary"><x-ui.icon name="activity" size="size-6" /></span><div><h2 id="ci-activities-title" class="text-xl font-bold tracking-tight text-brand-sidebar sm:text-2xl">CI Activities</h2><p class="mt-1 max-w-3xl text-sm leading-6 text-text-muted">Track pending, scheduled, follow-up, and completed investigation activities with proof of submission.</p></div></div>
                <div class="flex shrink-0 items-center justify-end gap-2">
                    <button type="button" class="ui-icon-button" title="Show panel" aria-label="Show Activity History panel" aria-controls="activity-history-panel" aria-expanded="false" data-ci-history-show hidden><x-ui.icon name="eye" size="size-4" /></button>
                    <button type="button" class="ui-button-primary shrink-0" data-ci-activity-dialog-open><x-ui.icon name="plus" size="size-4" />Add Activity</button>
                </div>
            </div>

            <div class="mt-5 flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                <nav class="flex min-w-0 flex-1 gap-1 overflow-x-auto overflow-y-hidden border-b border-ui-border" aria-label="Activity status filters">
                    @foreach($tabs as $key => $label)
                        <a href="{{ route('client-folders.activities.index', [$clientFolder] + $personParams + ['status' => $key]) }}" class="relative flex min-h-10 shrink-0 items-center gap-2 rounded-t-control px-3 py-2 text-sm font-semibold text-text-muted transition hover:bg-surface-muted hover:text-brand-sidebar data-[active=true]:bg-brand-soft data-[active=true]:text-brand-primary data-[active=true]:after:absolute data-[active=true]:after:inset-x-0 data-[active=true]:after:-bottom-px data-[active=true]:after:h-0.5 data-[active=true]:after:bg-brand-primary" data-ci-activity-tab data-filter="{{ $key }}" data-active="{{ $filter === $key ? 'true' : 'false' }}" aria-current="{{ $filter === $key ? 'page' : 'false' }}">
                            {{ $label }} @if($counts[$key] > 0)<span class="rounded-full bg-surface px-2 py-0.5 text-xs font-bold shadow-sm">{{ $counts[$key] }}</span>@endif
                        </a>
                    @endforeach
                </nav>
                <div class="flex w-full flex-wrap items-center justify-end gap-2 sm:w-auto sm:shrink-0">
                    <div class="relative min-w-0 basis-full flex-1 sm:w-52 sm:basis-auto sm:flex-none">
                        <label for="ci-activity-search" class="sr-only">Search activities</label>
                        <span class="pointer-events-none absolute inset-y-0 left-0 grid w-9 place-items-center text-text-muted"><x-ui.icon name="search" size="size-4" /></span>
                        <input id="ci-activity-search" type="search" class="ui-control !min-h-9 !py-1.5 !pl-9 !pr-9 text-sm" placeholder="Search activities..." autocomplete="off" data-ci-activity-search>
                        <button type="button" class="absolute inset-y-0 right-0 grid w-9 place-items-center rounded-r-control text-text-muted transition hover:text-brand-primary focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-brand-primary/30" aria-label="Clear activity search" data-ci-activity-search-clear hidden><x-ui.icon name="close" size="size-3.5" /></button>
                    </div>
                </div>
            </div>

            <div class="mt-4 overflow-x-auto overflow-y-hidden rounded-card border border-ui-border">
                <table class="w-full min-w-[64rem] text-left text-sm">
                    <thead class="bg-surface-subtle text-xs font-bold text-text-muted">
                        <tr>
                            @foreach(['activity' => 'Activity', 'status' => 'Status', 'schedule' => 'Schedule'] as $sortKey => $sortLabel)
                                <th scope="col" class="{{ $sortKey === 'activity' ? 'px-4' : 'px-3' }} py-3" aria-sort="none" data-ci-sort-header="{{ $sortKey }}"><button type="button" class="inline-flex items-center gap-1.5 hover:text-brand-sidebar" data-ci-sort="{{ $sortKey }}">{{ $sortLabel }}<span class="inline-flex flex-col text-[0.5rem] leading-[0.4rem]" aria-hidden="true"><span class="opacity-30" data-sort-arrow="asc">▲</span><span class="opacity-30" data-sort-arrow="desc">▼</span></span></button></th>
                            @endforeach
                            <th scope="col" class="px-3 py-3">Proof / Submission</th>
                            @foreach(['creator' => 'Creator', 'updated' => 'Last Updated'] as $sortKey => $sortLabel)
                                <th scope="col" class="px-3 py-3" aria-sort="none" data-ci-sort-header="{{ $sortKey }}"><button type="button" class="inline-flex items-center gap-1.5 hover:text-brand-sidebar" data-ci-sort="{{ $sortKey }}">{{ $sortLabel }}<span class="inline-flex flex-col text-[0.5rem] leading-[0.4rem]" aria-hidden="true"><span class="opacity-30" data-sort-arrow="asc">▲</span><span class="opacity-30" data-sort-arrow="desc">▼</span></span></button></th>
                            @endforeach
                            <th scope="col" class="px-3 py-3 text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-ui-border bg-surface" data-ci-activities-body>
                        @foreach($activities as $activity)
                            @php
                                $activitySchedule = $activity->scheduled_at ?? $activity->visit_date;
                                $scheduledToday = $activity->status === App\Enums\ActivityStatus::Scheduled
                                    && $activity->scheduled_at?->timezone(config('cims.display_timezone'))->isToday();
                                $activityProof = $activity->mediaReferences;
                                $attachmentCount = $activityProof->count();
                                $singleAttachment = $attachmentCount === 1 ? $activityProof->first() : null;
                                $singleAttachmentIsPreviewable = $singleAttachment
                                    && (Str::startsWith($singleAttachment->mime_type, 'image/') || Str::startsWith($singleAttachment->mime_type, 'video/'));
                                $isBankCoopCheck = $activity->definition?->code === App\Models\ActivityDefinition::BANK_COOP_CHECK_CODE;
                                $isAssetCheck = $activity->definition?->code === App\Models\ActivityDefinition::ASSET_CHECK_CODE;
                                $isMandatoryDefault = $activity->isMandatoryDefault();
                                $isDefaultCheck = $isMandatoryDefault;
                                $hasQuickCompletion = $isDefaultCheck || $isBankCoopCheck || $isAssetCheck;
                                $completionIsChecked = match (true) {
                                    $isBankCoopCheck => $activity->bank_targets_count > 0 && $activity->bank_targets_count === $activity->completed_bank_targets_count,
                                    $isAssetCheck => $activity->asset_targets_count > 0 && $activity->asset_targets_count === $activity->completed_asset_targets_count,
                                    default => $activity->status === App\Enums\ActivityStatus::Completed,
                                };
                                $completionKind = match (true) {
                                    $isBankCoopCheck => 'bank',
                                    $isAssetCheck => 'asset',
                                    default => 'default',
                                };
                            @endphp
                            <tr class="align-middle transition hover:bg-surface-subtle/70" data-ci-activity-row data-ci-activity-id="{{ $activity->id }}" data-status="{{ $activity->status->value }}" data-scheduled-today="{{ $scheduledToday ? 'true' : 'false' }}" data-sort-activity="{{ Str::lower($activity->name) }}" data-sort-status="{{ Str::lower($activity->status->label()) }}" data-sort-schedule="{{ $activitySchedule?->timestamp ?? 0 }}" data-sort-creator="{{ Str::lower($activity->creator?->full_name ?? 'System-created') }}" data-sort-updated="{{ $activity->updated_at->timestamp }}" @if(! $visibleActivityIds->contains($activity->id)) hidden @endif>
                                <td class="px-4 py-3">
                                    <div class="flex min-w-0 items-start gap-2.5">
                                        @if($hasQuickCompletion)
                                            <label class="mt-0.5 inline-flex size-9 shrink-0 cursor-pointer items-center justify-center rounded-control border border-success/25 bg-success-soft/60 transition hover:border-success/45 hover:bg-success-soft focus-within:ring-2 focus-within:ring-success/30 has-[:disabled]:cursor-default" title="{{ $completionIsChecked ? $activity->name.' completed' : ($completionKind === 'default' ? 'Mark '.$activity->name.' as completed' : 'Open '.$activity->name.' tracker to complete remaining targets') }}">
                                                <input
                                                    type="checkbox"
                                                    class="size-5 rounded-md border-2 border-success/50 text-success focus:ring-success"
                                                    aria-label="{{ $completionIsChecked ? $activity->name.' completed' : ($completionKind === 'default' ? 'Mark '.$activity->name.' as completed' : 'Open '.$activity->name.' tracker to complete remaining targets') }}"
                                                    data-ci-activity-completion="{{ $activity->id }}"
                                                    data-completion-kind="{{ $completionKind }}"
                                                    @if($isDefaultCheck)
                                                        data-completion-name="{{ $activity->name }}"
                                                        data-completion-update-url="{{ route('client-folders.activities.update', [$clientFolder, $activity]) }}"
                                                        data-completion-tracker-url="{{ route('client-folders.activities.default-check.show', [$clientFolder, $activity] + $personParams) }}"
                                                        data-completion-expected-updated-at="{{ $activity->updated_at->toISOString() }}"
                                                        data-completion-co-maker-id="{{ $activePerson?->id }}"
                                                    @endif
                                                    @checked($completionIsChecked)
                                                    @disabled($completionIsChecked)
                                                >
                                            </label>
                                        @endif
                                        <div class="min-w-0">
                                            <p class="break-words font-bold text-text-main">{{ $activity->name }}</p>
                                            @if($isBankCoopCheck)
                                                <p class="mt-0.5 max-w-52 break-words text-xs text-text-muted" data-bank-coop-progress="{{ $activity->id }}">{{ $activity->bank_targets_count }} {{ str('institution')->plural($activity->bank_targets_count) }} · {{ $activity->completed_bank_targets_count }} completed</p>
                                            @elseif($isAssetCheck)
                                                <p class="mt-0.5 max-w-52 break-words text-xs text-text-muted" data-asset-check-progress="{{ $activity->id }}">{{ $activity->asset_targets_count }} {{ str('assessor')->plural($activity->asset_targets_count) }} · {{ $activity->completed_asset_targets_count }} completed</p>
                                            @elseif($isDefaultCheck)
                                                <p class="mt-0.5 max-w-52 break-words text-xs text-text-muted" data-default-check-summary="{{ $activity->id }}">{{ $activity->status->label() }} · {{ $activity->remarks ?: 'No remarks yet' }}</p>
                                            @else
                                                <p class="mt-0.5 max-w-52 truncate text-xs text-text-muted">{{ $activity->target ?: ($activity->definition?->is_required ? 'Required investigation activity' : 'General investigation activity') }}</p>
                                            @endif
                                        </div>
                                    </div>
                                </td>
                                <td class="px-3 py-3"><span data-bank-coop-status="{{ $isBankCoopCheck ? $activity->id : '' }}" data-ci-activity-status-badge="{{ $activity->id }}" @class(['inline-flex rounded-full px-2.5 py-1 text-xs font-bold', 'bg-progress-soft text-progress' => $activity->status === App\Enums\ActivityStatus::Pending, 'bg-brand-soft text-brand-primary' => $activity->status === App\Enums\ActivityStatus::Scheduled, 'bg-[#fff0e7] text-[#c85b12]' => $activity->status === App\Enums\ActivityStatus::FollowUp, 'bg-success-soft text-success' => $activity->status === App\Enums\ActivityStatus::Completed])>{{ $activity->status->label() }}</span></td>
                                <td class="px-3 py-3 text-xs leading-5 text-text-muted" data-ci-activity-schedule-cell="{{ $activity->id }}">@if($activity->scheduled_at)<span class="block font-semibold text-text-main">{{ $activity->scheduled_at->timezone(config('cims.display_timezone'))->format('M j, Y') }}</span>{{ $activity->scheduled_has_time ? $activity->scheduled_at->timezone(config('cims.display_timezone'))->format('g:i A') : 'No specific time' }}@elseif($activity->visit_date)<span class="block font-semibold text-text-main">{{ $activity->visit_date->format('M j, Y') }}</span>Completed visit @else — @endif</td>
                                <td class="px-3 py-3" data-submission-cell="{{ $activity->id }}">
                                    <div class="min-w-36 space-y-2 text-xs">
                                        @if($attachmentCount === 1 && $singleAttachmentIsPreviewable)
                                            <button type="button" class="inline-flex min-h-7 items-center gap-1.5 rounded-control px-1.5 py-1 font-semibold text-brand-primary transition hover:bg-brand-soft hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-primary/30" data-modal-open="ci-proof-preview-{{ $activity->id }}-{{ $singleAttachment->id }}" aria-label="View {{ $singleAttachment->file_name }}"><x-ui.icon name="attachment" size="size-4" />1 Attachment</button>
                                        @elseif($attachmentCount === 1)
                                            <a href="{{ route('client-folders.activities.proof.content', [$clientFolder, $activity, $singleAttachment]) }}" target="_blank" rel="noopener" class="inline-flex min-h-7 items-center gap-1.5 rounded-control px-1.5 py-1 font-semibold text-brand-primary transition hover:bg-brand-soft hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-primary/30" aria-label="View {{ $singleAttachment->file_name }}"><x-ui.icon name="attachment" size="size-4" />1 Attachment</a>
                                        @elseif($attachmentCount > 1)
                                            <button type="button" class="inline-flex min-h-7 items-center gap-1.5 rounded-control px-1.5 py-1 font-semibold text-brand-primary transition hover:bg-brand-soft hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-primary/30" data-modal-open="ci-proof-list-{{ $activity->id }}" aria-label="View {{ $attachmentCount }} attachments for {{ $activity->name }}"><x-ui.icon name="attachment" size="size-4" />{{ $attachmentCount }} Attachments</button>
                                        @else
                                            <span class="flex items-center gap-1.5 font-semibold text-text-muted"><x-ui.icon name="attachment" size="size-4" />No Attachment</span>
                                        @endif
                                        @if($activity->submitted_at)
                                            <div class="space-y-1 leading-4">
                                                <span class="inline-flex items-center gap-1 rounded-full bg-success-soft px-2 py-0.5 font-bold text-success"><x-ui.icon name="check-circle" size="size-3.5" />Submitted</span>
                                                <span class="block text-text-muted">{{ $activity->submitted_at->timezone(config('cims.display_timezone'))->format('M j, Y · g:i A') }}</span>
                                                @if($activity->submitted_to)<span class="block max-w-44 truncate text-text-muted" title="{{ $activity->submitted_to }}">To: {{ $activity->submitted_to }}</span>@endif
                                            </div>
                                            @if($activity->status === App\Enums\ActivityStatus::Completed)
                                                <button type="button" class="ui-button-secondary-compact !min-h-7 !px-2 !py-1 !text-[0.6875rem]" data-modal-open="submit-activity-{{ $activity->id }}" data-submission-action="update">View / Update</button>
                                            @endif
                                        @else
                                            <span class="inline-flex items-center rounded-full border border-ui-border bg-surface-subtle px-2 py-0.5 font-bold text-text-muted">Not Submitted</span>
                                            @if($activity->status === App\Enums\ActivityStatus::Completed)
                                                <button type="button" class="ui-button-secondary-compact" data-modal-open="submit-activity-{{ $activity->id }}" data-submission-action="create">Mark as Submitted</button>
                                            @endif
                                        @endif
                                    </div>
                                </td>
                                <td class="px-3 py-3 text-xs leading-5"><span class="block max-w-36 truncate font-semibold text-text-main">{{ $activity->creator?->full_name ?? 'System-created' }}</span><span class="text-text-muted">Locked creator</span></td>
                                <td class="px-3 py-3 text-xs leading-5 text-text-muted" data-ci-activity-updated-cell="{{ $activity->id }}"><span class="block font-semibold text-text-main">{{ $activity->updated_at->timezone(config('cims.display_timezone'))->format('M j, Y') }}</span>{{ $activity->updated_at->timezone(config('cims.display_timezone'))->format('g:i A') }}@if($activity->updater) · {{ $activity->updater->full_name }}@endif</td>
                                <td class="px-3 py-3">
                                    <div class="flex items-center justify-center gap-1">
                                        @if($isDefaultCheck)
                                            <button type="button" class="ui-button-secondary-compact !size-8 !min-h-8 !px-0" aria-label="Edit {{ $activity->name }}" title="Edit" data-default-check-open="{{ $activity->id }}" data-default-check-url="{{ route('client-folders.activities.default-check.show', [$clientFolder, $activity] + $personParams) }}"><x-ui.icon name="edit" size="size-4" /></button>
                                        @elseif($isBankCoopCheck)
                                            <button type="button" class="ui-button-secondary-compact !size-8 !min-h-8 !px-0" aria-label="Edit {{ $activity->name }}" title="Edit" data-bank-coop-open="{{ $activity->id }}" data-bank-coop-url="{{ route('client-folders.activities.bank-coop.show', [$clientFolder, $activity] + $personParams) }}"><x-ui.icon name="edit" size="size-4" /></button>
                                        @elseif($isAssetCheck)
                                            <button type="button" class="ui-button-secondary-compact !size-8 !min-h-8 !px-0" aria-label="Edit {{ $activity->name }}" title="Edit" data-asset-check-open="{{ $activity->id }}" data-asset-check-url="{{ route('client-folders.activities.asset-check.show', [$clientFolder, $activity] + $personParams) }}"><x-ui.icon name="edit" size="size-4" /></button>
                                        @else
                                            <a href="{{ route('client-folders.activities.edit', [$clientFolder, $activity] + $personParams) }}" class="ui-button-secondary-compact !size-8 !min-h-8 !px-0" aria-label="Update {{ $activity->name }}" title="Update"><x-ui.icon name="edit" size="size-4" /></a>
                                            <a href="{{ route('client-folders.activities.edit', [$clientFolder, $activity] + $personParams) }}#schedule" class="ui-button-secondary-compact !size-8 !min-h-8 !px-0" aria-label="Schedule or reschedule {{ $activity->name }}" title="Schedule / Reschedule"><x-ui.icon name="calendar" size="size-4" /></a>
                                            @if($activity->status !== App\Enums\ActivityStatus::Completed)<form method="POST" action="{{ route('client-folders.activities.update', [$clientFolder, $activity]) }}">@csrf @method('PUT')<input type="hidden" name="co_maker_id" value="{{ $activePerson?->id }}"><input type="hidden" name="expected_updated_at" value="{{ $activity->updated_at->toISOString() }}"><input type="hidden" name="status" value="completed"><input type="hidden" name="intent" value="return"><button type="submit" class="ui-button-secondary-compact !size-8 !min-h-8 !px-0" aria-label="Complete {{ $activity->name }}" title="Complete"><x-ui.icon name="check-circle" size="size-4" /></button></form>@endif
                                        @endif
                                        <x-ui.context-menu :label="$activity->name.' more actions'">
                                            <x-slot:trigger><span class="ui-dots-trigger !size-8"><x-ui.icon name="more-vertical" size="size-4" /></span></x-slot:trigger>
                                            <a href="{{ route('client-folders.activities.edit', [$clientFolder, $activity] + $personParams) }}#notes-title" role="menuitem" class="client-folder-menu-item">View notes</a>
                                            <a href="{{ route('client-folders.media.index', [$clientFolder] + $personParams) }}" role="menuitem" class="client-folder-menu-item">Manage proof</a>
                                            @if($activity->status === App\Enums\ActivityStatus::Completed)
                                                <button type="button" role="menuitem" class="client-folder-menu-item" data-modal-open="reopen-activity-{{ $activity->id }}">Reopen Activity</button>
                                            @endif
                                            @unless($isMandatoryDefault)
                                                <button type="button" role="menuitem" class="client-folder-menu-item text-danger" data-modal-open="delete-activity-{{ $activity->id }}">Delete Activity</button>
                                            @endunless
                                        </x-ui.context-menu>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                        <tr data-ci-empty-state @if($visibleActivityIds->isNotEmpty()) hidden @endif><td colspan="7" class="px-6 py-12 text-center"><div data-ci-empty-fresh @if($counts['all'] !== 0) hidden @endif><p class="font-semibold text-text-main">No CI activities yet.</p><p class="mt-1 text-sm text-text-muted">Add an activity when there is something to process, schedule, follow up, or document.</p></div><div data-ci-empty-filter @if($counts['all'] === 0) hidden @endif><p class="font-semibold text-text-main">No activities in this view.</p><p class="mt-1 text-sm text-text-muted">Choose another status or add an activity.</p></div><div data-ci-empty-search hidden><p class="font-semibold text-text-main">No activities match your search.</p><p class="mt-1 text-sm text-text-muted">Try a different activity, status, date, creator, or submission term.</p></div></td></tr>
                    </tbody>
                </table>
            </div>

            <dialog id="bank-coop-tracker-modal" class="fixed inset-0 m-auto max-h-[calc(100dvh-2rem)] w-[calc(100%-2rem)] max-w-4xl overflow-hidden rounded-panel border-0 bg-surface p-0 shadow-float backdrop:bg-brand-sidebar/45" data-bank-coop-modal aria-labelledby="bank-coop-tracker-title">
                <div class="flex max-h-[calc(100dvh-2rem)] flex-col">
                    <div class="relative flex shrink-0 flex-col gap-3 border-b border-ui-border px-5 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-6">
                        <div class="min-w-0 pr-10 sm:pr-0"><h2 id="bank-coop-tracker-title" class="text-lg font-bold text-brand-sidebar">Bank / Coop Check</h2><p class="mt-1 truncate text-sm text-text-muted" data-bank-coop-modal-context>Loading exact activity context…</p></div>
                        <div class="flex flex-wrap items-center gap-2 pr-10 sm:pr-0"><button type="button" class="ui-button-primary !min-h-9 !px-3 !py-1.5 !text-xs" data-bank-coop-modal-add disabled><x-ui.icon name="plus" size="size-3.5" />Add Bank / Coop</button><button type="button" class="ui-icon-button absolute right-3 top-3 sm:static" data-bank-coop-modal-close aria-label="Close Bank / Coop Check"><x-ui.icon name="close" size="size-5" /></button></div>
                    </div>
                    <div class="min-h-0 flex-1 overflow-y-auto overscroll-contain bg-surface-subtle/40 p-3 sm:p-4" data-bank-coop-modal-body>
                        <div class="grid min-h-40 place-items-center rounded-card border border-ui-border bg-surface p-6 text-sm font-semibold text-text-muted" role="status">Loading Bank / Coop targets…</div>
                    </div>
                    <div class="flex shrink-0 justify-end border-t border-ui-border px-5 py-3.5 sm:px-6"><button type="button" class="ui-button-secondary-compact" data-bank-coop-modal-close>Close</button></div>
                </div>
            </dialog>

            <dialog id="asset-check-tracker-modal" class="fixed inset-0 m-auto max-h-[calc(100dvh-2rem)] w-[calc(100%-2rem)] max-w-4xl overflow-hidden rounded-panel border-0 bg-surface p-0 shadow-float backdrop:bg-brand-sidebar/45" data-asset-check-modal aria-labelledby="asset-check-tracker-title">
                <div class="flex max-h-[calc(100dvh-2rem)] flex-col">
                    <div class="relative flex shrink-0 flex-col gap-3 border-b border-ui-border px-5 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-6">
                        <div class="min-w-0 pr-10 sm:pr-0"><h2 id="asset-check-tracker-title" class="text-lg font-bold text-brand-sidebar">Asset Check</h2><p class="mt-1 break-words text-sm text-text-muted" data-asset-check-modal-context>Loading exact activity context…</p></div>
                        <div class="flex flex-wrap items-center gap-2 pr-10 sm:pr-0"><button type="button" class="ui-button-primary !min-h-9 !px-3 !py-1.5 !text-xs" data-asset-check-modal-add disabled><x-ui.icon name="plus" size="size-3.5" />Add Assessor</button><button type="button" class="ui-icon-button absolute right-3 top-3 sm:static" data-asset-check-modal-close aria-label="Close Asset Check"><x-ui.icon name="close" size="size-5" /></button></div>
                    </div>
                    <div class="min-h-0 flex-1 overflow-y-auto overscroll-contain bg-surface-subtle/40 p-3 sm:p-4" data-asset-check-modal-body><div class="grid min-h-40 place-items-center rounded-card border border-ui-border bg-surface p-6 text-sm font-semibold text-text-muted" role="status">Loading assessor targets…</div></div>
                    <div class="flex shrink-0 justify-end border-t border-ui-border px-5 py-3.5 sm:px-6"><button type="button" class="ui-button-secondary-compact" data-asset-check-modal-close>Close</button></div>
                </div>
            </dialog>

            <dialog id="default-check-tracker-modal" class="fixed inset-0 m-auto max-h-[calc(100dvh-2rem)] w-[calc(100%-2rem)] max-w-3xl overflow-hidden rounded-panel border-0 bg-surface p-0 shadow-float backdrop:bg-brand-sidebar/45" data-default-check-modal aria-labelledby="default-check-tracker-title">
                <div class="flex max-h-[calc(100dvh-2rem)] flex-col">
                    <div class="relative flex shrink-0 items-start justify-between gap-3 border-b border-ui-border px-5 py-4 sm:px-6"><div class="min-w-0 pr-10"><h2 id="default-check-tracker-title" class="break-words text-lg font-bold text-brand-sidebar">Activity Tracker</h2><p class="mt-1 break-words text-sm text-text-muted" data-default-check-modal-context>Loading exact activity context…</p></div><button type="button" class="ui-icon-button absolute right-3 top-3" data-default-check-modal-close aria-label="Close activity tracker"><x-ui.icon name="close" size="size-5" /></button></div>
                    <div class="min-h-0 flex-1 overflow-y-auto overscroll-contain bg-surface-subtle/40 p-3 sm:p-4" data-default-check-modal-body><div class="grid min-h-40 place-items-center rounded-card border border-ui-border bg-surface p-6 text-sm font-semibold text-text-muted" role="status">Loading activity tracker…</div></div>
                    <div class="flex shrink-0 justify-end border-t border-ui-border px-5 py-3.5 sm:px-6"><button type="button" class="ui-button-secondary-compact" data-default-check-modal-close>Close</button></div>
                </div>
            </dialog>

            <dialog id="quick-complete-activity-modal" class="fixed inset-0 m-auto max-h-[calc(100dvh-2rem)] w-[calc(100%-2rem)] max-w-md overflow-y-auto rounded-panel border-0 bg-surface p-0 shadow-float backdrop:bg-brand-sidebar/45" data-quick-complete-modal aria-labelledby="quick-complete-activity-title">
                <div class="border-b border-ui-border px-5 py-4"><h2 id="quick-complete-activity-title" class="break-words text-lg font-bold text-brand-sidebar" data-quick-complete-title>Mark activity as completed?</h2></div>
                <div class="space-y-3 px-5 py-5 text-sm leading-6 text-text-muted">
                    <p>The schedule and time will be cleared. Completion will be recorded in Activity History under the actual user confirming this action.</p>
                    <p class="rounded-control border border-danger/25 bg-danger-soft px-3 py-2 font-semibold text-danger" role="alert" data-quick-complete-error hidden></p>
                </div>
                <div class="flex flex-col-reverse gap-2.5 border-t border-ui-border px-5 py-4 sm:flex-row sm:justify-end"><button type="button" class="ui-button-secondary w-full sm:w-auto" data-quick-complete-cancel>Cancel</button><button type="button" class="ui-button-primary w-full sm:w-auto" data-quick-complete-confirm>Mark Completed</button></div>
            </dialog>

            @foreach($activities as $activity)
                @php
                    $activityProof = $activity->mediaReferences;
                @endphp
                @if($activityProof->count() > 1)
                    <x-ui.modal id="ci-proof-list-{{ $activity->id }}" title="Attachments ({{ $activityProof->count() }})" description="Proof files linked to {{ $activity->name }} only." size="max-w-lg" data-ci-proof-list data-ci-activity-id="{{ $activity->id }}">
                        <ul class="divide-y divide-ui-border" aria-label="Proof attachments for {{ $activity->name }}">
                            @foreach($activityProof as $proof)
                                @php
                                    $proofIsPreviewable = Str::startsWith($proof->mime_type, 'image/') || Str::startsWith($proof->mime_type, 'video/');
                                @endphp
                                <li class="flex min-w-0 items-center gap-3 py-3 first:pt-0 last:pb-0">
                                    <span class="grid size-9 shrink-0 place-items-center rounded-control bg-brand-soft text-brand-primary"><x-ui.icon name="{{ Str::startsWith($proof->mime_type, 'image/') ? 'media' : (Str::startsWith($proof->mime_type, 'video/') ? 'video' : 'report') }}" size="size-4" /></span>
                                    <span class="min-w-0 flex-1 break-words text-sm font-semibold leading-5 text-text-main" title="{{ $proof->file_name }}">{{ $proof->file_name }}</span>
                                    @if($proofIsPreviewable)
                                        <button type="button" class="ui-button-secondary-compact shrink-0" data-modal-open="ci-proof-preview-{{ $activity->id }}-{{ $proof->id }}" aria-label="View {{ $proof->file_name }}">View</button>
                                    @else
                                        <a href="{{ route('client-folders.activities.proof.content', [$clientFolder, $activity, $proof]) }}" target="_blank" rel="noopener" class="ui-button-secondary-compact shrink-0" aria-label="View {{ $proof->file_name }}">View</a>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                        <x-slot:footer><button type="button" data-modal-close class="ui-button-secondary">Close</button></x-slot:footer>
                    </x-ui.modal>
                @endif

                @foreach($activityProof as $proof)
                    @if(Str::startsWith($proof->mime_type, 'image/') || Str::startsWith($proof->mime_type, 'video/'))
                        <x-ui.modal id="ci-proof-preview-{{ $activity->id }}-{{ $proof->id }}" :title="$proof->file_name" size="max-w-4xl" data-ci-proof-preview data-ci-activity-id="{{ $activity->id }}" data-media-id="{{ $proof->id }}">
                            <div class="overflow-hidden rounded-card bg-brand-sidebar/5">
                                @if(Str::startsWith($proof->mime_type, 'image/'))
                                    <img src="{{ route('client-folders.activities.proof.content', [$clientFolder, $activity, $proof]) }}" alt="{{ $proof->file_name }}" loading="lazy" class="mx-auto max-h-[65vh] w-auto max-w-full object-contain">
                                @else
                                    <video controls preload="none" class="mx-auto max-h-[65vh] w-full bg-black" aria-label="{{ $proof->file_name }}"><source src="{{ route('client-folders.activities.proof.content', [$clientFolder, $activity, $proof]) }}" type="{{ $proof->mime_type }}">Your browser does not support this video format.</video>
                                @endif
                            </div>
                            <x-slot:footer><button type="button" data-modal-close class="ui-button-primary">Close</button></x-slot:footer>
                        </x-ui.modal>
                    @endif
                @endforeach

                @if($activity->status === App\Enums\ActivityStatus::Completed)
                    <dialog id="submit-activity-{{ $activity->id }}" class="fixed inset-0 m-auto max-h-[calc(100dvh-2rem)] w-[calc(100%-2rem)] max-w-xl overflow-hidden rounded-panel border-0 bg-surface p-0 shadow-float backdrop:bg-brand-sidebar/45" aria-labelledby="submission-dialog-title-{{ $activity->id }}" @if($errors->submission->any() && (int) old('submission_activity_id') === $activity->id) open @endif>
                        <form method="POST" action="{{ route('client-folders.activities.submit', [$clientFolder, $activity]) }}" class="flex max-h-[calc(100dvh-2rem)] flex-col">
                            @csrf @method('PATCH')
                            <input type="hidden" name="submission_activity_id" value="{{ $activity->id }}">
                            <input type="hidden" name="co_maker_id" value="{{ $activePerson?->id }}">
                            <div class="flex shrink-0 items-start justify-between gap-4 border-b border-ui-border px-5 py-4 sm:px-6">
                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center gap-2.5">
                                        <h2 id="submission-dialog-title-{{ $activity->id }}" class="text-lg font-bold text-brand-sidebar">{{ $activity->submitted_at ? 'View / Update Submission' : 'Mark as Submitted' }}</h2>
                                        @if($activity->submitted_at)<span class="inline-flex items-center gap-1 rounded-full bg-success-soft px-2 py-0.5 text-[0.6875rem] font-bold text-success"><x-ui.icon name="check-circle" size="size-3.5" />Submitted</span>@endif
                                    </div>
                                    <p class="mt-1.5 truncate text-sm font-bold text-text-main">{{ $activity->name }}</p>
                                    <p class="mt-0.5 text-xs leading-5 text-text-muted">{{ $activePerson?->full_name ?? $clientFolder->display_name }} <span aria-hidden="true">&middot;</span> {{ $activePerson ? 'Co-Maker' : 'Applicant' }}</p>
                                </div>
                                <button type="button" class="ui-icon-button -mr-2" data-modal-close aria-label="Close submission dialog"><x-ui.icon name="close" size="size-5" /></button>
                            </div>
                            <div class="min-h-0 space-y-4 overflow-y-auto overscroll-contain px-5 py-4 sm:px-6">
                                @if($activity->submitted_at)
                                    <dl class="grid gap-x-4 gap-y-3 rounded-control border border-ui-border bg-surface-subtle/70 px-4 py-3 sm:grid-cols-2" data-submission-summary>
                                        <div class="min-w-0"><dt class="text-[0.6875rem] font-bold uppercase tracking-wide text-text-muted">Submitted By</dt><dd class="mt-1 break-words text-sm font-semibold leading-5 text-text-main">{{ $activity->submitter?->full_name ?? 'Unknown user' }}</dd></div>
                                        <div class="min-w-0"><dt class="text-[0.6875rem] font-bold uppercase tracking-wide text-text-muted">Submitted At</dt><dd class="mt-1 break-words text-sm font-semibold leading-5 text-text-main">{{ $activity->submitted_at->timezone(config('cims.display_timezone'))->format('M j, Y · g:i A') }}</dd></div>
                                        <div class="min-w-0"><dt class="text-[0.6875rem] font-bold uppercase tracking-wide text-text-muted">Submitted To</dt><dd class="mt-1 break-words text-sm font-semibold leading-5 text-text-main">{{ $activity->submitted_to ?: 'Not specified' }}</dd></div>
                                        <div class="min-w-0"><dt class="text-[0.6875rem] font-bold uppercase tracking-wide text-text-muted">Proof</dt><dd class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-sm font-semibold leading-5 text-text-main">@if($activity->media_references_count > 0)<span>{{ $activity->media_references_count }} {{ Str::plural('Attachment', $activity->media_references_count) }}</span><a href="{{ route('client-folders.media.index', [$clientFolder] + $personParams) }}" class="text-xs font-bold text-brand-primary hover:underline">View Proof</a>@else<span class="text-text-muted">No Attachment</span>@endif</dd></div>
                                    </dl>
                                @endif
                                <div><label for="submitted-to-{{ $activity->id }}" class="ui-label">Submitted To <span class="font-normal text-text-muted">(optional)</span></label><input id="submitted-to-{{ $activity->id }}" name="submitted_to" value="{{ (int) old('submission_activity_id') === $activity->id ? old('submitted_to') : $activity->submitted_to }}" class="ui-control" maxlength="255" autocomplete="off" placeholder="Enter the Credit Analyst's name">@if($errors->submission->has('submitted_to'))<p class="mt-2 flex items-start gap-1.5 text-sm font-medium text-danger" role="alert"><x-ui.icon name="warning" size="mt-0.5 size-4" />{{ $errors->submission->first('submitted_to') }}</p>@endif</div>
                                <div><label for="submission-note-{{ $activity->id }}" class="ui-label">Submission Note <span class="font-normal text-text-muted">(optional)</span></label><textarea id="submission-note-{{ $activity->id }}" name="submission_note" rows="3" class="ui-control" placeholder="Add a concise handoff or submission note.">{{ (int) old('submission_activity_id') === $activity->id ? old('submission_note') : $activity->submission_note }}</textarea>@if($errors->submission->has('submission_note'))<p class="mt-2 flex items-start gap-1.5 text-sm font-medium text-danger" role="alert"><x-ui.icon name="warning" size="mt-0.5 size-4" />{{ $errors->submission->first('submission_note') }}</p>@endif</div>
                                @if($errors->submission->has('submission_activity_id'))<p class="flex items-start gap-1.5 text-sm font-medium text-danger" role="alert"><x-ui.icon name="warning" size="mt-0.5 size-4" />{{ $errors->submission->first('submission_activity_id') }}</p>@endif
                                <div class="flex items-start gap-2 rounded-control border border-brand-primary/15 bg-brand-soft/40 px-3 py-2.5 text-xs leading-5 text-text-muted"><x-ui.icon name="info" size="mt-0.5 size-4 shrink-0 text-brand-primary" /><p><span class="font-semibold text-text-main">Submitted By</span> records the authenticated user completing this handoff. The original activity Creator remains unchanged, and proof remains optional.</p></div>
                            </div>
                            <div class="flex shrink-0 flex-col-reverse gap-2.5 border-t border-ui-border px-5 py-3.5 sm:flex-row sm:justify-end sm:px-6"><button type="button" class="ui-button-secondary-compact !min-h-9 w-full sm:w-auto" data-modal-close>{{ $activity->submitted_at ? 'Close' : 'Cancel' }}</button><button type="submit" class="ui-button-primary !min-h-9 !px-3 !py-1.5 !text-xs w-full sm:w-auto"><x-ui.icon name="check-circle" size="size-3.5" />{{ $activity->submitted_at ? 'Update Submission' : 'Mark as Submitted' }}</button></div>
                        </form>
                    </dialog>
                    <x-ui.confirmation-dialog id="reopen-activity-{{ $activity->id }}" title="Reopen Activity?" :action="route('client-folders.activities.update', [$clientFolder, $activity])" method="PUT" confirm-label="Reopen Activity">
                        <p>This activity will be returned to Pending. The previous completion will remain visible in Activity History.</p>
                        <x-slot:formFields>
                            <input type="hidden" name="co_maker_id" value="{{ $activePerson?->id }}">
                            <input type="hidden" name="expected_updated_at" value="{{ $activity->updated_at->toISOString() }}">
                            <input type="hidden" name="status" value="pending">
                            <input type="hidden" name="intent" value="return">
                        </x-slot:formFields>
                    </x-ui.confirmation-dialog>
                @endif
                @unless($activity->isMandatoryDefault())
                    <x-ui.confirmation-dialog id="delete-activity-{{ $activity->id }}" :title="'Permanently delete '.$activity->name.'?'" :action="route('client-folders.activities.destroy', [$clientFolder, $activity])" method="DELETE" confirm-label="Permanently Delete" destructive>
                        <p>This action cannot be undone.</p>
                        <x-slot:formFields><input type="hidden" name="co_maker_id" value="{{ $activePerson?->id }}"></x-slot:formFields>
                    </x-ui.confirmation-dialog>
                @endunless
            @endforeach

            <div class="mt-4 flex items-start gap-2 rounded-control border border-progress/20 bg-progress-soft/70 px-4 py-3 text-sm text-[#76520c]"><x-ui.icon name="warning" size="size-4" class="mt-0.5" /><p>Only the activity creator receives scheduled notifications. Other authorized CI users may view and update the activity as needed.</p></div>
        </section>

        <div class="min-w-0" data-ci-history-shell>
            <aside id="activity-history-panel" class="ui-panel min-w-0 p-5" aria-labelledby="activity-history-title" data-ci-history-panel>
                <div class="flex items-center justify-between gap-3">
                    <div class="flex min-w-0 items-center gap-2 text-brand-primary"><x-ui.icon name="clock" size="size-5" /><h2 id="activity-history-title" class="truncate text-base font-bold text-brand-sidebar">Activity History</h2></div>
                    <button type="button" class="ui-icon-button -mr-2 -mt-2 shrink-0" title="Hide panel" aria-label="Hide Activity History panel" aria-controls="activity-history-panel" aria-expanded="true" data-ci-history-hide><x-ui.icon name="close" size="size-4" /></button>
                </div>
                @if($history->isEmpty())<div class="mt-6 rounded-control bg-surface-subtle p-4 text-sm leading-6 text-text-muted">No activity history has been recorded for this person yet.</div>@else
                    <ol class="relative mt-6 space-y-0">@foreach($history as $event)<li class="relative grid grid-cols-[1rem_1fr] gap-3 pb-6 last:pb-0">@unless($loop->last)<span class="absolute bottom-0 left-[0.4375rem] top-4 border-l border-dashed border-ui-border-strong" aria-hidden="true"></span>@endunless<span @class(['relative z-10 mt-1 size-3.5 rounded-full border-2 border-white shadow-sm', 'bg-success' => $event->tone === 'success', 'bg-brand-primary' => $event->tone === 'progress', 'bg-text-muted' => $event->tone === 'neutral'])></span><div class="min-w-0"><p class="text-sm font-bold leading-5 text-text-main">{{ $event->label }}</p>@if($event->detail)<p class="mt-1 break-words text-xs leading-5 text-text-muted">{{ $event->detail }}</p>@endif<p class="mt-1 text-xs leading-5 text-text-muted">by {{ $event->user?->full_name ?? 'System' }}<br>{{ $event->created_at->timezone(config('cims.display_timezone'))->format('M j, Y · g:i A') }}</p></div></li>@endforeach</ol>
                @endif
                <div class="mt-5 border-t border-ui-border pt-4">
                    <button type="button" class="w-full text-center text-sm font-bold text-brand-primary hover:underline" data-modal-open="all-activity-history">View All</button>
                </div>
            </aside>
        </div>
    </div>

    <dialog id="all-activity-history" class="fixed inset-0 m-auto w-[calc(100%-2rem)] max-w-2xl overflow-hidden rounded-panel border-0 bg-surface p-0 shadow-float backdrop:bg-brand-sidebar/45">
        <div class="flex max-h-[calc(100dvh-2rem)] flex-col">
            <div class="flex shrink-0 items-center justify-between gap-4 border-b border-ui-border px-5 py-4 sm:px-6">
                <div class="flex items-center gap-2 text-brand-primary"><x-ui.icon name="clock" size="size-5" /><h2 class="text-lg font-bold text-brand-sidebar">Activity History</h2></div>
                <button type="button" class="ui-icon-button -mr-2" data-modal-close aria-label="Close Activity History"><x-ui.icon name="close" size="size-5" /></button>
            </div>
            <div class="min-h-0 overflow-y-auto overscroll-contain px-5 py-4 sm:px-6" data-ci-history-modal-body>
                @forelse($allHistory as $event)
                    <div class="border-b border-ui-border px-1 py-4 first:pt-0 last:border-b-0 last:pb-0">
                        <p class="text-sm font-bold leading-5 text-text-main">{{ $event->label }}</p>
                        @if($event->detail)<p class="mt-1 break-words text-xs leading-5 text-text-muted">{{ $event->detail }}</p>@endif
                        <p class="mt-1 text-xs leading-5 text-text-muted">by {{ $event->user?->full_name ?? 'System' }} &middot; {{ $event->created_at->timezone(config('cims.display_timezone'))->format('M j, Y · g:i A') }}</p>
                    </div>
                @empty
                    <p class="rounded-control bg-surface-subtle p-4 text-sm leading-6 text-text-muted">No activity history has been recorded for this person yet.</p>
                @endforelse
            </div>
        </div>
    </dialog>

    <dialog class="fixed inset-0 m-auto max-h-[calc(100dvh-2rem)] w-[calc(100%-2rem)] max-w-xl overflow-hidden rounded-panel border-0 bg-surface p-0 shadow-float backdrop:bg-brand-sidebar/45" data-ci-activity-dialog @if($activityModalShouldOpen) open data-ci-activity-initial-open @endif @if($activityModalHasErrors) data-ci-activity-validation-open data-modal-state="open" @endif>
        <form method="POST" action="{{ route('client-folders.activities.store', $clientFolder) }}" enctype="multipart/form-data" class="flex max-h-[calc(100dvh-2rem)] flex-col" data-ci-activity-create-form novalidate>
            @csrf
            <input type="hidden" name="co_maker_id" value="{{ $activePerson?->id }}">
            <div class="shrink-0 px-5 pt-5 sm:px-6 sm:pt-6">
                <div class="flex items-start justify-between gap-4"><div><h2 class="text-xl font-bold text-brand-sidebar">Add Activity</h2><p class="mt-1 text-sm text-text-muted">Create a focused activity for {{ $activePerson?->full_name ?? $clientFolder->display_name }}.</p></div><button type="button" class="ui-icon-button -mr-2 -mt-2" data-ci-activity-dialog-close aria-label="Close"><x-ui.icon name="close" size="size-5" /></button></div>
                @if($activityModalSuccess)
                    <div class="mt-4 flex items-center gap-2 rounded-control bg-success-soft px-3 py-2 text-sm font-semibold text-success" role="status" data-ci-activity-success><x-ui.icon name="check-circle" size="size-4" />{{ $activityModalSuccess }}</div>
                @endif
                <div class="mt-4 flex items-start gap-2 rounded-control border border-danger/25 bg-danger-soft px-3 py-2 text-sm font-semibold text-danger" role="alert" tabindex="-1" data-ci-activity-request-error hidden><x-ui.icon name="warning" size="mt-0.5 size-4 shrink-0" /><span data-ci-activity-request-error-message></span></div>
            </div>
            <div class="min-h-0 flex-1 overflow-y-auto overscroll-contain px-5 py-5 sm:px-6" data-ci-activity-dialog-body>
                <div class="grid gap-4 sm:grid-cols-2">
                <div class="sm:col-span-2">
                    <label for="activity-definition" class="ui-label">Activity Type</label>
                    <div class="relative" data-activity-type-selector>
                        <input id="activity-definition" type="hidden" name="activity_definition_id" value="{{ $selectedActivityDefinitionId }}" data-activity-type-select>
                        <button type="button" class="ui-control flex w-full items-center justify-between gap-3 text-left" aria-haspopup="listbox" aria-controls="activity-type-options" aria-expanded="false" data-activity-type-trigger>
                            <span class="min-w-0 flex-1 truncate {{ $selectedActivityDefinition || $addingNewActivityType ? 'text-text-main' : 'text-text-muted' }}" data-activity-type-label>{{ $addingNewActivityType ? '+ Add New Activity Type' : ($selectedActivityDefinition?->name ?? 'Select activity type') }}</span>
                            <span class="grid size-5 shrink-0 place-items-center text-base leading-none text-text-muted" aria-hidden="true">&#9662;</span>
                        </button>
                        <div id="activity-type-options" class="absolute inset-x-0 z-30 mt-1.5 max-h-[min(18rem,50dvh)] overflow-y-auto rounded-control border border-ui-border bg-surface py-1.5 shadow-float" role="listbox" aria-label="Activity Type" data-activity-type-options hidden>
                            @if($builtInActivityDefinitions->isNotEmpty())
                                <div class="px-3 pb-1 pt-1.5 text-[0.6875rem] font-bold uppercase tracking-wide text-text-muted" role="presentation">Built-in Activity Types</div>
                                @foreach($builtInActivityDefinitions as $definition)
                                    @php
                                        $alreadyAdded = $existingDefinitionIds->contains($definition->id);
                                    @endphp
                                    <button type="button" class="flex min-h-10 w-full items-center px-3 py-2 text-left text-sm font-normal leading-5 transition hover:bg-surface-subtle focus:bg-surface-subtle focus:outline-none aria-selected:bg-brand-soft aria-selected:text-brand-primary disabled:cursor-not-allowed disabled:text-text-muted disabled:opacity-60" role="option" data-activity-type-option data-value="{{ $definition->id }}" data-code="{{ $definition->code }}" data-label="{{ $definition->name }}" aria-selected="{{ (string) $selectedActivityDefinitionId === (string) $definition->id ? 'true' : 'false' }}" @disabled($alreadyAdded)>{{ $definition->name }}@if($alreadyAdded)<span class="ml-auto pl-3 text-xs font-normal">Already Added</span>@endif</button>
                                @endforeach
                            @endif

                            @if($customActivityDefinitions->isNotEmpty())
                                <div role="group" aria-label="My Custom Activity Types" data-custom-activity-types>
                                <div class="mx-2 my-1 border-t border-ui-border" role="presentation"></div>
                                <div class="px-3 pb-1 pt-1.5 text-[0.6875rem] font-bold uppercase tracking-wide text-text-muted" role="presentation">My Custom Activity Types</div>
                                @foreach($customActivityDefinitions as $definition)
                                    @php
                                        $alreadyAdded = $existingDefinitionIds->contains($definition->id);
                                    @endphp
                                    <div class="flex min-w-0 items-center" role="presentation" data-custom-activity-type-row="{{ $definition->id }}">
                                        <button type="button" class="flex min-h-10 min-w-0 flex-1 items-center px-3 py-2 text-left text-sm font-normal leading-5 transition hover:bg-surface-subtle focus:bg-surface-subtle focus:outline-none aria-selected:bg-brand-soft aria-selected:text-brand-primary disabled:cursor-not-allowed disabled:text-text-muted disabled:opacity-60" role="option" data-activity-type-option data-value="{{ $definition->id }}" data-code="{{ $definition->code }}" data-label="{{ $definition->name }}" aria-selected="{{ (string) $selectedActivityDefinitionId === (string) $definition->id ? 'true' : 'false' }}" @disabled($alreadyAdded)><span class="min-w-0 flex-1 truncate">{{ $definition->name }}</span>@if($alreadyAdded)<span class="shrink-0 pl-3 text-xs font-normal">Already Added</span>@endif</button>
                                        <button type="button" class="mr-1 grid size-9 shrink-0 place-items-center rounded-control text-base font-bold text-danger transition hover:bg-danger-soft focus:bg-danger-soft focus:outline-none focus:ring-2 focus:ring-danger/30" title="Remove Activity Type" aria-label="Remove {{ $definition->name }} activity type" data-activity-type-remove="{{ $definition->id }}" data-activity-type-remove-dialog="remove-activity-definition-{{ $definition->id }}"><span aria-hidden="true">&minus;</span></button>
                                    </div>
                                @endforeach
                                </div>
                            @endif

                            <div class="mx-2 my-1 border-t border-ui-border" role="presentation"></div>
                            <button type="button" class="flex min-h-10 w-full items-center px-3 py-2 text-left text-sm font-normal leading-5 text-brand-primary transition hover:bg-brand-soft focus:bg-brand-soft focus:outline-none aria-selected:bg-brand-soft" role="option" data-activity-type-option data-value="{{ App\Models\ActivityDefinition::NEW_TYPE_VALUE }}" data-code="" data-label="+ Add New Activity Type" aria-selected="{{ $addingNewActivityType ? 'true' : 'false' }}">+ Add New Activity Type</button>
                        </div>
                    </div>
                    <x-form.validation-message for="activity_definition_id" />
                    <p class="mt-2 text-sm font-semibold text-danger" role="alert" data-ci-activity-type-error hidden>Please select an Activity Type.</p>
                </div>
                <div class="sm:col-span-2" data-new-activity-type-fields @if(! $addingNewActivityType) hidden @endif>
                    <label for="new-activity-type" class="ui-label">New Activity Type</label>
                    <input id="new-activity-type" name="new_activity_type" value="{{ old('new_activity_type') }}" class="ui-control" maxlength="255" autocomplete="off" @if($addingNewActivityType) required @else disabled @endif data-new-activity-type-input>
                    <x-form.validation-message for="new_activity_type" />
                    <p class="mt-2 text-sm font-semibold text-danger" role="alert" data-ci-new-activity-type-error hidden>Please enter an Activity Type name.</p>
                </div>
                <div data-standard-activity-field @if($addingNewActivityType || $addingBankCoopCheck) hidden @endif><label for="activity-status" class="ui-label">Status</label><select id="activity-status" name="status" class="ui-control" @if(! $addingNewActivityType && ! $addingBankCoopCheck) required @else disabled @endif data-ci-activity-status><option value="pending" @selected($addActivityStatus === 'pending')>Pending</option><option value="scheduled" @selected($addActivityStatus === 'scheduled')>Scheduled</option><option value="follow_up" @selected($addActivityStatus === 'follow_up')>For Follow-up</option><option value="completed" @selected($addActivityStatus === 'completed')>Completed</option></select><x-form.validation-message for="status" /><p class="mt-2 text-sm font-semibold text-danger" role="alert" data-ci-activity-status-error hidden>Please select a Status.</p></div>
                <div class="sm:col-span-2" data-standard-activity-field @if($addingNewActivityType || $addingBankCoopCheck) hidden @endif>
                    <div class="grid gap-3 sm:grid-cols-[minmax(0,1fr)_minmax(8rem,0.55fr)]">
                        <div><label for="activity-schedule" class="ui-label">Schedule / Follow-up Date</label><input id="activity-schedule" name="scheduled_at" type="date" value="{{ $addScheduleEnabled ? old('scheduled_at') : '' }}" class="ui-control disabled:cursor-not-allowed disabled:bg-surface-muted disabled:text-text-muted disabled:opacity-75" data-ci-activity-schedule @disabled(! $addScheduleEnabled) aria-disabled="{{ $addScheduleEnabled ? 'false' : 'true' }}"><x-form.validation-message for="scheduled_at" /><p class="mt-2 text-sm font-semibold text-danger" role="alert" data-ci-activity-schedule-error hidden>Please select a Schedule date.</p></div>
                        <div><label for="activity-schedule-time" class="ui-label">Time <span class="font-normal text-text-muted">(optional)</span></label><input id="activity-schedule-time" name="scheduled_time" type="time" value="{{ $addScheduleEnabled ? old('scheduled_time') : '' }}" class="ui-control disabled:cursor-not-allowed disabled:bg-surface-muted disabled:text-text-muted disabled:opacity-75" data-ci-activity-schedule-time @disabled(! $addScheduleEnabled) aria-disabled="{{ $addScheduleEnabled ? 'false' : 'true' }}"><x-form.validation-message for="scheduled_time" /></div>
                    </div>
                    <p class="ui-help" data-ci-schedule-help>{{ $addScheduleEnabled ? 'Time is optional. Without one, the creator is reminded at 8:00 AM on the selected date.' : 'Available when the status is Scheduled or For Follow-up.' }}</p>
                </div>
                <div class="sm:col-span-2" data-standard-activity-field @if($addingNewActivityType || $addingBankCoopCheck) hidden @endif><label for="activity-remarks" class="ui-label">Short Remarks <span class="font-normal text-text-muted">(optional)</span></label><textarea id="activity-remarks" name="remarks" rows="3" class="ui-control" placeholder="Add concise operational details." data-ci-activity-remarks @disabled($addingNewActivityType || $addingBankCoopCheck)>{{ old('remarks') }}</textarea><x-form.validation-message for="remarks" /></div>
                <section class="sm:col-span-2 rounded-card border border-ui-border bg-surface-subtle/50 p-3.5 sm:p-4" data-bank-targets-section @if(! $addingBankCoopCheck) hidden @endif>
                    <div><h3 class="text-xs font-bold uppercase tracking-wide text-brand-sidebar">Banks / Cooperatives</h3><p class="mt-1 text-xs text-text-muted">Add each institution with its own status and schedule.</p></div>
                    <div class="mt-3 space-y-3" data-bank-target-rows>
                        @foreach($bankTargetRows as $index => $target)
                            @php
                                $targetInquiryType = $target['inquiry_type'] ?? '';
                                $targetStatus = $target['status'] ?? 'pending';
                                $targetSupportsSchedule = in_array($targetStatus, ['scheduled', 'follow_up'], true);
                            @endphp
                            <article class="rounded-control border border-ui-border bg-surface p-3" data-bank-target-row data-bank-target-index="{{ $index }}">
                                <div class="mb-3 flex justify-end"><button type="button" class="ui-button-danger-compact" data-bank-target-remove><x-ui.icon name="trash" size="size-3.5" />Remove</button></div>
                                <div class="grid gap-3 sm:grid-cols-2">
                                    <div><label for="bank-target-inquiry-type-{{ $index }}" class="ui-label">Inquiry Type</label><select id="bank-target-inquiry-type-{{ $index }}" name="bank_targets[{{ $index }}][inquiry_type]" class="ui-control" required data-bank-target-inquiry-type data-bank-target-control @disabled(! $addingBankCoopCheck)><option value="">Select inquiry type</option>@foreach(App\Models\CiActivityBankTarget::INQUIRY_TYPES as $value => $label)<option value="{{ $value }}" @selected($targetInquiryType === $value)>{{ $label }}</option>@endforeach</select>@error('bank_targets.'.$index.'.inquiry_type')<p class="mt-1.5 text-sm font-semibold text-danger">{{ $message }}</p>@enderror<p class="mt-1.5 text-sm font-semibold text-danger" data-bank-target-inquiry-type-error hidden>Select an inquiry type.</p></div>
                                    <div><label for="bank-target-name-{{ $index }}" class="ui-label">Bank / Coop Name</label><input id="bank-target-name-{{ $index }}" name="bank_targets[{{ $index }}][institution_name]" value="{{ $target['institution_name'] ?? '' }}" class="ui-control" maxlength="255" required data-bank-target-control @disabled(! $addingBankCoopCheck)>@error('bank_targets.'.$index.'.institution_name')<p class="mt-1.5 text-sm font-semibold text-danger">{{ $message }}</p>@enderror<p class="mt-1.5 text-sm font-semibold text-danger" data-bank-target-name-error hidden>Enter the Bank / Coop name.</p></div>
                                    <div data-bank-target-branch-field @if($targetInquiryType === App\Models\CiActivityBankTarget::INQUIRY_TYPE_LOAN_INQUIRY) hidden @endif><label for="bank-target-branch-{{ $index }}" class="ui-label">Branch / Location <span class="font-normal text-text-muted">(optional)</span></label><input id="bank-target-branch-{{ $index }}" name="bank_targets[{{ $index }}][branch_location]" value="{{ $targetInquiryType === App\Models\CiActivityBankTarget::INQUIRY_TYPE_LOAN_INQUIRY ? '' : ($target['branch_location'] ?? '') }}" class="ui-control" maxlength="255" data-bank-target-branch data-bank-target-control @disabled(! $addingBankCoopCheck || $targetInquiryType === App\Models\CiActivityBankTarget::INQUIRY_TYPE_LOAN_INQUIRY)>@error('bank_targets.'.$index.'.branch_location')<p class="mt-1.5 text-sm font-semibold text-danger">{{ $message }}</p>@enderror</div>
                                    <div><label for="bank-target-status-{{ $index }}" class="ui-label">Status</label><select id="bank-target-status-{{ $index }}" name="bank_targets[{{ $index }}][status]" class="ui-control" required data-bank-target-status data-bank-target-control @disabled(! $addingBankCoopCheck)><option value="pending" @selected($targetStatus === 'pending')>Pending</option><option value="scheduled" @selected($targetStatus === 'scheduled')>Scheduled</option><option value="follow_up" @selected($targetStatus === 'follow_up')>For Follow-up</option><option value="completed" @selected($targetStatus === 'completed')>Completed</option></select>@error('bank_targets.'.$index.'.status')<p class="mt-1.5 text-sm font-semibold text-danger">{{ $message }}</p>@enderror</div>
                                    <div class="grid gap-3 sm:grid-cols-2"><div><label for="bank-target-date-{{ $index }}" class="ui-label">Schedule Date <span class="font-normal text-text-muted">(optional)</span></label><input id="bank-target-date-{{ $index }}" name="bank_targets[{{ $index }}][scheduled_at]" type="date" value="{{ $targetSupportsSchedule ? ($target['scheduled_at'] ?? '') : '' }}" class="ui-control disabled:cursor-not-allowed disabled:bg-surface-muted disabled:opacity-70" data-bank-target-date data-bank-target-control @disabled(! $addingBankCoopCheck || ! $targetSupportsSchedule)>@error('bank_targets.'.$index.'.scheduled_at')<p class="mt-1.5 text-sm font-semibold text-danger">{{ $message }}</p>@enderror</div><div><label for="bank-target-time-{{ $index }}" class="ui-label">Time <span class="font-normal text-text-muted">(optional)</span></label><input id="bank-target-time-{{ $index }}" name="bank_targets[{{ $index }}][scheduled_time]" type="time" value="{{ $targetSupportsSchedule ? ($target['scheduled_time'] ?? '') : '' }}" class="ui-control disabled:cursor-not-allowed disabled:bg-surface-muted disabled:opacity-70" data-bank-target-time data-bank-target-control @disabled(! $addingBankCoopCheck || ! $targetSupportsSchedule || blank($target['scheduled_at'] ?? null))>@error('bank_targets.'.$index.'.scheduled_time')<p class="mt-1.5 text-sm font-semibold text-danger">{{ $message }}</p>@enderror</div><p class="text-xs leading-5 text-text-muted sm:col-span-2">Date and time are optional. Select a date to enable a specific time.</p></div>
                                    <div class="sm:col-span-2"><label for="bank-target-remarks-{{ $index }}" class="ui-label">Remarks <span class="font-normal text-text-muted">(optional)</span></label><textarea id="bank-target-remarks-{{ $index }}" name="bank_targets[{{ $index }}][remarks]" rows="2" class="ui-control" data-bank-target-control @disabled(! $addingBankCoopCheck)>{{ $target['remarks'] ?? '' }}</textarea>@error('bank_targets.'.$index.'.remarks')<p class="mt-1.5 text-sm font-semibold text-danger">{{ $message }}</p>@enderror</div>
                                </div>
                            </article>
                        @endforeach
                    </div>
                    @error('bank_targets')<p class="mt-2 text-sm font-semibold text-danger">{{ $message }}</p>@enderror
                    <button type="button" class="ui-button-secondary-compact mt-3" data-bank-target-add><x-ui.icon name="plus" size="size-3.5" />Add Another Bank / Coop</button>
                    <template data-bank-target-template>
                        <article class="rounded-control border border-ui-border bg-surface p-3" data-bank-target-row data-bank-target-index="__INDEX__">
                            <div class="mb-3 flex justify-end"><button type="button" class="ui-button-danger-compact" data-bank-target-remove><x-ui.icon name="trash" size="size-3.5" />Remove</button></div>
                            <div class="grid gap-3 sm:grid-cols-2">
                                <div><label for="bank-target-inquiry-type-__INDEX__" class="ui-label">Inquiry Type</label><select id="bank-target-inquiry-type-__INDEX__" name="bank_targets[__INDEX__][inquiry_type]" class="ui-control" required data-bank-target-inquiry-type data-bank-target-control><option value="">Select inquiry type</option>@foreach(App\Models\CiActivityBankTarget::INQUIRY_TYPES as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select><p class="mt-1.5 text-sm font-semibold text-danger" data-bank-target-inquiry-type-error hidden>Select an inquiry type.</p></div>
                                <div><label for="bank-target-name-__INDEX__" class="ui-label">Bank / Coop Name</label><input id="bank-target-name-__INDEX__" name="bank_targets[__INDEX__][institution_name]" class="ui-control" maxlength="255" required data-bank-target-control><p class="mt-1.5 text-sm font-semibold text-danger" data-bank-target-name-error hidden>Enter the Bank / Coop name.</p></div>
                                <div data-bank-target-branch-field><label for="bank-target-branch-__INDEX__" class="ui-label">Branch / Location <span class="font-normal text-text-muted">(optional)</span></label><input id="bank-target-branch-__INDEX__" name="bank_targets[__INDEX__][branch_location]" class="ui-control" maxlength="255" data-bank-target-branch data-bank-target-control></div>
                                <div><label for="bank-target-status-__INDEX__" class="ui-label">Status</label><select id="bank-target-status-__INDEX__" name="bank_targets[__INDEX__][status]" class="ui-control" required data-bank-target-status data-bank-target-control><option value="pending">Pending</option><option value="scheduled">Scheduled</option><option value="follow_up">For Follow-up</option><option value="completed">Completed</option></select></div>
                                <div class="grid gap-3 sm:grid-cols-2"><div><label for="bank-target-date-__INDEX__" class="ui-label">Schedule Date <span class="font-normal text-text-muted">(optional)</span></label><input id="bank-target-date-__INDEX__" name="bank_targets[__INDEX__][scheduled_at]" type="date" class="ui-control disabled:cursor-not-allowed disabled:bg-surface-muted disabled:opacity-70" data-bank-target-date data-bank-target-control disabled></div><div><label for="bank-target-time-__INDEX__" class="ui-label">Time <span class="font-normal text-text-muted">(optional)</span></label><input id="bank-target-time-__INDEX__" name="bank_targets[__INDEX__][scheduled_time]" type="time" class="ui-control disabled:cursor-not-allowed disabled:bg-surface-muted disabled:opacity-70" data-bank-target-time data-bank-target-control disabled></div><p class="text-xs leading-5 text-text-muted sm:col-span-2">Date and time are optional. Select a date to enable a specific time.</p></div>
                                <div class="sm:col-span-2"><label for="bank-target-remarks-__INDEX__" class="ui-label">Remarks <span class="font-normal text-text-muted">(optional)</span></label><textarea id="bank-target-remarks-__INDEX__" name="bank_targets[__INDEX__][remarks]" rows="2" class="ui-control" data-bank-target-control></textarea></div>
                            </div>
                        </article>
                    </template>
                </section>
                <section class="sm:col-span-2 rounded-card border border-ui-border bg-surface-subtle/50 p-3.5 sm:p-4" data-asset-targets-section @if(! $addingAssetCheck) hidden @endif>
                    <div><h3 class="text-xs font-bold uppercase tracking-wide text-brand-sidebar">Assessor Offices</h3><p class="mt-1 text-xs leading-5 text-text-muted">Add only the assessor offices required for this client. Each row keeps its own status and schedule.</p></div>
                    <div class="mt-3 space-y-3" data-asset-target-rows>
                        @foreach($assetTargetRows as $index => $target)
                            @php
                                $assetStatus = $target['status'] ?? 'pending';
                                $assetSupportsSchedule = in_array($assetStatus, ['scheduled', 'follow_up'], true);
                            @endphp
                            <article class="rounded-control border border-ui-border bg-surface p-3" data-asset-target-row data-asset-target-index="{{ $index }}">
                                <div class="mb-3 flex justify-end"><button type="button" class="ui-button-danger-compact" data-asset-target-remove><x-ui.icon name="trash" size="size-3.5" />Remove</button></div>
                                <div class="grid gap-3 sm:grid-cols-2">
                                    <div><label class="ui-label" for="asset-target-type-{{ $index }}">Assessor Office / Type</label><select id="asset-target-type-{{ $index }}" name="asset_targets[{{ $index }}][assessor_type]" class="ui-control" required data-asset-target-control @disabled(! $addingAssetCheck)><option value="">Select assessor office</option>@foreach(App\Models\CiActivityAssetTarget::ASSESSOR_TYPES as $value => $label)<option value="{{ $value }}" @selected(($target['assessor_type'] ?? '') === $value)>{{ $label }}</option>@endforeach</select>@error('asset_targets.'.$index.'.assessor_type')<p class="mt-1.5 text-sm font-semibold text-danger">{{ $message }}</p>@enderror</div>
                                    <div><label class="ui-label" for="asset-target-location-{{ $index }}">Office / Municipality / City / Location</label><input id="asset-target-location-{{ $index }}" name="asset_targets[{{ $index }}][office_location]" value="{{ $target['office_location'] ?? '' }}" class="ui-control" maxlength="255" required data-asset-target-location data-asset-target-control @disabled(! $addingAssetCheck)>@error('asset_targets.'.$index.'.office_location')<p class="mt-1.5 text-sm font-semibold text-danger">{{ $message }}</p>@enderror</div>
                                    <div><label class="ui-label" for="asset-target-status-{{ $index }}">Status</label><select id="asset-target-status-{{ $index }}" name="asset_targets[{{ $index }}][status]" class="ui-control" required data-asset-target-status data-asset-target-control @disabled(! $addingAssetCheck)><option value="pending" @selected($assetStatus === 'pending')>Pending</option><option value="scheduled" @selected($assetStatus === 'scheduled')>Scheduled</option><option value="follow_up" @selected($assetStatus === 'follow_up')>For Follow-up</option><option value="completed" @selected($assetStatus === 'completed')>Completed</option></select></div>
                                    <div class="grid gap-3 sm:grid-cols-2"><div><label class="ui-label" for="asset-target-date-{{ $index }}">Schedule / Follow-up Date <span class="font-normal text-text-muted">(optional)</span></label><input id="asset-target-date-{{ $index }}" name="asset_targets[{{ $index }}][scheduled_at]" type="date" value="{{ $assetSupportsSchedule ? ($target['scheduled_at'] ?? '') : '' }}" class="ui-control disabled:cursor-not-allowed disabled:bg-surface-muted disabled:opacity-70" data-asset-target-date data-asset-target-control @disabled(! $addingAssetCheck || ! $assetSupportsSchedule)></div><div><label class="ui-label" for="asset-target-time-{{ $index }}">Time <span class="font-normal text-text-muted">(optional)</span></label><input id="asset-target-time-{{ $index }}" name="asset_targets[{{ $index }}][scheduled_time]" type="time" value="{{ $assetSupportsSchedule ? ($target['scheduled_time'] ?? '') : '' }}" class="ui-control disabled:cursor-not-allowed disabled:bg-surface-muted disabled:opacity-70" data-asset-target-time data-asset-target-control @disabled(! $addingAssetCheck || ! $assetSupportsSchedule || blank($target['scheduled_at'] ?? null))></div><p class="text-xs leading-5 text-text-muted sm:col-span-2">Select a date to enable a specific time.</p></div>
                                    <div class="sm:col-span-2"><label class="ui-label" for="asset-target-remarks-{{ $index }}">Short Remarks <span class="font-normal text-text-muted">(optional)</span></label><textarea id="asset-target-remarks-{{ $index }}" name="asset_targets[{{ $index }}][remarks]" rows="2" class="ui-control" data-asset-target-control @disabled(! $addingAssetCheck)>{{ $target['remarks'] ?? '' }}</textarea></div>
                                </div>
                            </article>
                        @endforeach
                    </div>
                    @error('asset_targets')<p class="mt-2 text-sm font-semibold text-danger">{{ $message }}</p>@enderror
                    <button type="button" class="ui-button-secondary-compact mt-3" data-asset-target-add><x-ui.icon name="plus" size="size-3.5" />Add Assessor</button>
                    <template data-asset-target-template>
                        <article class="rounded-control border border-ui-border bg-surface p-3" data-asset-target-row data-asset-target-index="__INDEX__">
                            <div class="mb-3 flex justify-end"><button type="button" class="ui-button-danger-compact" data-asset-target-remove><x-ui.icon name="trash" size="size-3.5" />Remove</button></div>
                            <div class="grid gap-3 sm:grid-cols-2">
                                <div><label class="ui-label" for="asset-target-type-__INDEX__">Assessor Office / Type</label><select id="asset-target-type-__INDEX__" name="asset_targets[__INDEX__][assessor_type]" class="ui-control" required data-asset-target-control><option value="">Select assessor office</option>@foreach(App\Models\CiActivityAssetTarget::ASSESSOR_TYPES as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select></div>
                                <div><label class="ui-label" for="asset-target-location-__INDEX__">Office / Municipality / City / Location</label><input id="asset-target-location-__INDEX__" name="asset_targets[__INDEX__][office_location]" class="ui-control" maxlength="255" required data-asset-target-location data-asset-target-control></div>
                                <div><label class="ui-label" for="asset-target-status-__INDEX__">Status</label><select id="asset-target-status-__INDEX__" name="asset_targets[__INDEX__][status]" class="ui-control" required data-asset-target-status data-asset-target-control><option value="pending">Pending</option><option value="scheduled">Scheduled</option><option value="follow_up">For Follow-up</option><option value="completed">Completed</option></select></div>
                                <div class="grid gap-3 sm:grid-cols-2"><div><label class="ui-label" for="asset-target-date-__INDEX__">Schedule / Follow-up Date <span class="font-normal text-text-muted">(optional)</span></label><input id="asset-target-date-__INDEX__" name="asset_targets[__INDEX__][scheduled_at]" type="date" class="ui-control disabled:cursor-not-allowed disabled:bg-surface-muted disabled:opacity-70" data-asset-target-date data-asset-target-control disabled></div><div><label class="ui-label" for="asset-target-time-__INDEX__">Time <span class="font-normal text-text-muted">(optional)</span></label><input id="asset-target-time-__INDEX__" name="asset_targets[__INDEX__][scheduled_time]" type="time" class="ui-control disabled:cursor-not-allowed disabled:bg-surface-muted disabled:opacity-70" data-asset-target-time data-asset-target-control disabled></div><p class="text-xs leading-5 text-text-muted sm:col-span-2">Select a date to enable a specific time.</p></div>
                                <div class="sm:col-span-2"><label class="ui-label" for="asset-target-remarks-__INDEX__">Short Remarks <span class="font-normal text-text-muted">(optional)</span></label><textarea id="asset-target-remarks-__INDEX__" name="asset_targets[__INDEX__][remarks]" rows="2" class="ui-control" data-asset-target-control></textarea></div>
                            </div>
                        </article>
                    </template>
                </section>
                <div class="min-w-0 sm:col-span-2" data-activity-create-only @if(! $addActivityProofEnabled) hidden @endif>
                    <label for="activity-attachment" class="ui-label">Proof / Attachment <span class="font-normal text-text-muted">(optional)</span></label>
                    <div class="flex min-w-0 flex-col gap-2 sm:flex-row sm:items-center">
                        <input id="activity-attachment" type="file" name="attachment" class="sr-only" data-ci-activity-attachment @disabled(! $addActivityProofEnabled)>
                        <button type="button" class="ui-button-secondary-compact w-fit shrink-0" data-ci-activity-attachment-open><x-ui.icon name="attachment" size="size-4" />Upload Attachment</button>
                        <div class="flex min-w-0 items-center gap-2 text-xs text-text-muted">
                            <span class="min-w-0 truncate" title="No file selected" data-ci-activity-attachment-name>No file selected</span>
                            <button type="button" class="shrink-0 font-semibold text-danger hover:underline" data-ci-activity-attachment-remove hidden>Remove</button>
                        </div>
                    </div>
                    <p class="ui-help">Attach supporting evidence now or add it later.</p>
                    <x-form.validation-message for="attachment" />
                </div>
                @if(! $addActivityProofEnabled && $errors->has('attachment'))
                    <div class="sm:col-span-2"><x-form.validation-message for="attachment" /></div>
                @endif
                <div class="sm:col-span-2 rounded-control bg-surface-subtle px-3.5 py-3 text-xs leading-5 text-text-muted" data-custom-activity-info @if(! $addingNewActivityType) hidden @endif>
                    Saving this reusable Activity Type will not create a CI Activity or assign a Creator.
                </div>
                </div>
            </div>
            <div class="flex shrink-0 flex-col-reverse gap-3 border-t border-ui-border px-5 py-4 sm:flex-row sm:justify-end sm:px-6"><button type="button" class="ui-button-secondary" data-ci-activity-dialog-close>Cancel</button><button type="submit" class="ui-button-primary" data-ci-activity-submit><x-ui.icon name="plus" size="size-4" /><span data-ci-activity-submit-label>{{ $addingNewActivityType ? 'Add Activity Type' : 'Add Activity' }}</span></button></div>
        </form>
    </dialog>

    @foreach($customActivityDefinitions as $definition)
        <x-ui.confirmation-dialog
            id="remove-activity-definition-{{ $definition->id }}"
            :title="$definition->activities_count === 0 ? 'Delete Activity Type?' : 'Remove Activity Type?'"
            :action="route('client-folders.activity-definitions.deactivate', [$clientFolder, $definition])"
            method="DELETE"
            :confirm-label="$definition->activities_count === 0 ? 'Delete Permanently' : 'Remove'"
            :destructive="$definition->activities_count === 0"
        >
            @if($definition->activities_count === 0)
                <p><span class="font-semibold text-text-main">{{ $definition->name }}</span> will be permanently deleted and can be created again later.</p>
            @else
                <p><span class="font-semibold text-text-main">{{ $definition->name }}</span> has existing activity history. It will be removed from future selection, but historical records will be preserved.</p>
            @endif
            <x-slot:formFields>
                <input type="hidden" name="co_maker_id" value="{{ $activePerson?->id }}">
                <input type="hidden" name="status" value="{{ $filter }}">
                <input type="hidden" name="selected_activity_definition_id" value="" data-activity-type-current-selection>
            </x-slot:formFields>
        </x-ui.confirmation-dialog>
    @endforeach

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const dialog = document.querySelector('[data-ci-activity-dialog]');
            const dialogBody = document.querySelector('[data-ci-activity-dialog-body]');
            const openButton = document.querySelector('[data-ci-activity-dialog-' + 'open]');
            if (!(dialog instanceof HTMLDialogElement)
                || !(dialogBody instanceof HTMLElement)
                || !(openButton instanceof HTMLButtonElement)) return;

            const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
            let closeTimer = null;
            let openFrame = null;
            let closeCallbacks = [];

            const finishCloseCallbacks = () => {
                const callbacks = closeCallbacks;
                closeCallbacks = [];
                callbacks.forEach((callback) => callback());
            };

            const showActivityDialog = () => {
                if (closeTimer !== null) window.clearTimeout(closeTimer);
                if (openFrame !== null) window.cancelAnimationFrame(openFrame);
                if (! dialog.open) dialog.showModal();
                dialogBody.scrollTop = 0;
                dialog.dataset.modalState = 'opening';
                openFrame = window.requestAnimationFrame(() => {
                    openFrame = null;
                    dialogBody.scrollTop = 0;
                    dialog.dataset.modalState = 'open';
                });
            };

            const closeActivityDialog = (afterClose = null) => {
                if (typeof afterClose === 'function') closeCallbacks.push(afterClose);
                if (! dialog.open) {
                    finishCloseCallbacks();
                    return;
                }
                if (dialog.dataset.modalState === 'closing') return;
                if (openFrame !== null) window.cancelAnimationFrame(openFrame);
                dialog.dataset.modalState = 'closing';

                const finishClose = () => {
                    closeTimer = null;
                    if (dialog.open) dialog.close();
                    delete dialog.dataset.modalState;
                    openButton.focus();
                    finishCloseCallbacks();
                };

                if (reducedMotion) finishClose();
                else closeTimer = window.setTimeout(finishClose, 190);
            };

            openButton.addEventListener('click', (event) => {
                event.preventDefault();
                event.stopImmediatePropagation();
                showActivityDialog();
            }, true);

            dialog.addEventListener('click', (event) => {
                const closeButton = event.target instanceof Element
                    ? event.target.closest('[data-ci-activity-dialog-close]')
                    : null;
                if (closeButton && dialog.contains(closeButton)) {
                    event.preventDefault();
                    event.stopImmediatePropagation();
                    closeActivityDialog();
                    return;
                }

                if (event.target === dialog) {
                    event.preventDefault();
                    event.stopImmediatePropagation();
                }
            }, true);

            dialog.addEventListener('cancel', (event) => {
                event.preventDefault();
                event.stopImmediatePropagation();
            });

            dialog.addEventListener('ci-activity-created', (event) => {
                const destination = event instanceof CustomEvent ? event.detail?.redirect : null;
                if (typeof destination !== 'string' || destination === '') return;
                closeActivityDialog(() => window.location.assign(destination));
            });

            if (dialog.hasAttribute('data-ci-activity-initial-open')) {
                dialog.removeAttribute('open');
                if (dialog.hasAttribute('data-ci-activity-validation-open')) {
                    dialog.showModal();
                    dialogBody.scrollTop = 0;
                    dialog.dataset.modalState = 'open';
                } else {
                    showActivityDialog();
                }
            }
        });

        document.addEventListener('DOMContentLoaded', () => {
            const layout = document.querySelector('[data-ci-activities-layout]');
            const shell = document.querySelector('[data-ci-history-shell]');
            const panel = document.querySelector('[data-ci-history-panel]');
            const hideButton = document.querySelector('[data-ci-history-hide]');
            const showButton = document.querySelector('[data-ci-history-show]');
            const desktopColumns = 'xl:grid-cols-[minmax(0,4fr)_minmax(15rem,1fr)]';
            const storageKey = 'brbi-ci-activities-history-collapsed';
            const initiallyCollapsed = document.documentElement.hasAttribute('data-ci-activities-history-collapsed');
            const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
            let collapseTimer = null;
            if (!(layout instanceof HTMLElement)
                || !(shell instanceof HTMLElement)
                || !(panel instanceof HTMLElement)
                || !(hideButton instanceof HTMLButtonElement)
                || !(showButton instanceof HTMLButtonElement)) return;

            const setExpandedState = (expanded) => {
                hideButton.setAttribute('aria-expanded', expanded ? 'true' : 'false');
                showButton.setAttribute('aria-expanded', expanded ? 'true' : 'false');
            };

            const syncFinalVisibility = (hidden) => {
                shell.hidden = hidden;
                panel.hidden = hidden;
            };

            const syncLayoutClasses = (hidden) => {
                layout.classList.toggle(desktopColumns, ! hidden);
                layout.classList.toggle('gap-5', ! hidden);
            };

            const persistCollapsedState = (collapsed) => {
                try {
                    localStorage.setItem(storageKey, String(collapsed));
                } catch (e) {}
            };

            const finishCollapse = () => {
                collapseTimer = null;
                if (layout.dataset.historyState === 'collapsed') syncFinalVisibility(true);
            };

            const hideHistoryPanel = () => {
                if (collapseTimer !== null) window.clearTimeout(collapseTimer);
                shell.hidden = false;
                showButton.hidden = false;
                layout.dataset.historyState = 'collapsed';
                syncLayoutClasses(true);
                setExpandedState(false);
                persistCollapsedState(true);

                if (reducedMotion) {
                    finishCollapse();
                    return;
                }

                collapseTimer = window.setTimeout(finishCollapse, 220);
            };

            const showHistoryPanel = () => {
                if (collapseTimer !== null) window.clearTimeout(collapseTimer);
                syncFinalVisibility(false);
                showButton.hidden = true;
                syncLayoutClasses(false);
                setExpandedState(true);
                persistCollapsedState(false);

                if (reducedMotion) {
                    layout.dataset.historyState = 'expanded';
                    return;
                }

                layout.dataset.historyState = 'collapsed';
                void shell.offsetHeight;
                window.requestAnimationFrame(() => {
                    layout.dataset.historyState = 'expanded';
                });
            };

            if (initiallyCollapsed) {
                layout.dataset.historyState = 'collapsed';
                syncLayoutClasses(true);
                syncFinalVisibility(true);
                showButton.hidden = false;
                setExpandedState(false);
            } else {
                layout.dataset.historyState = 'expanded';
                syncLayoutClasses(false);
                syncFinalVisibility(false);
                showButton.hidden = true;
                setExpandedState(true);
            }
            document.documentElement.removeAttribute('data-ci-activities-history-collapsed');

            hideButton.addEventListener('click', hideHistoryPanel);
            showButton.addEventListener('click', showHistoryPanel);
        });

        document.addEventListener('DOMContentLoaded', () => {
            const select = document.querySelector('[data-activity-type-select]');
            const selector = document.querySelector('[data-activity-type-selector]');
            const trigger = document.querySelector('[data-activity-type-trigger]');
            const optionsPanel = document.querySelector('[data-activity-type-options]');
            const selectionLabel = document.querySelector('[data-activity-type-label]');
            const options = [...document.querySelectorAll('[data-activity-type-option]')];
            const removeButtons = [...document.querySelectorAll('[data-activity-type-remove]')];
            const fields = document.querySelector('[data-new-activity-type-fields]');
            const input = document.querySelector('[data-new-activity-type-input]');
            const standardFields = [...document.querySelectorAll('[data-standard-activity-field]')];
            const status = document.querySelector('[data-ci-activity-status]');
            const schedule = document.querySelector('[data-ci-activity-schedule]');
            const scheduleTime = document.querySelector('[data-ci-activity-schedule-time]');
            const scheduleHelp = document.querySelector('[data-ci-schedule-help]');
            const remarks = document.querySelector('[data-ci-activity-remarks]');
            const attachmentSection = document.querySelector('[data-activity-create-only]');
            const attachment = document.querySelector('[data-ci-activity-attachment]');
            const attachmentOpen = document.querySelector('[data-ci-activity-attachment-open]');
            const attachmentName = document.querySelector('[data-ci-activity-attachment-name]');
            const attachmentRemove = document.querySelector('[data-ci-activity-attachment-remove]');
            const dialogBody = document.querySelector('[data-ci-activity-dialog-body]');
            const customInfo = document.querySelector('[data-custom-activity-info]');
            const form = document.querySelector('[data-ci-activity-create-form]');
            const submitLabel = document.querySelector('[data-ci-activity-submit-label]');
            const bankTargetSection = document.querySelector('[data-bank-targets-section]');
            const bankTargetRows = document.querySelector('[data-bank-target-rows]');
            const bankTargetTemplate = document.querySelector('[data-bank-target-template]');
            const bankTargetAdd = document.querySelector('[data-bank-target-add]');
            const assetTargetSection = document.querySelector('[data-asset-targets-section]');
            const assetTargetRows = document.querySelector('[data-asset-target-rows]');
            const assetTargetTemplate = document.querySelector('[data-asset-target-template]');
            const assetTargetAdd = document.querySelector('[data-asset-target-add]');
            const bankPrefillCandidates = @js(collect($bankInstitutionPrefillCandidates)->map(fn (array $candidate): array => [
                'inquiry_type' => $candidate['inquiry_type'],
                'institution_name' => $candidate['institution_name'],
                'branch_location' => $candidate['branch_location'],
            ])->values());
            if (!(select instanceof HTMLInputElement)
                || !(selector instanceof HTMLElement)
                || !(trigger instanceof HTMLButtonElement)
                || !(optionsPanel instanceof HTMLElement)
                || !(selectionLabel instanceof HTMLElement)
                || !(fields instanceof HTMLElement)
                || !(input instanceof HTMLInputElement)
                || !(status instanceof HTMLSelectElement)
                || !(schedule instanceof HTMLInputElement)
                || !(scheduleTime instanceof HTMLInputElement)
                || !(remarks instanceof HTMLTextAreaElement)
                || !(attachmentSection instanceof HTMLElement)
                || !(attachment instanceof HTMLInputElement)
                || !(attachmentOpen instanceof HTMLButtonElement)
                || !(attachmentName instanceof HTMLElement)
                || !(attachmentRemove instanceof HTMLButtonElement)
                || !(dialogBody instanceof HTMLElement)
                || !(customInfo instanceof HTMLElement)
                || !(form instanceof HTMLFormElement)
                || !(bankTargetSection instanceof HTMLElement)
                || !(bankTargetRows instanceof HTMLElement)
                || !(bankTargetTemplate instanceof HTMLTemplateElement)
                || !(bankTargetAdd instanceof HTMLButtonElement)
                || !(assetTargetSection instanceof HTMLElement)
                || !(assetTargetRows instanceof HTMLElement)
                || !(assetTargetTemplate instanceof HTMLTemplateElement)
                || !(assetTargetAdd instanceof HTMLButtonElement)) return;

            const addingNewActivityType = () => select.value === @js(App\Models\ActivityDefinition::NEW_TYPE_VALUE);
            const addingBankCoopCheck = () => options.some((option) => option.dataset.value === select.value
                && option.dataset.code === @js(App\Models\ActivityDefinition::BANK_COOP_CHECK_CODE));
            const addingAssetCheck = () => options.some((option) => option.dataset.value === select.value
                && option.dataset.code === @js(App\Models\ActivityDefinition::ASSET_CHECK_CODE));
            let nextBankTargetIndex = Math.max(-1, ...[...bankTargetRows.querySelectorAll('[data-bank-target-index]')]
                .map((row) => Number.parseInt(row.dataset.bankTargetIndex ?? '-1', 10))) + 1;
            let bankPrefillInitialized = @js($bankTargetRowsFromOldInput);

            const currentBankTargetRows = () => [...bankTargetRows.querySelectorAll('[data-bank-target-row]')];
            let nextAssetTargetIndex = Math.max(-1, ...[...assetTargetRows.querySelectorAll('[data-asset-target-index]')]
                .map((row) => Number.parseInt(row.dataset.assetTargetIndex ?? '-1', 10))) + 1;
            const currentAssetTargetRows = () => [...assetTargetRows.querySelectorAll('[data-asset-target-row]')];

            const derivedAssetParentStatus = () => {
                const statuses = currentAssetTargetRows().map((row) => row.querySelector('[data-asset-target-status]')?.value).filter(Boolean);
                if (statuses.length > 0 && statuses.every((targetStatus) => targetStatus === 'completed')) return 'completed';
                if (statuses.includes('follow_up')) return 'follow_up';
                if (statuses.includes('scheduled')) return 'scheduled';
                return 'pending';
            };

            const syncAssetTargetRow = (row) => {
                const active = ! assetTargetSection.hidden;
                const targetStatus = row.querySelector('[data-asset-target-status]');
                const date = row.querySelector('[data-asset-target-date]');
                const time = row.querySelector('[data-asset-target-time]');
                if (!(targetStatus instanceof HTMLSelectElement) || !(date instanceof HTMLInputElement) || !(time instanceof HTMLInputElement)) return;
                row.querySelectorAll('[data-asset-target-control]').forEach((control) => {
                    if (control !== date && control !== time) control.disabled = ! active;
                });
                const supportsSchedule = ['scheduled', 'follow_up'].includes(targetStatus.value);
                if (! supportsSchedule) { date.value = ''; time.value = ''; }
                date.disabled = ! active || ! supportsSchedule;
                if (! active || ! supportsSchedule || date.value === '') time.value = '';
                time.disabled = ! active || ! supportsSchedule || date.value === '';
                syncAttachmentAvailability();
            };

            const syncAssetTargetRows = () => {
                const rows = currentAssetTargetRows();
                rows.forEach((row) => {
                    const remove = row.querySelector('[data-asset-target-remove]');
                    if (remove instanceof HTMLButtonElement) remove.disabled = assetTargetSection.hidden || rows.length === 1;
                    syncAssetTargetRow(row);
                });
                assetTargetAdd.disabled = assetTargetSection.hidden;
            };

            const bindAssetTargetRow = (row) => {
                if (row.dataset.assetTargetBound === 'true') return;
                row.dataset.assetTargetBound = 'true';
                row.querySelector('[data-asset-target-status]')?.addEventListener('change', () => syncAssetTargetRow(row));
                row.querySelector('[data-asset-target-date]')?.addEventListener('input', () => syncAssetTargetRow(row));
                row.querySelector('[data-asset-target-remove]')?.addEventListener('click', () => {
                    if (currentAssetTargetRows().length === 1) return;
                    row.remove();
                    syncAssetTargetRows();
                });
            };

            const addAssetTargetRow = () => {
                assetTargetRows.insertAdjacentHTML('beforeend', assetTargetTemplate.innerHTML.replaceAll('__INDEX__', String(nextAssetTargetIndex++)));
                const row = currentAssetTargetRows().at(-1);
                if (row) { bindAssetTargetRow(row); syncAssetTargetRows(); row.querySelector('select')?.focus(); }
            };

            const syncAssetTargetSection = () => {
                assetTargetSection.hidden = ! addingAssetCheck() || addingNewActivityType();
                currentAssetTargetRows().forEach(bindAssetTargetRow);
                if (! assetTargetSection.hidden && currentAssetTargetRows().length === 0) addAssetTargetRow();
                syncAssetTargetRows();
            };

            const derivedBankParentStatus = () => {
                const statuses = currentBankTargetRows()
                    .map((row) => row.querySelector('[data-bank-target-status]'))
                    .filter((control) => control instanceof HTMLSelectElement)
                    .map((control) => control.value);
                if (statuses.length > 0 && statuses.every((targetStatus) => targetStatus === 'completed')) return 'completed';
                if (statuses.includes('follow_up')) return 'follow_up';
                if (statuses.includes('scheduled')) return 'scheduled';
                return 'pending';
            };

            const syncBankTargetRow = (row) => {
                const active = ! bankTargetSection.hidden;
                const inquiryTypeControl = row.querySelector('[data-bank-target-inquiry-type]');
                const branchField = row.querySelector('[data-bank-target-branch-field]');
                const branchControl = row.querySelector('[data-bank-target-branch]');
                const statusControl = row.querySelector('[data-bank-target-status]');
                const dateControl = row.querySelector('[data-bank-target-date]');
                const timeControl = row.querySelector('[data-bank-target-time]');
                if (!(inquiryTypeControl instanceof HTMLSelectElement)
                    || !(branchField instanceof HTMLElement)
                    || !(branchControl instanceof HTMLInputElement)
                    || !(statusControl instanceof HTMLSelectElement)
                    || !(dateControl instanceof HTMLInputElement)
                    || !(timeControl instanceof HTMLInputElement)) return;

                row.querySelectorAll('[data-bank-target-control]').forEach((control) => {
                    if (control !== dateControl && control !== timeControl) control.disabled = ! active;
                });
                const isLoanInquiry = inquiryTypeControl.value === @js(App\Models\CiActivityBankTarget::INQUIRY_TYPE_LOAN_INQUIRY);
                branchField.hidden = isLoanInquiry;
                if (isLoanInquiry) branchControl.value = '';
                branchControl.disabled = ! active || isLoanInquiry;
                const supportsSchedule = ['scheduled', 'follow_up'].includes(statusControl.value);
                if (! supportsSchedule) {
                    dateControl.value = '';
                    timeControl.value = '';
                }
                dateControl.disabled = ! active || ! supportsSchedule;
                dateControl.required = false;
                const timeEnabled = active && supportsSchedule && dateControl.value !== '';
                if (! timeEnabled) timeControl.value = '';
                timeControl.disabled = ! timeEnabled;
                syncAttachmentAvailability();
            };

            const syncBankTargetRows = () => {
                const rows = currentBankTargetRows();
                rows.forEach((row) => {
                    const remove = row.querySelector('[data-bank-target-remove]');
                    if (remove instanceof HTMLButtonElement) remove.disabled = bankTargetSection.hidden || rows.length === 1;
                    syncBankTargetRow(row);
                });
                bankTargetAdd.disabled = bankTargetSection.hidden;
            };

            const bindBankTargetRow = (row) => {
                if (row.dataset.bankTargetBound === 'true') return;
                row.dataset.bankTargetBound = 'true';
                const statusControl = row.querySelector('[data-bank-target-status]');
                const inquiryTypeControl = row.querySelector('[data-bank-target-inquiry-type]');
                const dateControl = row.querySelector('[data-bank-target-date]');
                const remove = row.querySelector('[data-bank-target-remove]');
                statusControl?.addEventListener('change', () => syncBankTargetRow(row));
                inquiryTypeControl?.addEventListener('change', () => syncBankTargetRow(row));
                dateControl?.addEventListener('input', () => syncBankTargetRow(row));
                remove?.addEventListener('click', () => {
                    if (currentBankTargetRows().length === 1) return;
                    row.remove();
                    syncBankTargetRows();
                });
            };

            const addBankTargetRow = (focus = true) => {
                const html = bankTargetTemplate.innerHTML.replaceAll('__INDEX__', String(nextBankTargetIndex++));
                bankTargetRows.insertAdjacentHTML('beforeend', html);
                const row = currentBankTargetRows().at(-1);
                if (row) {
                    bindBankTargetRow(row);
                    syncBankTargetRows();
                    if (focus) row.querySelector('input')?.focus();
                }

                return row ?? null;
            };

            const normalizeBankPrefill = (value) => value.toLocaleLowerCase().replace(/\s+/g, ' ').trim();
            const applyBankPrefillCandidate = (candidate) => {
                const institution = candidate.institution_name ?? '';
                const inquiryType = candidate.inquiry_type ?? '';
                const branch = candidate.branch_location ?? '';
                const inquiryTypeKey = normalizeBankPrefill(inquiryType);
                const institutionKey = normalizeBankPrefill(institution);
                const branchKey = normalizeBankPrefill(branch);
                if (institutionKey === '') return;

                const rows = currentBankTargetRows();
                const sameTargetType = rows.filter((row) => {
                    const type = row.querySelector('[data-bank-target-inquiry-type]');
                    const name = row.querySelector('[name$="[institution_name]"]');
                    return type instanceof HTMLSelectElement
                        && name instanceof HTMLInputElement
                        && normalizeBankPrefill(type.value) === inquiryTypeKey
                        && normalizeBankPrefill(name.value) === institutionKey;
                });
                const exact = sameTargetType.find((row) => {
                    const location = row.querySelector('[name$="[branch_location]"]');
                    return location instanceof HTMLInputElement && normalizeBankPrefill(location.value) === branchKey;
                });
                if (exact) {
                    return;
                }

                let target = rows.find((row) => {
                    const name = row.querySelector('[name$="[institution_name]"]');
                    const location = row.querySelector('[name$="[branch_location]"]');
                    const type = row.querySelector('[data-bank-target-inquiry-type]');
                    return type instanceof HTMLSelectElement
                        && name instanceof HTMLInputElement
                        && location instanceof HTMLInputElement
                        && type.value === ''
                        && name.value.trim() === ''
                        && location.value.trim() === '';
                });
                target ??= addBankTargetRow(false);
                const name = target?.querySelector('[name$="[institution_name]"]');
                const location = target?.querySelector('[name$="[branch_location]"]');
                const type = target?.querySelector('[data-bank-target-inquiry-type]');
                if (!(type instanceof HTMLSelectElement) || !(name instanceof HTMLInputElement) || !(location instanceof HTMLInputElement)) return;

                if (type.value === '') type.value = inquiryType;
                if (name.value.trim() === '') name.value = institution;
                if (location.value.trim() === '') location.value = branch;
                type.dispatchEvent(new Event('change', { bubbles: true }));
                name.dispatchEvent(new Event('input', { bubbles: true }));
            };

            const bankTargetCollectionIsPristine = () => currentBankTargetRows().every((row) => {
                const name = row.querySelector('[name$="[institution_name]"]');
                const inquiryType = row.querySelector('[data-bank-target-inquiry-type]');
                const location = row.querySelector('[name$="[branch_location]"]');
                const date = row.querySelector('[name$="[scheduled_at]"]');
                const time = row.querySelector('[name$="[scheduled_time]"]');
                const remarks = row.querySelector('[name$="[remarks]"]');

                return [inquiryType, name, location, date, time, remarks].every((control) => ! (control instanceof HTMLInputElement || control instanceof HTMLTextAreaElement || control instanceof HTMLSelectElement) || control.value.trim() === '');
            });

            const initializeBankTargetsFromCibi = () => {
                if (bankPrefillInitialized || bankTargetSection.hidden) return;
                if (! bankTargetCollectionIsPristine()) {
                    bankPrefillInitialized = true;
                    return;
                }

                bankPrefillCandidates.forEach(applyBankPrefillCandidate);
                bankPrefillInitialized = true;
            };

            const syncBankTargetSection = () => {
                bankTargetSection.hidden = ! addingBankCoopCheck() || addingNewActivityType();
                currentBankTargetRows().forEach(bindBankTargetRow);
                if (! bankTargetSection.hidden && currentBankTargetRows().length === 0) addBankTargetRow();
                initializeBankTargetsFromCibi();
                syncBankTargetRows();
            };

            const syncAttachmentName = () => {
                const fileName = attachment.files?.[0]?.name ?? '';
                attachmentName.textContent = fileName || 'No file selected';
                attachmentName.title = fileName || 'No file selected';
                attachmentRemove.hidden = fileName === '';
            };

            const enabledOptions = () => options.filter((option) => option instanceof HTMLButtonElement && option.isConnected && ! option.disabled);

            const closeActivityTypeOptions = (restoreFocus = false) => {
                optionsPanel.hidden = true;
                trigger.setAttribute('aria-expanded', 'false');
                if (restoreFocus) trigger.focus();
            };

            const openActivityTypeOptions = (focusLast = false) => {
                optionsPanel.hidden = false;
                trigger.setAttribute('aria-expanded', 'true');
                const available = enabledOptions();
                (focusLast ? available.at(-1) : available[0])?.focus();
            };

            const chooseActivityType = (option) => {
                select.value = option.dataset.value ?? '';
                selectionLabel.textContent = option.dataset.label ?? 'Select activity type';
                selectionLabel.classList.remove('text-text-muted');
                selectionLabel.classList.add('text-text-main');
                options.forEach((candidate) => candidate.setAttribute('aria-selected', candidate === option ? 'true' : 'false'));
                closeActivityTypeOptions();
                select.dispatchEvent(new Event('change'));
                trigger.focus();
            };

            const resetActivityType = () => {
                select.value = '';
                selectionLabel.textContent = 'Select activity type';
                selectionLabel.classList.remove('text-text-main');
                selectionLabel.classList.add('text-text-muted');
                options.forEach((option) => option.setAttribute('aria-selected', 'false'));
                select.dispatchEvent(new Event('change'));
            };

            const syncScheduleAvailability = () => {
                const parentFieldsEnabled = ! addingNewActivityType() && ! addingBankCoopCheck() && ! addingAssetCheck();
                const enabled = parentFieldsEnabled && ['scheduled', 'follow_up'].includes(status.value);
                if (! enabled) {
                    schedule.value = '';
                    scheduleTime.value = '';
                }
                schedule.disabled = ! enabled;
                scheduleTime.disabled = ! enabled;
                schedule.required = parentFieldsEnabled && status.value === 'scheduled';
                schedule.setAttribute('aria-disabled', enabled ? 'false' : 'true');
                scheduleTime.setAttribute('aria-disabled', enabled ? 'false' : 'true');
                if (scheduleHelp) scheduleHelp.textContent = enabled
                    ? 'Time is optional. Without one, the creator is reminded at 8:00 AM on the selected date.'
                    : 'Available when the status is Scheduled or For Follow-up.';
            };

            const syncAttachmentAvailability = () => {
                const effectiveStatus = addingBankCoopCheck() ? derivedBankParentStatus() : (addingAssetCheck() ? derivedAssetParentStatus() : status.value);
                const enabled = ! addingNewActivityType() && effectiveStatus === 'completed';
                attachmentSection.hidden = ! enabled;
                attachment.disabled = ! enabled;
                if (! enabled && attachment.value !== '') {
                    attachment.value = '';
                    syncAttachmentName();
                }
            };

            const syncNewActivityType = () => {
                const addingNewType = addingNewActivityType();
                const bankCoopCheck = ! addingNewType && addingBankCoopCheck();
                const assetCheck = ! addingNewType && addingAssetCheck();
                const parentFieldsHidden = addingNewType || bankCoopCheck || assetCheck;
                fields.hidden = ! addingNewType;
                input.required = addingNewType;
                input.disabled = ! addingNewType;
                standardFields.forEach((field) => { field.hidden = parentFieldsHidden; });
                customInfo.hidden = ! addingNewType;
                status.disabled = parentFieldsHidden;
                status.required = ! parentFieldsHidden;
                remarks.disabled = parentFieldsHidden;
                form.dataset.submissionMode = addingNewType ? 'activity-type' : 'activity';
                if (submitLabel) submitLabel.textContent = addingNewType ? 'Add Activity Type' : 'Add Activity';

                if (addingNewType) {
                    status.value = 'pending';
                    schedule.value = '';
                    scheduleTime.value = '';
                    remarks.value = '';
                    attachment.value = '';
                    syncAttachmentName();
                }

                syncScheduleAvailability();
                syncAttachmentAvailability();
                syncBankTargetSection();
                syncAssetTargetSection();
                window.requestAnimationFrame(() => { dialogBody.scrollTop = 0; });
            };

            select.addEventListener('change', syncNewActivityType);
            bankTargetAdd.addEventListener('click', addBankTargetRow);
            assetTargetAdd.addEventListener('click', addAssetTargetRow);
            attachmentOpen.addEventListener('click', () => attachment.click());
            attachment.addEventListener('change', syncAttachmentName);
            attachmentRemove.addEventListener('click', () => {
                attachment.value = '';
                syncAttachmentName();
                attachmentOpen.focus();
            });
            trigger.addEventListener('click', () => {
                if (optionsPanel.hidden) openActivityTypeOptions();
                else closeActivityTypeOptions();
            });
            trigger.addEventListener('keydown', (event) => {
                if (! ['ArrowDown', 'ArrowUp'].includes(event.key)) return;
                event.preventDefault();
                openActivityTypeOptions(event.key === 'ArrowUp');
            });
            options.forEach((option) => option.addEventListener('click', () => chooseActivityType(option)));
            optionsPanel.addEventListener('keydown', (event) => {
                if (event.key === 'Escape') {
                    event.preventDefault();
                    closeActivityTypeOptions(true);
                    return;
                }

                if (! ['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(event.key)) return;
                const available = enabledOptions();
                const current = available.indexOf(document.activeElement);
                if (current === -1) return;
                event.preventDefault();
                const next = event.key === 'Home'
                    ? 0
                    : event.key === 'End'
                        ? available.length - 1
                        : (current + (event.key === 'ArrowDown' ? 1 : -1) + available.length) % available.length;
                available[next]?.focus();
            });
            removeButtons.forEach((button) => {
                const dialog = document.getElementById(button.dataset.activityTypeRemoveDialog ?? '');
                const removalForm = dialog?.querySelector('form');
                if (!(dialog instanceof HTMLDialogElement) || !(removalForm instanceof HTMLFormElement)) return;

                button.addEventListener('click', (event) => {
                    event.preventDefault();
                    event.stopPropagation();
                    closeActivityTypeOptions();
                    const currentSelection = dialog.querySelector('[data-activity-type-current-selection]');
                    if (currentSelection instanceof HTMLInputElement) currentSelection.value = select.value;
                    if (! dialog.open) dialog.showModal();
                });

                removalForm.addEventListener('submit', async (event) => {
                    event.preventDefault();
                    if (removalForm.dataset.submitting === 'true') return;
                    removalForm.dataset.submitting = 'true';
                    const confirmButton = removalForm.querySelector('[type="submit"]');
                    if (confirmButton instanceof HTMLButtonElement) {
                        confirmButton.disabled = true;
                        confirmButton.setAttribute('aria-busy', 'true');
                    }

                    try {
                        const response = await fetch(removalForm.action, {
                            method: removalForm.method,
                            body: new FormData(removalForm),
                            credentials: 'same-origin',
                            headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'text/html' },
                        });
                        if (! response.ok) throw new Error('Activity Type removal failed.');

                        const updatedPage = new DOMParser().parseFromString(await response.text(), 'text/html');
                        const definitionId = button.dataset.activityTypeRemove ?? '';
                        if (! updatedPage.querySelector('[data-ci-activity-dialog]')
                            || updatedPage.querySelector(`[data-activity-type-remove="${definitionId}"]`)) {
                            throw new Error('Removed Activity Type is still selectable.');
                        }

                        dialog.close();
                        button.closest('[data-custom-activity-type-row]')?.remove();
                        const customGroup = optionsPanel.querySelector('[data-custom-activity-types]');
                        if (customGroup && ! customGroup.querySelector('[data-activity-type-remove]')) customGroup.remove();
                        dialog.remove();

                        if (select.value === definitionId || addingNewActivityType()) resetActivityType();
                        else syncNewActivityType();
                        trigger.focus();
                    } catch (error) {
                        HTMLFormElement.prototype.submit.call(removalForm);
                    }
                });
            });
            document.addEventListener('click', (event) => {
                if (! selector.contains(event.target)) closeActivityTypeOptions();
            });
            selector.closest('dialog')?.addEventListener('close', () => closeActivityTypeOptions());
            status.addEventListener('change', () => {
                syncScheduleAvailability();
                syncAttachmentAvailability();
            });
            syncNewActivityType();
        });

        document.addEventListener('DOMContentLoaded', () => {
            const form = document.querySelector('[data-ci-activity-create-form]');
            const dialog = form?.closest('[data-ci-activity-dialog]');
            const submitButton = form?.querySelector('[data-ci-activity-submit]');
            const submitLabel = submitButton?.querySelector('[data-ci-activity-submit-label]');
            const dialogBody = form?.querySelector('[data-ci-activity-dialog-body]');
            const activityType = form?.querySelector('[data-activity-type-select]');
            const activityTypeTrigger = form?.querySelector('[data-activity-type-trigger]');
            const activityTypeError = form?.querySelector('[data-ci-activity-type-error]');
            const newActivityType = form?.querySelector('[data-new-activity-type-input]');
            const newActivityTypeError = form?.querySelector('[data-ci-new-activity-type-error]');
            const status = form?.querySelector('[data-ci-activity-status]');
            const statusError = form?.querySelector('[data-ci-activity-status-error]');
            const schedule = form?.querySelector('[data-ci-activity-schedule]');
            const scheduleError = form?.querySelector('[data-ci-activity-schedule-error]');
            const requestError = form?.querySelector('[data-ci-activity-request-error]');
            const requestErrorMessage = form?.querySelector('[data-ci-activity-request-error-message]');
            const bankTargetSection = form?.querySelector('[data-bank-targets-section]');
            const assetTargetSection = form?.querySelector('[data-asset-targets-section]');
            if (!(form instanceof HTMLFormElement)
                || !(dialog instanceof HTMLDialogElement)
                || !(submitButton instanceof HTMLButtonElement)
                || !(dialogBody instanceof HTMLElement)
                || !(activityType instanceof HTMLInputElement)
                || !(activityTypeTrigger instanceof HTMLButtonElement)
                || !(activityTypeError instanceof HTMLElement)
                || !(newActivityType instanceof HTMLInputElement)
                || !(newActivityTypeError instanceof HTMLElement)
                || !(status instanceof HTMLSelectElement)
                || !(statusError instanceof HTMLElement)
                || !(schedule instanceof HTMLInputElement)
                || !(scheduleError instanceof HTMLElement)
                || !(requestError instanceof HTMLElement)
                || !(requestErrorMessage instanceof HTMLElement)
                || !(bankTargetSection instanceof HTMLElement)
                || !(assetTargetSection instanceof HTMLElement)) return;

            const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
            const invalidClasses = ['border-danger', 'ring-2', 'ring-danger/20'];

            const stabilizeDialogFrame = () => {
                if (form.style.height !== '') return;
                const height = form.getBoundingClientRect().height;
                if (height > 0) form.style.height = `${height}px`;
            };

            const releaseDialogFrame = () => form.style.removeProperty('height');

            const resetSubmissionState = () => {
                delete form.dataset.submitting;
                submitButton.disabled = false;
                submitButton.removeAttribute('aria-busy');
                if (submitLabel) submitLabel.textContent = form.dataset.submissionMode === 'activity-type'
                    ? 'Add Activity Type'
                    : 'Add Activity';
            };

            const clearRequestError = () => {
                requestError.hidden = true;
                requestErrorMessage.textContent = '';
            };

            const showRequestError = (message) => {
                requestErrorMessage.textContent = message;
                requestError.hidden = false;
                requestError.focus({ preventScroll: true });
            };

            const setInvalid = (control, error, invalid) => {
                control.classList.toggle(invalidClasses[0], invalid);
                control.classList.toggle(invalidClasses[1], invalid);
                control.classList.toggle(invalidClasses[2], invalid);
                control.setAttribute('aria-invalid', invalid ? 'true' : 'false');
                error.hidden = ! invalid;
            };

            const revealFirstInvalid = (control) => {
                const bodyRect = dialogBody.getBoundingClientRect();
                const controlRect = control.getBoundingClientRect();
                if (controlRect.top < bodyRect.top || controlRect.bottom > bodyRect.bottom) {
                    dialogBody.scrollTo({
                        top: dialogBody.scrollTop + controlRect.top - bodyRect.top - 16,
                        behavior: reducedMotion ? 'auto' : 'smooth',
                    });
                }
                control.focus({ preventScroll: true });
            };

            const validateActivityForm = () => {
                const addingActivityType = form.dataset.submissionMode === 'activity-type';
                const addingBankActivity = ! addingActivityType && ! bankTargetSection.hidden;
                const addingAssetActivity = ! addingActivityType && ! assetTargetSection.hidden;
                const addingMultiTargetActivity = addingBankActivity || addingAssetActivity;
                const missingActivityType = activityType.value === '';
                const missingNewActivityType = addingActivityType && newActivityType.value.trim() === '';
                const missingStatus = ! addingActivityType && ! addingMultiTargetActivity && status.value === '';
                const missingSchedule = ! addingActivityType && ! addingMultiTargetActivity && status.value === 'scheduled' && schedule.value === '';
                let firstInvalidBankTarget = null;
                let firstInvalidAssetTarget = null;

                if (! bankTargetSection.hidden) {
                    bankTargetSection.querySelectorAll('[data-bank-target-row]').forEach((row) => {
                        const inquiryType = row.querySelector('[data-bank-target-inquiry-type]');
                        const inquiryTypeError = row.querySelector('[data-bank-target-inquiry-type-error]');
                        const name = row.querySelector('[name$="[institution_name]"]');
                        const nameError = row.querySelector('[data-bank-target-name-error]');
                        if (!(inquiryType instanceof HTMLSelectElement)
                            || !(inquiryTypeError instanceof HTMLElement)
                            || !(name instanceof HTMLInputElement)
                            || !(nameError instanceof HTMLElement)) return;

                        const missingInquiryType = inquiryType.value === '';
                        const missingName = name.value.trim() === '';
                        setInvalid(inquiryType, inquiryTypeError, missingInquiryType);
                        setInvalid(name, nameError, missingName);
                        if (! firstInvalidBankTarget && missingInquiryType) firstInvalidBankTarget = inquiryType;
                        if (! firstInvalidBankTarget && missingName) firstInvalidBankTarget = name;
                    });
                }

                if (! assetTargetSection.hidden) {
                    assetTargetSection.querySelectorAll('[data-asset-target-row]').forEach((row) => {
                        const location = row.querySelector('[data-asset-target-location]');
                        if (!(location instanceof HTMLInputElement)) return;
                        const missingLocation = location.value.trim() === '';
                        location.classList.toggle('border-danger', missingLocation);
                        location.classList.toggle('ring-2', missingLocation);
                        location.classList.toggle('ring-danger/20', missingLocation);
                        location.setAttribute('aria-invalid', missingLocation ? 'true' : 'false');
                        let error = row.querySelector('[data-asset-target-location-error]');
                        if (missingLocation && !(error instanceof HTMLElement)) {
                            error = document.createElement('p');
                            error.dataset.assetTargetLocationError = '';
                            error.className = 'mt-1.5 text-sm font-semibold text-danger';
                            error.textContent = 'Enter the office, municipality, city, or location.';
                            location.insertAdjacentElement('afterend', error);
                        } else if (! missingLocation) error?.remove();
                        if (! firstInvalidAssetTarget && missingLocation) firstInvalidAssetTarget = location;
                    });
                }

                if (missingActivityType || missingNewActivityType || missingStatus || missingSchedule || firstInvalidBankTarget || firstInvalidAssetTarget) {
                    stabilizeDialogFrame();
                }

                setInvalid(activityTypeTrigger, activityTypeError, missingActivityType);
                setInvalid(newActivityType, newActivityTypeError, missingNewActivityType);
                setInvalid(status, statusError, missingStatus);
                setInvalid(schedule, scheduleError, missingSchedule);

                if (missingActivityType) return activityTypeTrigger;
                if (missingNewActivityType) return newActivityType;
                if (missingStatus) return status;
                if (missingSchedule) return schedule;
                if (firstInvalidBankTarget) return firstInvalidBankTarget;
                if (firstInvalidAssetTarget) return firstInvalidAssetTarget;
                return null;
            };

            activityType.addEventListener('change', () => setInvalid(activityTypeTrigger, activityTypeError, false));
            newActivityType.addEventListener('input', () => setInvalid(newActivityType, newActivityTypeError, false));
            status.addEventListener('change', () => {
                setInvalid(status, statusError, false);
                if (status.value !== 'scheduled') setInvalid(schedule, scheduleError, false);
            });
            schedule.addEventListener('input', () => setInvalid(schedule, scheduleError, false));
            form.addEventListener('input', (event) => {
                const control = event.target;
                if (!(control instanceof HTMLInputElement)) return;
                const row = control.closest('[data-bank-target-row]');
                const assetRow = control.closest('[data-asset-target-row]');
                if (assetRow && control.matches('[data-asset-target-location]')) {
                    control.classList.remove('border-danger', 'ring-2', 'ring-danger/20');
                    control.setAttribute('aria-invalid', 'false');
                    assetRow.querySelector('[data-asset-target-location-error]')?.remove();
                }
                if (! row) return;
                const error = control.matches('[name$="[institution_name]"]')
                    ? row.querySelector('[data-bank-target-name-error]')
                    : null;
                if (error instanceof HTMLElement) setInvalid(control, error, false);
            });
            form.addEventListener('input', clearRequestError);
            form.addEventListener('change', clearRequestError);
            dialog.addEventListener('close', releaseDialogFrame);
            window.addEventListener('resize', releaseDialogFrame);

            form.addEventListener('submit', async (event) => {
                event.preventDefault();
                if (form.dataset.submitting === 'true') {
                    return;
                }

                clearRequestError();
                const firstInvalid = validateActivityForm();
                if (firstInvalid) {
                    revealFirstInvalid(firstInvalid);
                    return;
                }

                form.dataset.submitting = 'true';
                submitButton.disabled = true;
                submitButton.setAttribute('aria-busy', 'true');
                if (submitLabel) submitLabel.textContent = form.dataset.submissionMode === 'activity-type'
                    ? 'Adding Activity Type…'
                    : 'Adding Activity…';

                try {
                    const response = await fetch(form.action, {
                        method: form.method,
                        body: new FormData(form),
                        credentials: 'same-origin',
                        headers: {
                            Accept: 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                    });
                    const payload = await response.json().catch(() => ({}));
                    if (! response.ok) {
                        const validationMessage = Object.values(payload.errors ?? {}).flat().find((message) => typeof message === 'string');
                        throw new Error(validationMessage ?? payload.message ?? 'Unable to add the activity. Please try again.');
                    }
                    if (typeof payload.redirect !== 'string' || payload.redirect === '') {
                        throw new Error('The activity was saved, but the tracker could not be refreshed. Please reload the page.');
                    }

                    if (payload.activity_created === true) {
                        dialog.dispatchEvent(new CustomEvent('ci-activity-created', { detail: { redirect: payload.redirect } }));
                        return;
                    }

                    window.location.assign(payload.redirect);
                } catch (error) {
                    resetSubmissionState();
                    showRequestError(error instanceof Error ? error.message : 'Unable to add the activity. Please try again.');
                }
            });
        });

        document.addEventListener('DOMContentLoaded', () => {
            const completionCheckboxes = [...document.querySelectorAll('[data-ci-activity-completion]')];
            const modal = document.querySelector('[data-quick-complete-modal]');
            const title = modal?.querySelector('[data-quick-complete-title]');
            const error = modal?.querySelector('[data-quick-complete-error]');
            const cancel = modal?.querySelector('[data-quick-complete-cancel]');
            const confirm = modal?.querySelector('[data-quick-complete-confirm]');
            if (!(modal instanceof HTMLDialogElement)
                || !(title instanceof HTMLElement)
                || !(error instanceof HTMLElement)
                || !(cancel instanceof HTMLButtonElement)
                || !(confirm instanceof HTMLButtonElement)) return;

            let pendingCheckbox = null;

            const resetDialog = () => {
                error.hidden = true;
                error.textContent = '';
                confirm.disabled = false;
                confirm.textContent = 'Mark Completed';
            };

            completionCheckboxes.forEach((checkbox) => checkbox.addEventListener('change', () => {
                if (!(checkbox instanceof HTMLInputElement) || ! checkbox.checked) return;
                checkbox.checked = false;

                if (checkbox.dataset.completionKind !== 'default') {
                    const activityId = checkbox.dataset.ciActivityCompletion ?? '';
                    const trackerAttribute = checkbox.dataset.completionKind === 'bank' ? 'data-bank-coop-open' : 'data-asset-check-open';
                    const tracker = document.querySelector(`[${trackerAttribute}="${activityId}"]`);
                    if (tracker instanceof HTMLElement) tracker.click();
                    return;
                }

                pendingCheckbox = checkbox;
                resetDialog();
                title.textContent = `Mark ${checkbox.dataset.completionName ?? 'activity'} as completed?`;
                modal.showModal();
            }));

            cancel.addEventListener('click', () => modal.close());
            modal.addEventListener('close', () => {
                if (pendingCheckbox instanceof HTMLInputElement && ! pendingCheckbox.disabled) pendingCheckbox.checked = false;
                pendingCheckbox = null;
                resetDialog();
            });

            confirm.addEventListener('click', async () => {
                if (!(pendingCheckbox instanceof HTMLInputElement)) return;
                const checkbox = pendingCheckbox;
                const updateUrl = checkbox.dataset.completionUpdateUrl ?? '';
                const trackerUrl = checkbox.dataset.completionTrackerUrl ?? '';
                const formData = new FormData();
                formData.set('_method', 'PUT');
                formData.set('_token', document.querySelector('meta[name="csrf-token"]')?.content ?? '');
                formData.set('co_maker_id', checkbox.dataset.completionCoMakerId ?? '');
                formData.set('expected_updated_at', checkbox.dataset.completionExpectedUpdatedAt ?? '');
                formData.set('status', 'completed');
                formData.set('intent', 'return');
                confirm.disabled = true;
                confirm.textContent = 'Completing…';
                checkbox.disabled = true;
                checkbox.checked = false;
                error.hidden = true;

                try {
                    const response = await fetch(updateUrl, {
                        method: 'POST',
                        body: formData,
                        credentials: 'same-origin',
                        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    });
                    if (! response.ok) {
                        const payload = await response.json().catch(() => ({}));
                        throw new Error(Object.values(payload.errors ?? {}).flat().join(' ') || 'Unable to complete this activity.');
                    }

                    checkbox.checked = true;
                    document.dispatchEvent(new CustomEvent('ci-default-check-refresh-request', {
                        detail: { activityId: checkbox.dataset.ciActivityCompletion ?? '', url: trackerUrl },
                    }));
                    modal.close();
                } catch (requestError) {
                    checkbox.checked = false;
                    checkbox.disabled = false;
                    error.textContent = requestError instanceof Error ? requestError.message : 'Unable to complete this activity.';
                    error.hidden = false;
                    confirm.disabled = false;
                    confirm.textContent = 'Mark Completed';
                }
            });
        });

        document.addEventListener('DOMContentLoaded', () => {
            const modal = document.querySelector('[data-bank-coop-modal]');
            const modalBody = modal?.querySelector('[data-bank-coop-modal-body]');
            const modalContext = modal?.querySelector('[data-bank-coop-modal-context]');
            const addButton = modal?.querySelector('[data-bank-coop-modal-add]');
            const closeButtons = [...(modal?.querySelectorAll('[data-bank-coop-modal-close]') ?? [])];
            const openers = [...document.querySelectorAll('[data-bank-coop-open]')];
            if (!(modal instanceof HTMLDialogElement)
                || !(modalBody instanceof HTMLElement)
                || !(modalContext instanceof HTMLElement)
                || !(addButton instanceof HTMLButtonElement)) return;

            let currentActivityId = '';
            let currentUrl = '';
            let currentTrigger = null;

            const loadingMarkup = '<div class="grid min-h-40 place-items-center rounded-card border border-ui-border bg-surface p-6 text-sm font-semibold text-text-muted" role="status">Loading Bank / Coop targets…</div>';
            const statusClasses = {
                pending: ['bg-progress-soft', 'text-progress'],
                scheduled: ['bg-brand-soft', 'text-brand-primary'],
                follow_up: ['bg-[#fff0e7]', 'text-[#c85b12]'],
                completed: ['bg-success-soft', 'text-success'],
            };

            const showLoadError = (message) => {
                modalBody.replaceChildren();
                const panel = document.createElement('div');
                panel.className = 'rounded-card border border-danger/25 bg-danger-soft p-5 text-sm text-danger';
                const text = document.createElement('p');
                text.className = 'font-semibold';
                text.textContent = message;
                panel.append(text);
                if (currentUrl !== '') {
                    const fallback = document.createElement('a');
                    fallback.href = currentUrl;
                    fallback.className = 'ui-button-secondary-compact mt-3 inline-flex';
                    fallback.textContent = 'Open detail page';
                    panel.append(fallback);
                }
                modalBody.append(panel);
            };

            const syncScheduleForm = (form) => {
                const inquiryType = form.querySelector('[data-bank-target-detail-inquiry-type]');
                const branchField = form.querySelector('[data-bank-target-detail-branch-field]');
                const branch = form.querySelector('[data-bank-target-detail-branch]');
                const status = form.querySelector('[data-bank-target-detail-status]');
                const date = form.querySelector('[data-bank-target-detail-date]');
                const time = form.querySelector('[data-bank-target-detail-time]');
                if (!(inquiryType instanceof HTMLSelectElement)
                    || !(branchField instanceof HTMLElement)
                    || !(branch instanceof HTMLInputElement)
                    || !(status instanceof HTMLSelectElement)
                    || !(date instanceof HTMLInputElement)
                    || !(time instanceof HTMLInputElement)) return;

                const sync = () => {
                    const isLoanInquiry = inquiryType.value === @js(App\Models\CiActivityBankTarget::INQUIRY_TYPE_LOAN_INQUIRY);
                    branchField.hidden = isLoanInquiry;
                    if (isLoanInquiry) branch.value = '';
                    branch.disabled = isLoanInquiry;
                    const supportsSchedule = ['scheduled', 'follow_up'].includes(status.value);
                    if (! supportsSchedule) {
                        date.value = '';
                        time.value = '';
                    }
                    date.disabled = ! supportsSchedule;
                    date.required = false;
                    const timeEnabled = supportsSchedule && date.value !== '';
                    if (! timeEnabled) time.value = '';
                    time.disabled = ! timeEnabled;
                };

                status.addEventListener('change', sync);
                inquiryType.addEventListener('change', sync);
                date.addEventListener('input', sync);
                sync();
            };

            const synchronizeTable = (source) => {
                const activityId = source.dataset.bankCoopActivityId ?? '';
                const targetCount = Number.parseInt(source.dataset.bankCoopTargetCount ?? '0', 10);
                const completedCount = Number.parseInt(source.dataset.bankCoopCompletedCount ?? '0', 10);
                const status = source.dataset.bankCoopStatus ?? 'pending';
                const statusLabel = source.dataset.bankCoopStatusLabel ?? 'Pending';
                const progress = document.querySelector(`[data-bank-coop-progress="${activityId}"]`);
                const statusBadge = document.querySelector(`[data-bank-coop-status="${activityId}"]`);
                const completionCheckbox = document.querySelector(`[data-ci-activity-completion="${activityId}"]`);
                const row = progress?.closest('[data-ci-activity-row]');

                if (progress instanceof HTMLElement) {
                    progress.textContent = `${targetCount} ${targetCount === 1 ? 'institution' : 'institutions'} · ${completedCount} completed`;
                }
                if (statusBadge instanceof HTMLElement) {
                    Object.values(statusClasses).flat().forEach((className) => statusBadge.classList.remove(className));
                    (statusClasses[status] ?? statusClasses.pending).forEach((className) => statusBadge.classList.add(className));
                    statusBadge.textContent = statusLabel;
                }
                if (completionCheckbox instanceof HTMLInputElement) {
                    const completed = targetCount > 0 && targetCount === completedCount;
                    completionCheckbox.checked = completed;
                    completionCheckbox.disabled = completed;
                }
                if (row instanceof HTMLTableRowElement) {
                    row.dataset.status = status;
                    row.dataset.sortStatus = statusLabel.toLocaleLowerCase();
                }

                document.dispatchEvent(new CustomEvent('ci-bank-coop-updated', {
                    detail: { activityId },
                }));
            };

            const renderDetail = (html) => {
                const page = new DOMParser().parseFromString(html, 'text/html');
                const source = page.querySelector('[data-bank-coop-modal-source]');
                if (!(source instanceof HTMLElement) || source.dataset.bankCoopActivityId !== currentActivityId) {
                    throw new Error('The exact Bank / Coop activity could not be loaded.');
                }

                modalBody.replaceChildren(...[...source.childNodes].map((node) => document.importNode(node, true)));
                modalContext.textContent = source.dataset.bankCoopContext ?? 'Bank / Coop activity';
                addButton.disabled = false;
                modalBody.querySelectorAll('[data-bank-target-form]').forEach((form) => {
                    if (form instanceof HTMLFormElement) syncScheduleForm(form);
                });
                synchronizeTable(source);
            };

            const loadDetail = async () => {
                modalBody.innerHTML = loadingMarkup;
                addButton.disabled = true;
                try {
                    const response = await fetch(currentUrl, {
                        credentials: 'same-origin',
                        headers: { Accept: 'text/html', 'X-Requested-With': 'XMLHttpRequest' },
                    });
                    if (! response.ok) throw new Error('Unable to load the Bank / Coop activity.');
                    renderDetail(await response.text());
                } catch (error) {
                    showLoadError(error instanceof Error ? error.message : 'Unable to load the Bank / Coop activity.');
                }
            };

            const openChildDialog = (dialog) => {
                if (!(dialog instanceof HTMLDialogElement)) return;
                dialog.showModal();
                dialog.querySelector('input:not([type="hidden"]), select, textarea, button')?.focus();
            };

            openers.forEach((opener) => opener.addEventListener('click', (event) => {
                event.preventDefault();
                currentActivityId = opener.dataset.bankCoopOpen ?? '';
                currentUrl = opener.dataset.bankCoopUrl ?? opener.href;
                currentTrigger = opener;
                modalContext.textContent = 'Loading exact activity context…';
                modal.showModal();
                loadDetail();
            }));

            closeButtons.forEach((button) => button.addEventListener('click', () => modal.close()));
            modal.addEventListener('click', (event) => {
                if (event.target === modal) modal.close();
            });
            modal.addEventListener('close', () => {
                addButton.disabled = true;
                modalBody.innerHTML = loadingMarkup;
                if (currentTrigger instanceof HTMLElement) currentTrigger.focus();
            });
            addButton.addEventListener('click', (event) => {
                event.stopPropagation();
                openChildDialog(modalBody.querySelector('#add-bank-target'));
            });

            modalBody.addEventListener('click', (event) => {
                const trigger = event.target.closest('[data-modal-open]');
                const close = event.target.closest('[data-modal-close]');
                const prefill = event.target.closest('[data-bank-target-prefill]');
                if (close) {
                    event.preventDefault();
                    event.stopPropagation();
                    close.closest('dialog')?.close();
                    return;
                }
                if (prefill instanceof HTMLButtonElement) {
                    event.preventDefault();
                    event.stopPropagation();
                    const dialog = prefill.closest('dialog');
                    const inquiryType = dialog?.querySelector('[name="inquiry_type"]');
                    const institution = dialog?.querySelector('[name="institution_name"]');
                    const branch = dialog?.querySelector('[name="branch_location"]');
                    if (!(inquiryType instanceof HTMLSelectElement) || !(institution instanceof HTMLInputElement) || !(branch instanceof HTMLInputElement)) return;

                    const candidateInstitution = prefill.dataset.institution ?? '';
                    const normalize = (value) => value.toLocaleLowerCase().replace(/\s+/g, ' ').trim();
                    if (institution.value.trim() !== '' && normalize(institution.value) !== normalize(candidateInstitution)) return;
                    if (institution.value.trim() === '') institution.value = candidateInstitution;
                    if (inquiryType.value === '') inquiryType.value = prefill.dataset.inquiryType ?? '';
                    if (branch.value.trim() === '') branch.value = prefill.dataset.branch ?? '';
                    inquiryType.dispatchEvent(new Event('change', { bubbles: true }));
                    institution.dispatchEvent(new Event('input', { bubbles: true }));
                    institution.focus();
                    return;
                }
                if (! trigger) return;

                event.preventDefault();
                event.stopPropagation();
                if (trigger instanceof HTMLInputElement && trigger.type === 'checkbox') trigger.checked = false;
                const dialog = modalBody.querySelector(`#${trigger.dataset.modalOpen}`);
                const followUpId = trigger.dataset.bankTargetFollowUp;
                if (followUpId && dialog instanceof HTMLDialogElement) {
                    const status = dialog.querySelector('[data-bank-target-detail-status]');
                    if (status instanceof HTMLSelectElement) {
                        status.value = 'follow_up';
                        status.dispatchEvent(new Event('change'));
                    }
                }
                openChildDialog(dialog);
            });

            modalBody.addEventListener('submit', async (event) => {
                const form = event.target;
                if (!(form instanceof HTMLFormElement)) return;
                event.preventDefault();
                event.stopPropagation();

                const submitter = event.submitter instanceof HTMLButtonElement ? event.submitter : null;
                submitter?.setAttribute('disabled', 'disabled');
                form.querySelector('[data-bank-coop-form-error]')?.remove();

                try {
                    const response = await fetch(form.action, {
                        method: form.method,
                        body: new FormData(form),
                        credentials: 'same-origin',
                        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    });
                    if (! response.ok) {
                        const payload = await response.json().catch(() => ({}));
                        const validationMessage = Object.values(payload.errors ?? {}).flat().find((message) => typeof message === 'string');
                        throw new Error(validationMessage ?? payload.message ?? 'Unable to save the Bank / Coop target.');
                    }
                    renderDetail(await response.text());
                } catch (error) {
                    const alert = document.createElement('div');
                    alert.dataset.bankCoopFormError = '';
                    alert.className = 'mx-5 mt-4 rounded-control border border-danger/25 bg-danger-soft px-3 py-2 text-sm font-semibold text-danger sm:mx-6';
                    alert.setAttribute('role', 'alert');
                    alert.tabIndex = -1;
                    alert.textContent = error instanceof Error ? error.message : 'Unable to save the Bank / Coop target.';
                    form.prepend(alert);
                    alert.focus();
                    if (submitter?.isConnected) submitter.removeAttribute('disabled');
                }
            });
        });

        document.addEventListener('DOMContentLoaded', () => {
            const rows = [...document.querySelectorAll('[data-ci-activity-row]')];
            const tabs = [...document.querySelectorAll('[data-ci-activity-tab]')];
            const sortButtons = [...document.querySelectorAll('[data-ci-sort]')];
            const tableBody = document.querySelector('[data-ci-activities-body]');
            const emptyState = document.querySelector('[data-ci-empty-state]');
            const emptyFresh = document.querySelector('[data-ci-empty-fresh]');
            const emptyFilter = document.querySelector('[data-ci-empty-filter]');
            const emptySearch = document.querySelector('[data-ci-empty-search]');
            const searchInput = document.querySelector('[data-ci-activity-search]');
            const searchClear = document.querySelector('[data-ci-activity-search-clear]');
            if (!(searchInput instanceof HTMLInputElement)
                || !(searchClear instanceof HTMLButtonElement)
                || !(emptySearch instanceof HTMLElement)) return;

            let activeFilter = @js($filter);
            let activeSort = null;
            let sortDirection = 'ascending';
            let searchQuery = '';
            let searchTimer = null;

            const normalizeSearch = (value) => value.toLocaleLowerCase().replace(/\s+/g, ' ').trim();
            rows.forEach((row) => { row.dataset.searchText = normalizeSearch(row.textContent ?? ''); });

            const matchesFilter = (row, filter) => {
                if (filter === 'all') return true;
                if (filter === 'scheduled_today') return row.dataset.scheduledToday === 'true';
                return row.dataset.status === filter;
            };

            const matchesSearch = (row) => searchQuery === '' || (row.dataset.searchText ?? '').includes(searchQuery);

            const updateSortIndicators = () => {
                sortButtons.forEach((button) => {
                    const selected = button.dataset.ciSort === activeSort;
                    const header = button.closest('[data-ci-sort-header]');
                    if (header) header.setAttribute('aria-sort', selected ? sortDirection : 'none');
                    button.querySelector('[data-sort-arrow="asc"]')?.classList.toggle('opacity-30', !selected || sortDirection !== 'ascending');
                    button.querySelector('[data-sort-arrow="desc"]')?.classList.toggle('opacity-30', !selected || sortDirection !== 'descending');
                });
            };

            const sortRows = () => {
                if (!activeSort || !(tableBody instanceof HTMLTableSectionElement)) return;

                const numeric = ['schedule', 'updated'].includes(activeSort);
                const multiplier = sortDirection === 'ascending' ? 1 : -1;
                rows.sort((left, right) => {
                    const leftValue = left.dataset[`sort${activeSort.charAt(0).toUpperCase()}${activeSort.slice(1)}`] ?? '';
                    const rightValue = right.dataset[`sort${activeSort.charAt(0).toUpperCase()}${activeSort.slice(1)}`] ?? '';
                    const comparison = numeric
                        ? Number(leftValue) - Number(rightValue)
                        : leftValue.localeCompare(rightValue, undefined, { numeric: true, sensitivity: 'base' });
                    if (comparison !== 0) return comparison * multiplier;
                    return Number(left.dataset.ciActivityId ?? 0) - Number(right.dataset.ciActivityId ?? 0);
                });
                rows.forEach((row) => tableBody.insertBefore(row, emptyState));
                updateSortIndicators();
            };

            const applyFilter = (filter, updateUrl = true) => {
                activeFilter = filter;
                const visibleRows = rows.filter((row) => {
                    row.hidden = !matchesFilter(row, filter) || !matchesSearch(row);
                    return !row.hidden;
                });

                tabs.forEach((tab) => {
                    const selected = tab.dataset.filter === filter;
                    tab.dataset.active = selected ? 'true' : 'false';
                    tab.setAttribute('aria-current', selected ? 'page' : 'false');
                });
                if (emptyState instanceof HTMLTableRowElement) emptyState.hidden = visibleRows.length > 0;
                if (emptyFresh instanceof HTMLElement) emptyFresh.hidden = rows.length > 0;
                if (emptyFilter instanceof HTMLElement) emptyFilter.hidden = rows.length === 0 || searchQuery !== '';
                emptySearch.hidden = rows.length === 0 || searchQuery === '' || visibleRows.length > 0;
                sortRows();

                if (updateUrl) {
                    const url = new URL(window.location.href);
                    url.searchParams.set('status', filter);
                    window.history.replaceState({}, '', url);
                }
            };

            const applySearch = () => {
                searchTimer = null;
                searchQuery = normalizeSearch(searchInput.value);
                searchClear.hidden = searchQuery === '';
                applyFilter(activeFilter, false);
            };

            searchInput.addEventListener('input', () => {
                searchClear.hidden = normalizeSearch(searchInput.value) === '';
                if (searchTimer !== null) window.clearTimeout(searchTimer);
                searchTimer = window.setTimeout(applySearch, 225);
            });
            searchInput.addEventListener('keydown', (event) => {
                if (event.key !== 'Escape' || searchInput.value === '') return;
                event.preventDefault();
                searchClear.click();
            });
            searchClear.addEventListener('click', () => {
                if (searchTimer !== null) window.clearTimeout(searchTimer);
                searchInput.value = '';
                applySearch();
                searchInput.focus();
            });
            tabs.forEach((tab) => tab.addEventListener('click', (event) => {
                event.preventDefault();
                applyFilter(tab.dataset.filter ?? 'all');
            }));
            sortButtons.forEach((button) => button.addEventListener('click', () => {
                const key = button.dataset.ciSort;
                if (!key) return;
                sortDirection = activeSort === key && sortDirection === 'ascending' ? 'descending' : 'ascending';
                activeSort = key;
                sortRows();
            }));
            document.addEventListener('ci-bank-coop-updated', (event) => {
                const activityId = String(event.detail?.activityId ?? '');
                const row = rows.find((candidate) => candidate.dataset.ciActivityId === activityId);
                if (!(row instanceof HTMLTableRowElement)) return;

                row.dataset.searchText = normalizeSearch(row.textContent ?? '');
                row.hidden = !matchesFilter(row, activeFilter) || !matchesSearch(row);
                const visibleRows = rows.filter((candidate) => !candidate.hidden);
                if (emptyState instanceof HTMLTableRowElement) emptyState.hidden = visibleRows.length > 0;
                if (emptyFresh instanceof HTMLElement) emptyFresh.hidden = rows.length > 0;
                if (emptyFilter instanceof HTMLElement) emptyFilter.hidden = rows.length === 0 || searchQuery !== '';
                emptySearch.hidden = rows.length === 0 || searchQuery === '' || visibleRows.length > 0;
                sortRows();
            });
            applyFilter(activeFilter, false);
        });
    </script>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const modal = document.querySelector('[data-asset-check-modal]');
            const body = modal?.querySelector('[data-asset-check-modal-body]');
            const context = modal?.querySelector('[data-asset-check-modal-context]');
            const add = modal?.querySelector('[data-asset-check-modal-add]');
            const openers = [...document.querySelectorAll('[data-asset-check-open]')];
            if (!(modal instanceof HTMLDialogElement) || !(body instanceof HTMLElement) || !(context instanceof HTMLElement) || !(add instanceof HTMLButtonElement)) return;
            let currentUrl = '';
            let currentOpener = null;

            const bindSchedule = (form) => {
                const status = form.querySelector('[data-asset-detail-status]'); const date = form.querySelector('[data-asset-detail-date]'); const time = form.querySelector('[data-asset-detail-time]');
                if (!(status instanceof HTMLSelectElement) || !(date instanceof HTMLInputElement) || !(time instanceof HTMLInputElement)) return;
                const sync = () => { const enabled = ['scheduled', 'follow_up'].includes(status.value); if (! enabled) { date.value = ''; time.value = ''; } date.disabled = ! enabled; if (! enabled || date.value === '') time.value = ''; time.disabled = ! enabled || date.value === ''; };
                status.addEventListener('change', sync); date.addEventListener('input', sync); sync();
            };

            const render = (html) => {
                const page = new DOMParser().parseFromString(html, 'text/html');
                const source = page.querySelector('[data-asset-check-modal-source]');
                if (!(source instanceof HTMLElement)) throw new Error('Asset Check tracker could not be loaded.');
                body.replaceChildren(...[...source.childNodes].map((node) => document.importNode(node, true)));
                context.textContent = source.dataset.assetContext ?? 'Asset Check';
                add.disabled = false;
                const activityId = source.dataset.assetActivityId ?? '';
                const progress = document.querySelector(`[data-asset-check-progress="${activityId}"]`);
                const completionCheckbox = document.querySelector(`[data-ci-activity-completion="${activityId}"]`);
                const targetCount = Number.parseInt(source.dataset.assetTargetCount ?? '0', 10);
                const completedCount = Number.parseInt(source.dataset.assetCompletedCount ?? '0', 10);
                if (progress) progress.textContent = `${source.dataset.assetTargetCount ?? '0'} assessor${source.dataset.assetTargetCount === '1' ? '' : 's'} · ${source.dataset.assetCompletedCount ?? '0'} completed`;
                const row = currentOpener?.closest('tr');
                const badge = row?.querySelector('td:nth-child(3) span');
                if (row) row.dataset.status = source.dataset.assetStatus ?? row.dataset.status;
                if (badge) {
                    badge.textContent = source.dataset.assetStatusLabel ?? badge.textContent;
                    badge.className = `inline-flex rounded-full px-2.5 py-1 text-xs font-bold ${source.dataset.assetStatus === 'completed' ? 'bg-success-soft text-success' : (source.dataset.assetStatus === 'follow_up' ? 'bg-[#fff0e7] text-[#c85b12]' : (source.dataset.assetStatus === 'scheduled' ? 'bg-brand-soft text-brand-primary' : 'bg-progress-soft text-progress'))}`;
                }
                if (completionCheckbox instanceof HTMLInputElement) {
                    const completed = targetCount > 0 && targetCount === completedCount;
                    completionCheckbox.checked = completed;
                    completionCheckbox.disabled = completed;
                }
                body.querySelectorAll('[data-asset-target-form]').forEach((form) => bindSchedule(form));
                document.dispatchEvent(new CustomEvent('ci-asset-check-updated', { detail: { activityId } }));
                document.dispatchEvent(new CustomEvent('ci-bank-coop-updated', { detail: { activityId } }));
            };

            const load = async (url) => {
                body.innerHTML = '<div class="grid min-h-40 place-items-center rounded-card border border-ui-border bg-surface p-6 text-sm font-semibold text-text-muted" role="status">Loading assessor targets…</div>';
                const response = await fetch(url, { headers: { Accept: 'text/html', 'X-Requested-With': 'XMLHttpRequest' } });
                if (! response.ok) throw new Error('Asset Check tracker could not be loaded.');
                currentUrl = response.url || url;
                render(await response.text());
            };

            openers.forEach((opener) => opener.addEventListener('click', async (event) => {
                event.preventDefault(); currentOpener = opener; currentUrl = opener.dataset.assetCheckUrl ?? opener.href;
                if (! modal.open) modal.showModal();
                try { await load(currentUrl); } catch (error) { body.innerHTML = `<div class="rounded-card border border-danger/30 bg-danger-soft p-5 text-sm font-semibold text-danger">${error.message}</div>`; }
            }));
            modal.querySelectorAll('[data-asset-check-modal-close]').forEach((button) => button.addEventListener('click', () => { modal.close(); currentOpener?.focus(); }));
            add.addEventListener('click', () => { const dialog = body.querySelector('#add-asset-target'); if (dialog instanceof HTMLDialogElement) dialog.showModal(); });
            body.addEventListener('click', (event) => {
                const trigger = event.target.closest('[data-asset-modal-open]'); const close = event.target.closest('[data-asset-modal-close]');
                if (close) { close.closest('dialog')?.close(); return; }
                if (! trigger) return;
                event.preventDefault();
                if (trigger.matches('[data-asset-target-complete]')) trigger.checked = false;
                const dialog = body.querySelector(`#${trigger.dataset.assetModalOpen}`); if (dialog instanceof HTMLDialogElement) dialog.showModal();
            });
            body.addEventListener('submit', async (event) => {
                const form = event.target;
                if (!(form instanceof HTMLFormElement) || ! form.matches('[data-asset-target-form]')) return;
                event.preventDefault();
                const submit = form.querySelector('[type="submit"]'); if (submit instanceof HTMLButtonElement) submit.disabled = true;
                const response = await fetch(form.action, { method: form.method, body: new FormData(form), headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
                if (! response.ok) { if (submit instanceof HTMLButtonElement) submit.disabled = false; return; }
                await load(response.url || currentUrl);
            });
        });
    </script>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const modal = document.querySelector('[data-default-check-modal]');
            const body = modal?.querySelector('[data-default-check-modal-body]');
            const context = modal?.querySelector('[data-default-check-modal-context]');
            const openers = [...document.querySelectorAll('[data-default-check-open]')];
            if (!(modal instanceof HTMLDialogElement) || !(body instanceof HTMLElement) || !(context instanceof HTMLElement)) return;

            let currentUrl = '';
            let currentOpener = null;
            const loadingMarkup = '<div class="grid min-h-40 place-items-center rounded-card border border-ui-border bg-surface p-6 text-sm font-semibold text-text-muted" role="status">Loading activity tracker…</div>';
            const statusClasses = {
                pending: ['bg-progress-soft', 'text-progress'],
                scheduled: ['bg-brand-soft', 'text-brand-primary'],
                follow_up: ['bg-[#fff0e7]', 'text-[#c85b12]'],
                completed: ['bg-success-soft', 'text-success'],
            };

            const synchronizeTable = (source) => {
                const activityId = source.dataset.defaultCheckActivityId ?? '';
                const status = source.dataset.defaultCheckStatus ?? 'pending';
                const row = document.querySelector(`[data-ci-activity-row] [data-ci-activity-status-badge="${activityId}"]`)?.closest('[data-ci-activity-row]');
                const badge = document.querySelector(`[data-ci-activity-status-badge="${activityId}"]`);
                const scheduleCell = document.querySelector(`[data-ci-activity-schedule-cell="${activityId}"]`);
                const updatedCell = document.querySelector(`[data-ci-activity-updated-cell="${activityId}"]`);
                const summary = document.querySelector(`[data-default-check-summary="${activityId}"]`);
                const completionCheckbox = document.querySelector(`[data-ci-activity-completion="${activityId}"]`);

                if (badge instanceof HTMLElement) {
                    Object.values(statusClasses).flat().forEach((className) => badge.classList.remove(className));
                    (statusClasses[status] ?? statusClasses.pending).forEach((className) => badge.classList.add(className));
                    badge.textContent = source.dataset.defaultCheckStatusLabel ?? 'Pending';
                }
                if (scheduleCell instanceof HTMLElement) {
                    scheduleCell.replaceChildren();
                    const schedule = source.dataset.defaultCheckSchedule ?? '—';
                    if (schedule !== '—') {
                        const date = document.createElement('span');
                        date.className = 'block font-semibold text-text-main';
                        date.textContent = schedule;
                        scheduleCell.append(date, document.createTextNode(source.dataset.defaultCheckScheduleTime ?? ''));
                    } else {
                        scheduleCell.textContent = '—';
                    }
                }
                if (updatedCell instanceof HTMLElement) {
                    updatedCell.replaceChildren();
                    const date = document.createElement('span');
                    date.className = 'block font-semibold text-text-main';
                    date.textContent = source.dataset.defaultCheckUpdatedDate ?? '';
                    updatedCell.append(date, document.createTextNode(source.dataset.defaultCheckUpdatedDetail ?? ''));
                }
                if (summary instanceof HTMLElement) summary.textContent = `${source.dataset.defaultCheckStatusLabel ?? 'Pending'} · ${source.dataset.defaultCheckRemarks || 'No remarks yet'}`;
                if (completionCheckbox instanceof HTMLInputElement) {
                    const completed = status === 'completed';
                    completionCheckbox.checked = completed;
                    completionCheckbox.disabled = completed;
                    completionCheckbox.dataset.completionExpectedUpdatedAt = source.dataset.defaultCheckUpdatedIso ?? completionCheckbox.dataset.completionExpectedUpdatedAt;
                }
                if (row instanceof HTMLTableRowElement) {
                    row.dataset.status = status;
                    row.dataset.sortStatus = (source.dataset.defaultCheckStatusLabel ?? status).toLocaleLowerCase();
                    row.dataset.sortSchedule = source.dataset.defaultCheckSortSchedule ?? '0';
                    row.dataset.sortUpdated = source.dataset.defaultCheckUpdatedTimestamp ?? row.dataset.sortUpdated;
                }
                document.dispatchEvent(new CustomEvent('ci-bank-coop-updated', { detail: { activityId } }));
            };

            document.addEventListener('ci-default-check-refresh-request', async (event) => {
                const url = String(event.detail?.url ?? '');
                const activityId = String(event.detail?.activityId ?? '');
                if (url === '' || activityId === '') return;
                const response = await fetch(url, { credentials: 'same-origin', headers: { Accept: 'text/html', 'X-Requested-With': 'XMLHttpRequest' } });
                if (! response.ok) return;
                const page = new DOMParser().parseFromString(await response.text(), 'text/html');
                const source = page.querySelector('[data-default-check-modal-source]');
                if (source instanceof HTMLElement && source.dataset.defaultCheckActivityId === activityId) synchronizeTable(source);
            });

            const bindSource = (source) => {
                const form = body.querySelector('[data-default-check-form]');
                if (!(form instanceof HTMLFormElement)) return;
                const status = form.querySelector('[data-default-check-status-control]');
                const date = form.querySelector('[data-default-check-date]');
                const time = form.querySelector('[data-default-check-time]');
                const completion = body.querySelector('[data-default-check-completion]');
                if (!(status instanceof HTMLSelectElement) || !(date instanceof HTMLInputElement) || !(time instanceof HTMLInputElement)) return;

                const syncSchedule = () => {
                    const enabled = ['scheduled', 'follow_up'].includes(status.value);
                    if (! enabled) { date.value = ''; time.value = ''; }
                    date.disabled = ! enabled;
                    if (! enabled || date.value === '') time.value = '';
                    time.disabled = ! enabled || date.value === '';
                };
                status.addEventListener('change', syncSchedule);
                date.addEventListener('input', syncSchedule);
                syncSchedule();

                form.addEventListener('submit', async (event) => {
                    event.preventDefault();
                    if (status.value === 'completed' && form.dataset.currentStatus !== 'completed' && form.dataset.completionConfirmed !== 'true' && completion instanceof HTMLDialogElement) {
                        completion.showModal();
                        return;
                    }

                    const submit = form.querySelector('[data-default-check-submit]');
                    const errors = form.querySelector('[data-default-check-errors]');
                    if (submit instanceof HTMLButtonElement) submit.disabled = true;
                    if (errors instanceof HTMLElement) { errors.hidden = true; errors.replaceChildren(); }
                    const response = await fetch(form.action, {
                        method: form.method,
                        body: new FormData(form),
                        credentials: 'same-origin',
                        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    });
                    if (! response.ok) {
                        const payload = await response.json().catch(() => ({}));
                        if (errors instanceof HTMLElement) {
                            errors.textContent = Object.values(payload.errors ?? {}).flat().join(' ') || 'Unable to save this activity.';
                            errors.hidden = false;
                        }
                        if (submit instanceof HTMLButtonElement) submit.disabled = false;
                        return;
                    }
                    await loadDetail();
                });

                body.querySelector('[data-default-check-completion-cancel]')?.addEventListener('click', () => completion?.close());
                body.querySelector('[data-default-check-completion-confirm]')?.addEventListener('click', () => {
                    if (completion instanceof HTMLDialogElement) completion.close();
                    form.dataset.completionConfirmed = 'true';
                    form.requestSubmit();
                });
                body.querySelector('[data-default-check-close]')?.addEventListener('click', () => modal.close());
                synchronizeTable(source);
            };

            const loadDetail = async () => {
                body.innerHTML = loadingMarkup;
                const response = await fetch(currentUrl, { credentials: 'same-origin', headers: { Accept: 'text/html', 'X-Requested-With': 'XMLHttpRequest' } });
                if (! response.ok) throw new Error('Unable to load this activity tracker.');
                const page = new DOMParser().parseFromString(await response.text(), 'text/html');
                const source = page.querySelector('[data-default-check-modal-source]');
                if (!(source instanceof HTMLElement)) throw new Error('The exact activity context could not be loaded.');
                body.replaceChildren(...[...source.childNodes].map((node) => document.importNode(node, true)));
                context.textContent = source.dataset.defaultCheckContext ?? 'Activity tracker';
                bindSource(source);
            };

            openers.forEach((opener) => opener.addEventListener('click', async (event) => {
                event.preventDefault();
                currentUrl = opener.dataset.defaultCheckUrl ?? opener.href ?? '';
                currentOpener = opener;
                context.textContent = 'Loading exact activity context…';
                if (! modal.open) modal.showModal();
                try { await loadDetail(); } catch (error) { body.innerHTML = `<div class="rounded-card border border-danger/25 bg-danger-soft p-5 text-sm font-semibold text-danger">${error instanceof Error ? error.message : 'Unable to load this activity tracker.'}</div>`; }
            }));
            modal.querySelectorAll('[data-default-check-modal-close]').forEach((button) => button.addEventListener('click', () => modal.close()));
            modal.addEventListener('click', (event) => { if (event.target === modal) modal.close(); });
            modal.addEventListener('close', () => { body.innerHTML = loadingMarkup; currentOpener?.focus(); });
        });
    </script>
@endsection
