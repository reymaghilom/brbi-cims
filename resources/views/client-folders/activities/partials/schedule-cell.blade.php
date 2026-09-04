@props(['activity', 'isBankCoopCheck' => false, 'isAssetCheck' => false, 'scheduleSummary' => null])
@if($isBankCoopCheck || $isAssetCheck)
    @if($scheduleSummary && $scheduleSummary->has_schedule)
        <span class="block font-semibold text-text-main">{{ $scheduleSummary->primary_label }}</span>
        <span class="block">{{ $scheduleSummary->scheduled_at->timezone(config('cims.display_timezone'))->format('M j, Y') }}{{ $scheduleSummary->scheduled_has_time ? ' · '.$scheduleSummary->scheduled_at->timezone(config('cims.display_timezone'))->format('g:i A') : '' }}</span>
        @if($scheduleSummary->additional_count > 0)
            <span class="block text-text-muted">+{{ $scheduleSummary->additional_count }} more scheduled</span>
        @endif
    @else
        —
    @endif
@elseif($activity->scheduled_at)
    <span class="block font-semibold text-text-main">{{ $activity->scheduled_at->timezone(config('cims.display_timezone'))->format('M j, Y') }}</span>{{ $activity->scheduled_has_time ? $activity->scheduled_at->timezone(config('cims.display_timezone'))->format('g:i A') : 'No specific time' }}
@elseif($activity->visit_date)
    <span class="block font-semibold text-text-main">{{ $activity->visit_date->format('M j, Y') }}</span>Completed visit
@else
    —
@endif
