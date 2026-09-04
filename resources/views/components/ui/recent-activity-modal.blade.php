@props(['id', 'activities'])
@php($displayTimezone = config('cims.display_timezone'))

<x-ui.modal :id="$id" title="Recent Activity" description="Newest first." size="max-w-2xl">
    <div>
        @forelse($activities as $activity)
            <div class="border-b border-ui-border px-1 py-4 first:pt-0 last:border-b-0 last:pb-0">
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
            </div>
        @empty
            <p class="rounded-control bg-surface-subtle p-4 text-sm leading-6 text-text-muted">No recent activity recorded yet.</p>
        @endforelse
    </div>
</x-ui.modal>
