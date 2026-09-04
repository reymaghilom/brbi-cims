<div class="flex items-center justify-between gap-2">
    <div class="flex min-w-0 items-center gap-2 text-brand-primary"><x-ui.icon name="clock" size="size-5" /><h2 id="business-recent-activity-title" class="truncate text-sm font-bold text-brand-sidebar">Recent Activity</h2></div>
    <button type="button" class="ui-icon-button -mr-2 -mt-2 shrink-0" title="Hide Recent Activity" aria-label="Hide Recent Activity panel" aria-controls="business-history-panel" aria-expanded="true" data-business-history-hide><x-ui.icon name="close" size="size-4" /></button>
</div>
@if($recentActivity->isEmpty())
    <div class="mt-4 rounded-control bg-surface-subtle p-4 text-sm leading-6 text-text-muted">No recent business activity yet.</div>
@else
    <ol class="relative mt-4 space-y-0">
        @foreach($recentActivity->take(5) as $activity)
            <li class="relative grid grid-cols-[1.75rem_1fr] gap-2.5 pb-5 last:pb-0">
                @unless($loop->last)<span class="absolute bottom-0 left-[0.8125rem] top-7 border-l border-dashed border-ui-border-strong" aria-hidden="true"></span>@endunless
                <span class="relative z-10 grid size-7 shrink-0 place-items-center rounded-full bg-brand-soft text-brand-primary"><x-ui.icon name="{{ $activity->icon }}" size="size-3.5" /></span>
                <div class="min-w-0 pt-0.5">
                    <p class="break-words text-sm font-bold leading-5 text-text-main">{{ $activity->label }}</p>
                    @if($activity->detail)
                        <p class="mt-0.5 break-words text-xs leading-5 text-text-muted">{{ $activity->detail }}</p>
                    @endif
                    @if($activity->personContext)
                        <p class="mt-0.5 break-words text-xs leading-5 text-text-muted">{{ $activity->personContext }}</p>
                    @endif
                    @if($activity->changedFieldsLabel)
                        <p class="mt-0.5 break-words text-xs leading-5 text-text-muted">Updated: {{ $activity->changedFieldsLabel }}</p>
                    @endif
                    <p class="mt-0.5 text-xs leading-5 text-text-muted">{{ $activity->actorLabel }} {{ $activity->user?->full_name ?? '—' }} &middot; {{ $activity->created_at->timezone($displayTimezone)->format('M j, Y · g:i A') }}</p>
                </div>
            </li>
        @endforeach
    </ol>
@endif
<div class="mt-5 border-t border-ui-border pt-4">
    <button type="button" class="w-full text-center text-sm font-bold text-brand-primary hover:underline" data-modal-open="business-recent-activity-dialog">View All</button>
</div>
