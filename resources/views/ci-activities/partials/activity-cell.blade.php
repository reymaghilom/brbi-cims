@props(['row'])
<span class="block font-semibold text-text-main">{{ $row->activityLabel }}</span>
@if($row->targetLabel)
    <span class="block text-xs text-text-muted">{{ $row->targetLabel }}</span>
@endif
@if($row->additionalScheduledCount > 0)
    <span class="mt-1 inline-flex rounded-full bg-surface-muted px-2 py-0.5 text-[0.68rem] font-semibold text-text-muted">+{{ $row->additionalScheduledCount }} more scheduled</span>
@endif
