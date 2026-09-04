@props(['row'])
@if($row->scheduledAt)
    <span class="block font-semibold text-text-main">{{ $row->scheduledAt->timezone(config('cims.display_timezone'))->format('M j, Y') }}</span>
    <span class="block text-text-muted">{{ $row->scheduledHasTime ? $row->scheduledAt->timezone(config('cims.display_timezone'))->format('g:i A') : 'No specific time' }}</span>
@else
    &mdash;
@endif
