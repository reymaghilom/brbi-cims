@props(['createdBy' => null, 'createdAt' => null, 'updatedBy' => null, 'updatedAt' => null])
@php
    $format = fn ($date) => $date?->timezone(config('cims.display_timezone'))->format('M j, Y g:i A');
@endphp

@if($createdBy || $updatedBy)
    <div {{ $attributes->class('flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-text-muted') }}>
        @if($createdBy)
            <span>Created by: <span class="font-semibold text-text-main">{{ $createdBy }}</span>@if($createdAt) &middot; {{ $format($createdAt) }}@endif</span>
        @endif
        @if($updatedBy)
            <span>Last updated by: <span class="font-semibold text-text-main">{{ $updatedBy }}</span>@if($updatedAt) &middot; {{ $format($updatedAt) }}@endif</span>
        @endif
    </div>
@endif
