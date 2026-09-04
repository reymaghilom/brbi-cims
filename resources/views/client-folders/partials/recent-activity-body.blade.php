@php($recentActivityPreview = $recentPersonActivity->take(5))
@if($recentActivityPreview->isEmpty())
    <div class="mt-6 rounded-control bg-surface-subtle p-4 text-sm leading-6 text-text-muted">
        @if($coMakers->isNotEmpty())
            No recent activity recorded yet for {{ $viewingLabel }}.
        @else
            No recent activity recorded yet.
        @endif
    </div>
@else
    <ol class="relative mt-6 space-y-0">
        @foreach($recentActivityPreview as $activity)
            <li class="relative grid grid-cols-[1rem_1fr] gap-3 pb-6 last:pb-0">
                @unless($loop->last)<span class="absolute bottom-0 left-[0.4375rem] top-4 border-l border-dashed border-ui-border-strong" aria-hidden="true"></span>@endunless
                <span class="relative z-10 mt-1 size-3.5 rounded-full border-2 border-white bg-brand-primary shadow-sm" aria-hidden="true"></span>
                <div class="min-w-0">
                    <p class="text-sm font-bold leading-5 text-text-main">{{ $activity->label }}</p>
                    @if($activity->detail)
                        <p class="mt-1 break-words text-xs leading-5 text-text-muted">{{ $activity->detail }}</p>
                    @endif
                    @if($activity->personContext)
                        <p class="mt-1 break-words text-xs leading-5 text-text-muted">{{ $activity->personContext }}</p>
                    @endif
                    <p class="mt-1 text-xs leading-5 text-text-muted">{{ $activity->actorLabel }} {{ $activity->user?->full_name ?? '—' }}<br>{{ $activity->created_at->timezone($displayTimezone)->format('M j, Y · g:i A') }}</p>
                </div>
            </li>
        @endforeach
    </ol>
@endif
