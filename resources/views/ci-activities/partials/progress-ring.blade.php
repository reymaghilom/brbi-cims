@props(['numerator' => 0, 'denominator' => 0, 'percent' => 0])
@php
    $radius = 15;
    $circumference = 2 * M_PI * $radius;
    $offset = $circumference * (1 - max(0, min(100, $percent)) / 100);
    $ringColorClass = $percent >= 100 && $denominator > 0 ? 'text-success' : 'text-brand-primary';
@endphp
<div class="flex items-center gap-2.5">
    <svg viewBox="0 0 36 36" class="size-9 shrink-0 -rotate-90">
        <circle cx="18" cy="18" r="{{ $radius }}" fill="none" stroke="currentColor" stroke-width="3.5" class="text-ui-border"></circle>
        <circle cx="18" cy="18" r="{{ $radius }}" fill="none" stroke="currentColor" stroke-width="3.5" stroke-linecap="round" class="{{ $ringColorClass }} transition-[stroke-dashoffset]" stroke-dasharray="{{ $circumference }}" stroke-dashoffset="{{ $offset }}"></circle>
    </svg>
    <div class="text-xs leading-tight">
        <p class="font-bold text-text-main">{{ $numerator }}/{{ $denominator }}</p>
        <p class="text-text-muted">{{ $percent }}%</p>
    </div>
</div>
