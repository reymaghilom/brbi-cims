@props(['event', 'showConnector' => true])
<li class="relative grid grid-cols-[1rem_1fr] gap-3 pb-6 last:pb-0" data-ci-history-entry-id="{{ $event->id }}">
    @if($showConnector)
        <span class="absolute bottom-0 left-[0.4375rem] top-4 border-l border-dashed border-ui-border-strong" aria-hidden="true"></span>
    @endif
    <span @class(['relative z-10 mt-1 size-3.5 rounded-full border-2 border-white shadow-sm', 'bg-success' => $event->tone === 'success', 'bg-brand-primary' => $event->tone === 'progress', 'bg-text-muted' => $event->tone === 'neutral'])></span>
    <div class="min-w-0">
        <p class="text-sm font-bold leading-5 text-text-main">{{ $event->label }}</p>
        @if($event->detail)<p class="mt-1 break-words text-xs leading-5 text-text-muted">{{ $event->detail }}</p>@endif
        <p class="mt-1 text-xs leading-5 text-text-muted">by {{ $event->user?->full_name ?? 'System' }}<br>{{ $event->created_at->timezone(config('cims.display_timezone'))->format('M j, Y · g:i A') }}</p>
    </div>
</li>
