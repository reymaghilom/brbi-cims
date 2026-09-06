@php
    $updatedAt = $item->lastUpdatedAt?->timezone($displayTimezone);
@endphp
<li class="p-4" data-report-card>
    <div class="flex flex-wrap items-start justify-between gap-2">
        <span class="inline-flex items-center gap-1.5 rounded-full border border-ui-border bg-surface-muted px-2.5 py-1 text-xs font-normal text-text-main">
            <x-ui.icon :name="$item->typeIcon()" size="size-3.5" class="text-text-subtle" />{{ $item->typeLabel() }}
        </span>
        <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-bold {{ $item->isCompleted ? 'bg-success-soft text-success' : 'bg-progress-soft text-progress' }}">
            <x-ui.icon :name="$item->isCompleted ? 'check-circle' : 'clock'" size="size-3.5" />{{ $item->statusLabel() }}
        </span>
    </div>
    {{-- Same weight/size as the desktop row, so the card reads as the same application. --}}
    <a href="{{ $item->folderUrl() }}" class="mt-2 block break-words text-sm font-bold text-text-main hover:text-brand-primary hover:underline" title="{{ $item->clientName }}">{{ $item->clientName }}</a>
    <p class="mt-0.5 text-xs text-text-muted">{{ $item->personLabel() }}@if($item->personName): {{ $item->personName }}@endif</p>
    @if($item->businessName)<p class="mt-0.5 break-words text-xs text-text-muted">{{ $item->businessName }}</p>@endif
    <p class="mt-1 text-xs text-text-subtle">@if($updatedAt)Last updated {{ $updatedAt->format('M j, Y · g:i A') }}@else Not yet started @endif</p>
    @include('reports._actions', ['item' => $item, 'actionClass' => 'mt-3 flex flex-nowrap items-center gap-2'])
</li>
