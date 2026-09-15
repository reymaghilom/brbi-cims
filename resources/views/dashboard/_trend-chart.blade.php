@php
    /**
     * CI Completion Trend - one range. A plain HTML/CSS bar chart (this project ships no chart
     * library): completed investigations are discrete counts, so bars read better than a line, and
     * real HTML text keeps axis labels and tooltips at a readable size on every screen - an SVG
     * viewBox shrank its text to a few pixels on phones.
     *
     * Everything drawn comes from DashboardData::trend(): `bars` are grouped from the same daily
     * counts as `points`, so the summary total always equals the sum of the visible bars.
     */
    $bars = collect($trend['bars']);
    $total = (int) $trend['total'];
    $barMax = (int) $bars->max('value');
    $count = max(1, $bars->count());

    // Whole-number axis that adapts to the data: at most 5 steps of 1, 2, 5, 10, 20, 50, ...
    $step = 1;
    if ($barMax > 5) {
        $magnitude = 10 ** (int) floor(log10($barMax / 5));
        $step = collect([1, 2, 5, 10])->map(fn (int $nice): int => $nice * $magnitude)->first(fn (int $candidate): bool => $candidate * 5 >= $barMax);
    }
    $axisMax = max($step, (int) ceil(max(1, $barMax) / $step) * $step);
    $ticks = collect(range($axisMax, 0, -$step))->values();

    $plural = fn (int $value): string => $value.' completed '.Str::plural('investigation', $value);
    // Twelve month labels do not fit a phone: every other one is kept there (the space stays reserved).
    $thinOnPhone = $count > 7;
@endphp

<div class="mt-4" data-trend-summary>
    <p class="flex flex-wrap items-baseline gap-x-2 gap-y-0.5">
        <span class="text-2xl font-bold leading-none tabular-nums text-text-main" data-trend-total>{{ $total }}</span>
        <span class="text-sm font-semibold text-text-main">Completed this period</span>
        <span class="text-xs text-text-muted">· {{ $trend['period_label'] }}</span>
    </p>
</div>

@if($total === 0)
    <div class="mt-3 flex min-h-52 flex-col items-center justify-center rounded-control border border-dashed border-ui-border bg-surface-muted/50 px-4 py-8 text-center sm:min-h-60" data-trend-empty>
        <span class="grid size-10 place-items-center rounded-full bg-surface text-text-subtle" aria-hidden="true"><x-ui.icon name="chart" size="size-5" /></span>
        <p class="mt-3 text-sm font-semibold text-text-main">No completed investigations in this period.</p>
        <p class="mt-1 max-w-xs text-sm text-text-muted">Completed investigations will appear here once available.</p>
    </div>
@else
    <figure class="mt-3" data-trend-chart>
        <div class="flex gap-2">
            {{-- Y axis: whole-number ticks only. --}}
            <div class="relative h-48 w-7 shrink-0 sm:h-56" aria-hidden="true">
                @foreach($ticks as $tick)
                    <span class="absolute right-0 translate-y-1/2 text-xs tabular-nums leading-none text-text-subtle" style="bottom: {{ round($tick / $axisMax * 100, 4) }}%">{{ $tick }}</span>
                @endforeach
            </div>

            <div class="min-w-0 flex-1">
                <div class="relative h-48 sm:h-56">
                    {{-- Recessive gridlines at the same ticks. --}}
                    @foreach($ticks as $tick)
                        <span @class(['absolute inset-x-0 border-t', 'border-ui-border-strong' => $tick === 0, 'border-ui-border/70' => $tick !== 0]) style="bottom: {{ round($tick / $axisMax * 100, 4) }}%" aria-hidden="true"></span>
                    @endforeach

                    <ol class="relative flex h-full items-stretch gap-1 sm:gap-2" aria-label="Completed investigations by period">
                        @foreach($bars as $index => $bar)
                            @php
                                $height = $bar['value'] > 0 ? max(1.5, round($bar['value'] / $axisMax * 100, 4)) : 0;
                                $tooltipAlign = match (true) {
                                    $index < $count / 3 => 'left-0',
                                    $index >= $count * 2 / 3 => 'right-0',
                                    default => 'left-1/2 -translate-x-1/2',
                                };
                            @endphp
                            <li class="group relative flex min-w-0 flex-1 items-end justify-center rounded-t-[4px] outline-none focus-visible:ring-2 focus-visible:ring-brand-primary/40" tabindex="0" data-trend-bar data-value="{{ $bar['value'] }}">
                                <span class="absolute inset-0 rounded-t-[4px] bg-brand-soft/0 transition group-hover:bg-brand-soft/60 group-focus-visible:bg-brand-soft/60" aria-hidden="true"></span>
                                <span class="relative block w-full max-w-10 rounded-t-[4px] bg-brand-primary transition group-hover:bg-brand-primary/85" style="height: {{ $height }}%; --dashboard-entry-delay: {{ $index * 60 }}ms" data-trend-bar-fill aria-hidden="true"></span>
                                <span class="sr-only">{{ $bar['tooltip'] }}: {{ $plural((int) $bar['value']) }}</span>
                                {{-- Tooltip: shown on hover and keyboard focus; edge bars anchor inward so it stays inside a phone viewport. --}}
                                <span role="tooltip" class="pointer-events-none absolute bottom-full z-10 mb-2 hidden w-max max-w-[10.5rem] rounded-control border border-ui-border bg-surface px-2.5 py-1.5 text-left shadow-float group-hover:block group-focus-visible:block {{ $tooltipAlign }}" data-trend-tooltip aria-hidden="true">
                                    <span class="block text-xs font-semibold text-text-main">{{ $bar['tooltip'] }}</span>
                                    <span class="block text-xs text-text-muted">{{ $plural((int) $bar['value']) }}</span>
                                </span>
                            </li>
                        @endforeach
                    </ol>
                </div>

                {{-- X axis: full labels from sm up; short labels (and every other month) on phones. --}}
                <div class="mt-2 flex gap-1 sm:gap-2" aria-hidden="true">
                    @foreach($bars as $index => $bar)
                        <span @class(['min-w-0 flex-1 text-center text-xs leading-tight text-text-subtle', 'max-sm:invisible' => $thinOnPhone && $index % 2 === 1])>
                            <span class="sm:hidden">{{ $bar['short'] }}</span><span class="hidden sm:inline">{{ $bar['label'] }}</span>
                        </span>
                    @endforeach
                </div>
            </div>
        </div>
        <figcaption class="sr-only">{{ $total }} completed {{ Str::plural('investigation', $total) }} in the {{ Str::lower($trend['period_label']) }}.</figcaption>
    </figure>
@endif
