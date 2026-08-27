@props(['id', 'activities'])
@php($displayTimezone = config('cims.display_timezone'))

<x-ui.modal :id="$id" title="Recent Activity" description="Newest first." size="max-w-lg">
    <ol class="space-y-0">
        @forelse($activities as $activity)
            <li class="relative ml-3 border-l border-ui-border pb-5 pl-6 last:border-transparent last:pb-0">
                <span class="absolute -left-1.5 top-1.5 size-3 rounded-full border-2 border-surface bg-brand-primary" aria-hidden="true"></span>
                <time class="block text-xs font-semibold text-text-subtle">{{ $activity->created_at->timezone($displayTimezone)->format('M j, Y') }} &middot; {{ $activity->created_at->timezone($displayTimezone)->format('g:i A') }}</time>
                <p class="mt-1 flex items-center gap-1.5 text-sm font-bold text-text-main">
                    <x-ui.icon :name="$activity->icon" size="size-3.5" class="text-brand-primary" />
                    {{ $activity->label }}
                </p>
                @if($activity->detail)
                    <p class="mt-0.5 text-xs font-medium uppercase text-text-main">{{ $activity->detail }}</p>
                @endif
                @if($activity->personContext)
                    <p class="mt-0.5 text-xs text-text-muted">{{ $activity->personContext }}</p>
                @endif
                <p class="mt-1 text-xs text-text-muted">{{ $activity->actorLabel }} {{ $activity->user?->full_name ?? '—' }}</p>
            </li>
        @empty
            <li class="text-sm text-text-muted">No recent activity recorded yet.</li>
        @endforelse
    </ol>
</x-ui.modal>
