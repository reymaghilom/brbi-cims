@php
    /**
     * Donut drawn with one stroked circle per segment (dash length = that segment's share of the
     * circumference), so it needs no library and no script. Segments are laid out from the top and
     * always describe the same total the centre label shows.
     */
    $total = (int) $workload['total'];
    $segments = collect($workload['segments'])->filter(fn (array $segment): bool => $segment['count'] > 0)->values();

    $radius = 54;
    $circumference = 2 * M_PI * $radius;
    $offset = 0.0;

    $strokes = [
        'brand' => 'var(--color-brand-primary)',
        'amber' => 'var(--color-progress)',
        'danger' => 'var(--color-danger)',
        'success' => 'var(--color-success)',
    ];
    $dots = [
        'brand' => 'bg-brand-primary',
        'amber' => 'bg-progress',
        'danger' => 'bg-danger',
        'success' => 'bg-success',
    ];
@endphp

{{-- The donut sits above the legend rather than beside it: this card is only a quarter of the
     analytics row on wide screens, and sharing that width left the legend too narrow for
     "Needs Attention" to sit clear of its own count. Stacked, the legend always gets the full card
     width and its three columns line up at every breakpoint. --}}
<div class="mt-4 flex flex-col items-center gap-5">
    <div class="relative shrink-0">
        <svg viewBox="0 0 140 140" class="size-36" role="img" aria-label="Workload split across {{ $total }} {{ Str::plural('client', $total) }}.">
            <circle cx="70" cy="70" r="{{ $radius }}" fill="none" stroke="var(--color-ui-border)" stroke-width="18" />
            @foreach($segments as $segment)
                @php
                    $length = $total > 0 ? round($circumference * $segment['count'] / $total, 3) : 0;
                    $dash = $length.' '.round($circumference - $length, 3);
                    $rotation = $total > 0 ? round($offset / $total * 360, 3) : 0;
                    $offset += $segment['count'];
                @endphp
                <circle cx="70" cy="70" r="{{ $radius }}" fill="none" stroke="{{ $strokes[$segment['tone']] }}" stroke-width="18"
                    stroke-dasharray="{{ $dash }}" transform="rotate({{ $rotation - 90 }} 70 70)" />
            @endforeach
        </svg>
        <span class="pointer-events-none absolute inset-0 grid place-content-center text-center">
            <span class="block text-2xl font-bold tabular-nums tracking-tight text-text-main">{{ $total }}</span>
            <span class="block text-xs font-semibold text-text-muted">{{ Str::plural('Client', $total) }}</span>
        </span>
    </div>

    {{-- One grid per row with identical track sizes, so the count and percentage columns align
         down the list instead of drifting with each label's length. --}}
    <ul class="w-full min-w-0 divide-y divide-ui-border">
        @foreach($workload['segments'] as $segment)
            <li class="grid grid-cols-[auto_minmax(0,1fr)_2.5rem_3rem] items-center gap-x-3 py-2.5 text-sm sm:gap-x-4">
                <span class="size-2.5 rounded-full {{ $dots[$segment['tone']] }}" aria-hidden="true"></span>
                <span class="min-w-0 leading-5 text-text-muted">{{ $segment['label'] }}</span>
                <span class="text-right font-bold tabular-nums text-text-main">{{ $segment['count'] }}</span>
                <span class="text-right tabular-nums text-text-subtle">{{ $segment['percent'] }}%</span>
                <span class="sr-only">{{ $segment['label'] }}: {{ $segment['count'] }} {{ Str::plural('client', $segment['count']) }}, {{ $segment['percent'] }} percent.</span>
            </li>
        @endforeach
    </ul>
</div>
