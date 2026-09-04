@props(['scheduledTodayCount', 'scheduledTodayItems', 'scheduledTodayNotifications'])
<div class="w-[min(23rem,calc(100vw-2.75rem))]" data-scheduled-today-panel>
    <div class="border-b border-ui-border px-3 py-2.5">
        <p class="text-sm font-bold leading-5 text-brand-sidebar">Scheduled Today</p>
        @if($scheduledTodayCount > 0)
            <p class="mt-0.5 text-xs leading-4 text-text-muted">{{ $scheduledTodayCount }} {{ Illuminate\Support\Str::plural('activity', $scheduledTodayCount) }}</p>
        @endif
    </div>
    <div class="max-h-[calc(100dvh-10rem)] divide-y divide-ui-border overflow-y-auto overscroll-contain sm:max-h-none sm:overflow-visible" data-scheduled-today-list>
        @forelse($scheduledTodayItems->take(5) as $scheduledItem)
            @php
                $scheduledTimeLabel = $scheduledItem->scheduled_has_time
                    ? $scheduledItem->scheduled_at->timezone(config('cims.display_timezone'))->format('g:i A')
                    : 'Today';
                $scheduledNotification = $scheduledTodayNotifications->get($scheduledItem->key);
                $scheduledNotificationIsUnread = $scheduledNotification !== null && $scheduledNotification->read_at === null;
                $scheduledItemClass = 'block w-full px-3 py-2.5 text-left transition hover:bg-brand-soft focus-visible:bg-brand-soft focus-visible:outline-none'
                    .($scheduledNotificationIsUnread ? ' bg-brand-soft/40' : '');
            @endphp
            @if($scheduledNotification)
                <form method="POST" action="{{ route('notifications.ci-activities.read', $scheduledNotification->id) }}">@csrf
                    <button type="submit" role="menuitem" class="{{ $scheduledItemClass }}" data-scheduled-today-item="{{ $scheduledItem->key }}" data-scheduled-notification-state="{{ $scheduledNotificationIsUnread ? 'unread' : 'read' }}">
            @else
                <a href="{{ $scheduledItem->url }}" role="menuitem" class="{{ $scheduledItemClass }}" data-scheduled-today-item="{{ $scheduledItem->key }}" data-scheduled-notification-state="none">
            @endif
                <span class="block truncate text-sm font-bold leading-5 text-text-main">{{ $scheduledItem->name }}</span>
                <span class="mt-1 flex min-w-0 items-center justify-between gap-3">
                    <span class="min-w-0 truncate text-xs leading-4 text-text-muted" title="{{ $scheduledItem->client_folder_display_name }} · {{ $scheduledItem->person_label }}">{{ $scheduledItem->client_folder_display_name }} <span aria-hidden="true">&middot;</span> {{ $scheduledItem->person_label }}</span>
                    <span class="inline-flex shrink-0 items-center gap-1 text-xs font-bold leading-4 text-brand-primary"><x-ui.icon :name="$scheduledItem->scheduled_has_time ? 'clock' : 'calendar'" size="size-3.5" />{{ $scheduledTimeLabel }}</span>
                </span>
            @if($scheduledNotification)
                    </button>
                </form>
            @else
                </a>
            @endif
        @empty
            <p class="px-3 py-5 text-center text-sm leading-5 text-text-muted">No scheduled CI activities today.</p>
        @endforelse
    </div>
    <div class="border-t border-ui-border p-1.5">
        <a href="{{ route('ci-activities.index') }}" role="menuitem" class="flex min-h-9 items-center justify-center rounded-control px-3 py-1.5 text-xs font-bold text-brand-primary transition hover:bg-brand-soft focus-visible:bg-brand-soft focus-visible:outline-none">View CI Activities</a>
    </div>
</div>
