@php
    // The Add form restores old input so a refused duplicate never makes the CI retype anything.
    // Edit forms keep rendering the saved row exactly as before.
    $useOldInput = $useOldInput ?? false;
    $localSchedule = $target?->scheduled_at?->timezone(config('cims.display_timezone'));
    $assessorTypeValue = $useOldInput ? old('assessor_type', $target?->assessor_type) : $target?->assessor_type;
    $officeLocationValue = $useOldInput ? old('office_location', $target?->office_location) : $target?->office_location;
    $statusValue = $useOldInput
        ? (App\Enums\ActivityStatus::tryFrom((string) old('status')) ?? $target?->status ?? App\Enums\ActivityStatus::Pending)
        : ($target?->status ?? App\Enums\ActivityStatus::Pending);
    $dateValue = $useOldInput ? old('scheduled_at', $localSchedule?->format('Y-m-d')) : $localSchedule?->format('Y-m-d');
    $timeValue = $useOldInput
        ? old('scheduled_time', $target?->scheduled_has_time ? $localSchedule?->format('H:i') : '')
        : ($target?->scheduled_has_time ? $localSchedule?->format('H:i') : '');
    $remarksValue = $useOldInput ? old('remarks', $target?->remarks) : $target?->remarks;
    $requiresScheduleDate = App\Enums\ActivityStatus::requiresScheduledDate($statusValue);
@endphp
<div class="grid gap-4 sm:grid-cols-2">
    <div><label class="ui-label" for="{{ $prefix }}-assessor-type">Assessor Office / Type</label><select id="{{ $prefix }}-assessor-type" name="assessor_type" class="ui-control" required><option value="">Select assessor office</option>@foreach($assessorTypes as $value => $label)<option value="{{ $value }}" @selected($assessorTypeValue === $value)>{{ $label }}</option>@endforeach</select></div>
    <div><label class="ui-label" for="{{ $prefix }}-office-location">Office / Municipality / City / Location</label><input id="{{ $prefix }}-office-location" name="office_location" value="{{ $officeLocationValue }}" class="ui-control" maxlength="255" required></div>
    <div><label class="ui-label" for="{{ $prefix }}-status">Status</label><select id="{{ $prefix }}-status" name="status" class="ui-control" required data-asset-detail-status data-schedule-status>@foreach($statuses as $status)<option value="{{ $status->value }}" @selected($statusValue === $status)>{{ $status->label() }}</option>@endforeach</select></div>
    <div class="sm:col-span-2 grid gap-3 sm:grid-cols-[minmax(0,2fr)_minmax(8rem,1fr)]"><div><label class="ui-label" for="{{ $prefix }}-date">Schedule / Follow-up Date <x-form.schedule-date-indicator :required="$requiresScheduleDate" /></label><input id="{{ $prefix }}-date" name="scheduled_at" type="date" value="{{ $dateValue }}" class="ui-control" data-asset-detail-date data-schedule-date @required($requiresScheduleDate)></div><div><label class="ui-label" for="{{ $prefix }}-time">Time <span class="font-normal text-text-muted">(optional)</span></label><input id="{{ $prefix }}-time" name="scheduled_time" type="time" value="{{ $timeValue }}" class="ui-control" data-asset-detail-time></div><p class="text-xs leading-5 text-text-muted sm:col-span-2">Date is required for Scheduled and For Follow-up activities. Time is optional.</p></div>
    <div class="sm:col-span-2"><label class="ui-label" for="{{ $prefix }}-remarks">Short Remarks <span class="font-normal text-text-muted">(optional)</span></label><textarea id="{{ $prefix }}-remarks" name="remarks" rows="3" class="ui-control">{{ $remarksValue }}</textarea></div>
</div>
