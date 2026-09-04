@php
    /**
     * Inline SVG rather than a charting dependency: this project ships no chart library, and one
     * line plus one filled area does not justify adding (and bundling) one. The viewBox scales the
     * whole drawing with the card, so it stays sharp and readable at every width.
     */
    $points = collect($trend['points']);
    $count = max(1, $points->count());
    $max = max(1, (int) $trend['max']);

    $width = 720;
    $height = 230;
    $padLeft = 34;
    $padRight = 14;
    $padTop = 16;
    $padBottom = 30;
    $plotWidth = $width - $padLeft - $padRight;
    $plotHeight = $height - $padTop - $padBottom;

    $x = fn (int $index): float => $count === 1
        ? $padLeft + $plotWidth / 2
        : round($padLeft + $index * ($plotWidth / ($count - 1)), 2);
    $y = fn (int $value): float => round($padTop + (1 - ($value / $max)) * $plotHeight, 2);

    $coordinates = $points->values()->map(fn (array $point, int $index): array => [
        'x' => $x($index),
        'y' => $y((int) $point['value']),
        'label' => $point['label'],
        'value' => (int) $point['value'],
    ]);

    $line = $coordinates->map(fn (array $point): string => $point['x'].','.$point['y'])->implode(' ');
    $area = $coordinates->isEmpty() ? '' : 'M '.$coordinates->first()['x'].' '.($padTop + $plotHeight)
        .' L '.$coordinates->map(fn (array $point): string => $point['x'].' '.$point['y'])->implode(' L ')
        .' L '.$coordinates->last()['x'].' '.($padTop + $plotHeight).' Z';

    // Three gridlines is enough to read the scale without turning the card into graph paper.
    $ticks = collect([$max, (int) round($max / 2), 0])->unique()->values();
    // Only ever label as many points as can be read at a glance; 30-day and 12-month ranges thin out.
    $labelStep = (int) max(1, ceil($count / 8));
@endphp

<figure class="mt-4" role="group" aria-label="Completed investigations over the selected period">
    <svg viewBox="0 0 {{ $width }} {{ $height }}" class="h-auto w-full" preserveAspectRatio="xMidYMid meet" role="img"
        aria-label="{{ $trend['total'] }} completed {{ Str::plural('investigation', $trend['total']) }} across {{ $count }} {{ Str::plural('period', $count) }}.">
        @foreach($ticks as $tick)
            @php $tickY = $y((int) $tick); @endphp
            <line x1="{{ $padLeft }}" y1="{{ $tickY }}" x2="{{ $width - $padRight }}" y2="{{ $tickY }}" stroke="var(--color-ui-border)" stroke-width="1" />
            <text x="{{ $padLeft - 8 }}" y="{{ $tickY + 4 }}" text-anchor="end" font-size="11" fill="var(--color-text-subtle)">{{ $tick }}</text>
        @endforeach

        @if($area !== '')
            <path d="{{ $area }}" fill="var(--color-brand-soft)" opacity="0.85" />
        @endif
        @if($coordinates->count() > 1)
            <polyline points="{{ $line }}" fill="none" stroke="var(--color-brand-primary)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" />
        @endif

        @foreach($coordinates as $index => $point)
            <circle cx="{{ $point['x'] }}" cy="{{ $point['y'] }}" r="{{ $index === $coordinates->count() - 1 ? 5 : 3.5 }}"
                fill="var(--color-surface)" stroke="var(--color-brand-primary)" stroke-width="2.5" />
            <title>{{ $point['value'] }} completed · {{ $point['label'] }}</title>
            @if($index % $labelStep === 0 || $index === $coordinates->count() - 1)
                <text x="{{ $point['x'] }}" y="{{ $height - 9 }}" text-anchor="middle" font-size="11" fill="var(--color-text-subtle)">{{ $point['label'] }}</text>
            @endif
        @endforeach
    </svg>
    <figcaption class="sr-only">
        @foreach($coordinates as $point){{ $point['label'] }}: {{ $point['value'] }} completed.@endforeach
    </figcaption>
</figure>
