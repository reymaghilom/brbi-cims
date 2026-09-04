{{--
    Full Field Documentation activity history for one category (Residence or Business), rendered
    inside the "View All" modal — same authoritative $activities collection the compact 5-item
    panel takes(5) off (see _recent-activity.blade.php), just shown in full. Same row style as
    CI Activities' own "all-activity-history" modal body for visual consistency.

    Expected props: $activities.
--}}
@forelse($activities as $event)
    <div class="border-b border-ui-border px-1 py-4 first:pt-0 last:border-b-0 last:pb-0">
        <p class="text-sm font-bold leading-5 text-text-main">{{ $event->label }}</p>
        <p class="mt-1 text-xs leading-5 text-text-muted">{{ $event->user?->full_name ?? '—' }} &middot; {{ $event->created_at->timezone(config('cims.display_timezone'))->format('M j, Y · g:i A') }}</p>
    </div>
@empty
    <p class="rounded-control bg-surface-subtle p-4 text-sm leading-6 text-text-muted">No recent activity yet.</p>
@endforelse
