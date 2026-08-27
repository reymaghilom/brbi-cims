@props(['id', 'events'])
@php($displayTimezone = config('cims.display_timezone'))

<x-ui.modal :id="$id" title="Folder History" description="Newest first." size="max-w-lg">
    <ol class="space-y-0">
        @forelse($events as $event)
            <li class="relative ml-3 border-l border-ui-border pb-5 pl-6 last:border-transparent last:pb-0">
                <span class="absolute -left-1.5 top-1.5 size-3 rounded-full border-2 border-surface bg-brand-primary" aria-hidden="true"></span>
                <time class="block text-xs font-semibold text-text-subtle">{{ $event->created_at->timezone($displayTimezone)->format('M j, Y') }} &middot; {{ $event->created_at->timezone($displayTimezone)->format('g:i A') }}</time>
                <p class="mt-1 text-sm font-bold text-text-main">
                    @switch($event->action)
                        @case('client_folder.created') Folder Created @break
                        @case('client_folder.renamed') Folder Updated @break
                        @default {{ $event->action }}
                    @endswitch
                </p>
                @if($event->action === 'client_folder.renamed')
                    <p class="mt-0.5 text-sm text-text-muted">{{ data_get($event->metadata, 'previous_name') }} &rarr; {{ data_get($event->metadata, 'new_name') }}</p>
                @endif
                <p class="mt-1 text-xs text-text-muted">by {{ $event->user?->full_name ?? '—' }}</p>
            </li>
        @empty
            <li class="text-sm text-text-muted">No folder history recorded yet.</li>
        @endforelse
    </ol>
</x-ui.modal>
