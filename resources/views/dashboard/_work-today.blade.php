@php
    $statusTones ??= [
        'danger' => 'border-danger/20 bg-danger-soft text-danger',
        'amber' => 'border-progress/20 bg-progress-soft text-progress',
        'brand' => 'border-brand-primary/15 bg-brand-soft text-brand-primary',
        'neutral' => 'border-ui-border bg-surface-muted text-text-muted',
    ];
    $statusIcons ??= [
        'danger' => 'warning',
        'amber' => 'activity',
        'brand' => 'calendar',
        'neutral' => 'clock',
    ];
@endphp

<div class="@container" data-work-today-page="{{ $workToday->currentPage() }}" data-work-today-presentation="modern">
    <header class="flex flex-wrap items-center justify-between gap-3 border-b border-ui-border/80 px-4 py-3.5 sm:px-5 sm:py-4">
        <div class="flex min-w-0 items-center gap-3">
            <span class="grid size-9 shrink-0 place-items-center rounded-control bg-brand-soft text-brand-primary" aria-hidden="true"><x-ui.icon name="activity" size="size-4" /></span>
            <div class="min-w-0">
                <h3 id="work-today-title" class="font-bold leading-5 text-text-main">My Work Today</h3>
                <p class="mt-0.5 text-xs leading-4 text-text-muted">Your CI activities that need action</p>
            </div>
        </div>
        <a href="{{ route('ci-activities.index') }}" class="ui-button-secondary-compact shrink-0" data-work-today-view-all><x-ui.icon name="eye" size="size-4" data-work-today-view-all-icon />View All</a>
    </header>

    @if($workToday->isEmpty())
        <x-ui.empty-state class="m-4 sm:m-5" title="You're all caught up" description="No items need your attention right now." icon="check-circle" />
    @else
        <div class="hidden @min-[44rem]:block" data-work-today-desktop-table>
            <table class="ui-table table-fixed">
                <colgroup>
                    <col class="w-[24%]">
                    <col class="w-[23%]">
                    <col class="w-[19%]">
                    <col class="w-[18%]">
                    <col class="w-[16%]">
                </colgroup>
                <thead class="bg-surface-muted/70 normal-case tracking-normal">
                    <tr>
                        <th scope="col" class="px-3 py-2.5 text-[0.7rem] font-semibold">Client Name</th>
                        <th scope="col" class="px-3 py-2.5 text-[0.7rem] font-semibold">Pending Activity</th>
                        <th scope="col" class="px-3 py-2.5 text-[0.7rem] font-semibold">Status</th>
                        <th scope="col" class="px-3 py-2.5 text-[0.7rem] font-semibold">Last Activity</th>
                        <th scope="col" class="px-3 py-2.5 text-right text-[0.7rem] font-semibold">Action</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($workToday as $item)
                        <tr data-work-today-row>
                            <td class="px-3 py-3">
                                <a href="{{ $item['client_url'] }}" class="cursor-pointer break-words font-semibold leading-5 text-brand-primary transition-colors hover:text-brand-primary-hover hover:underline focus-visible:underline" data-work-today-client-link>{{ $item['client'] }}</a>
                                @if($item['person'])<p class="mt-0.5 break-words text-xs leading-4 text-text-muted" data-work-today-person>Co-Maker: {{ $item['person'] }}</p>@endif
                            </td>
                            <td class="px-3 py-3">
                                <span class="block break-words text-sm font-medium leading-5 text-text-main" data-work-today-activity>{{ $item['activity'] }}</span>
                                @if($item['target'])<span class="mt-0.5 block break-words text-xs leading-4 text-text-subtle">{{ $item['target'] }}</span>@endif
                            </td>
                            <td class="px-3 py-3"><span class="inline-flex min-h-6 items-center gap-1 whitespace-nowrap rounded-full border px-2 py-0.5 text-[0.7rem] font-semibold {{ $statusTones[$item['tone']] }}" data-work-today-status><x-ui.icon :name="$statusIcons[$item['tone']]" size="size-3.5" />{{ $item['status'] }}</span></td>
                            <td class="px-3 py-3" data-work-today-last-activity>
                                <div class="flex items-start gap-2">
                                    <x-ui.icon name="calendar" size="size-4" class="mt-0.5 text-text-subtle" />
                                    <span class="min-w-0">
                                        <span class="block whitespace-nowrap text-xs font-medium leading-4 text-text-main">{{ $item['updated_at']?->timezone(config('cims.display_timezone'))->format('M j, Y') }}</span>
                                        <span class="block whitespace-nowrap text-[0.7rem] leading-4 text-text-muted">{{ $item['updated_at']?->timezone(config('cims.display_timezone'))->format('g:i A') }}</span>
                                    </span>
                                </div>
                            </td>
                            <td class="px-3 py-3 text-right">@include('dashboard._work-today-action', ['item' => $item, 'mobile' => false])</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <ul class="divide-y divide-ui-border @min-[44rem]:hidden" data-work-today-mobile-list>
            @foreach($workToday as $item)
                <li class="p-4 transition-colors hover:bg-surface-muted/50" data-work-today-row>
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <a href="{{ $item['client_url'] }}" class="block cursor-pointer break-words font-semibold leading-5 text-brand-primary hover:underline focus-visible:underline" data-work-today-client-link>{{ $item['client'] }}</a>
                            @if($item['person'])<p class="mt-0.5 break-words text-xs leading-4 text-text-muted" data-work-today-person>Co-Maker: {{ $item['person'] }}</p>@endif
                        </div>
                        <span class="inline-flex min-h-6 shrink-0 items-center gap-1.5 whitespace-nowrap rounded-full border px-2.5 py-0.5 text-[0.7rem] font-semibold {{ $statusTones[$item['tone']] }}" data-work-today-status><x-ui.icon :name="$statusIcons[$item['tone']]" size="size-3.5" />{{ $item['status'] }}</span>
                    </div>
                    <p class="mt-2.5 break-words text-sm font-medium leading-5 text-text-main" data-work-today-activity>{{ $item['activity'] }}</p>
                    @if($item['target'])<p class="mt-0.5 break-words text-xs leading-4 text-text-subtle">{{ $item['target'] }}</p>@endif
                    <div class="mt-2 flex items-center gap-2 text-xs text-text-muted" data-work-today-last-activity>
                        <x-ui.icon name="calendar" size="size-4" class="text-text-subtle" />
                        <span><span class="font-medium text-text-main">{{ $item['updated_at']?->timezone(config('cims.display_timezone'))->format('M j, Y') }}</span><span aria-hidden="true"> · </span>{{ $item['updated_at']?->timezone(config('cims.display_timezone'))->format('g:i A') }}</span>
                    </div>
                    <div class="mt-3 flex justify-end">@include('dashboard._work-today-action', ['item' => $item, 'mobile' => true])</div>
                </li>
            @endforeach
        </ul>

        @if($workToday->hasPages())
            <div class="flex flex-col gap-3 border-t border-ui-border bg-surface-muted/30 px-4 py-3.5 sm:flex-row sm:items-center sm:justify-between sm:px-5">
                <p class="text-xs text-text-muted">Showing <span class="font-semibold text-text-main">{{ $workToday->firstItem() }}–{{ $workToday->lastItem() }}</span> of {{ $workToday->total() }} activities</p>
                <x-ui.compact-pagination :paginator="$workToday" aria-label="My Work Today pagination" data-work-today-pagination />
            </div>
        @endif
    @endif
</div>
