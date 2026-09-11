@props(['id', 'activities'])
@php($displayTimezone = config('cims.display_timezone'))

<x-ui.modal :id="$id" title="Recent Activity" description="Newest first." size="max-w-2xl">
    <ol class="relative space-y-0" aria-label="Complete recent activity history">
        @forelse($activities as $activity)
            <li class="relative grid min-w-0 grid-cols-[1rem_minmax(0,1fr)] gap-3 pb-6 last:pb-0">
                @unless($loop->last)<span class="absolute bottom-0 left-[0.4375rem] top-4 border-l border-dashed border-ui-border-strong" aria-hidden="true"></span>@endunless
                <span class="relative z-10 mt-1 size-3.5 rounded-full border-2 border-white bg-brand-primary shadow-sm" aria-hidden="true"></span>
                <article class="min-w-0 rounded-control border border-ui-border bg-surface-subtle px-3.5 py-3">
                    <p class="text-sm font-bold leading-5 text-text-main">{{ $activity->label }}</p>
                    @if($activity->detail)
                        <p class="mt-1 break-words text-xs leading-5 text-text-muted">{{ $activity->detail }}</p>
                    @endif
                    @if($activity->personContext)
                        <p class="mt-1 break-words text-xs leading-5 text-text-muted">{{ $activity->personContext }}</p>
                    @endif
                    @if($activity->changedFieldsLabel ?? null)
                        <p class="mt-1 break-words text-xs leading-5 text-text-muted">Updated: {{ $activity->changedFieldsLabel }}</p>
                    @endif
                    <p class="mt-1 text-xs leading-5 text-text-muted">{{ $activity->actorLabel }} {{ $activity->user?->full_name ?? '—' }} &middot; {{ $activity->created_at->timezone($displayTimezone)->format('M j, Y · g:i A') }}</p>
                </article>
            </li>
        @empty
            <li class="rounded-control bg-surface-subtle p-4 text-sm leading-6 text-text-muted">No recent activity recorded yet.</li>
        @endforelse
    </ol>
</x-ui.modal>
