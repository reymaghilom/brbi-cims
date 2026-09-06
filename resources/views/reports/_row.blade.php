@php
    $updatedAt = $item->lastUpdatedAt?->timezone($displayTimezone);
@endphp
<tr>
    <td class="text-text-subtle tabular-nums">{{ $rowNumber }}</td>
    <td>
        {{-- The row's primary label, carrying the same bold weight every other table in the app
             gives its primary column (see the Saved Businesses table). It stays at the row's own
             text-sm size, so it leads the row by weight and colour rather than by size. `title`
             carries the full name for a truncated or wrapped one, the same convention the Client
             Folders tile already uses. --}}
        <a href="{{ $item->folderUrl() }}" class="text-sm font-bold text-text-main hover:text-brand-primary hover:underline" title="{{ $item->clientName }}">{{ $item->clientName }}</a>
        @if($item->businessName)<span class="mt-0.5 block text-xs text-text-subtle">{{ $item->businessName }}</span>@endif
    </td>
    <td>
        <span class="inline-flex items-center gap-1.5 whitespace-nowrap text-text-muted" @if($item->personName) title="Co-Maker: {{ $item->personName }}" @endif>
            <x-ui.icon :name="$item->coMakerId ? 'users' : 'user'" size="size-4" class="text-text-subtle" />{{ $item->personLabel() }}
        </span>
        @if($item->personName)<span class="mt-0.5 block max-w-40 truncate text-xs text-text-subtle">{{ $item->personName }}</span>@endif
    </td>
    <td>
        <span class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-full border border-ui-border bg-surface-muted px-2.5 py-1 text-sm font-normal text-text-main">
            <x-ui.icon :name="$item->typeIcon()" size="size-3.5" class="text-text-subtle" />{{ $item->typeLabel() }}
        </span>
    </td>
    <td>
        <span class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-full px-2.5 py-1 text-xs font-bold {{ $item->isCompleted ? 'bg-success-soft text-success' : 'bg-progress-soft text-progress' }}">
            <x-ui.icon :name="$item->isCompleted ? 'check-circle' : 'clock'" size="size-3.5" />{{ $item->statusLabel() }}
        </span>
    </td>
    <td class="whitespace-nowrap text-text-muted">
        @if($updatedAt)
            <span class="block">{{ $updatedAt->format('M j, Y') }}</span>
            <span class="block text-xs text-text-subtle">{{ $updatedAt->format('g:i A') }}</span>
        @else
            —
        @endif
    </td>
    {{-- w-px keeps the Actions column no wider than its longest action group, and the group
         itself never wraps to a second line. --}}
    <td class="w-px whitespace-nowrap">@include('reports._actions', ['item' => $item, 'actionClass' => 'flex flex-nowrap items-center justify-end gap-1.5'])</td>
</tr>
