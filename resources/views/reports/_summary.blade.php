{{-- The four KPI cards. Rendered in the page, and again on their own after a report is saved
     from the Reports workspace so the counts AUTO-UPDATE without a page reload. --}}
@foreach($kpis as $kpi)
    <a href="{{ route('reports.index', $kpi['query']) }}" class="ui-card flex items-start gap-3.5 p-4 transition hover:border-brand-primary sm:p-5" aria-label="{{ $kpi['label'] }}">
        <span class="grid size-11 shrink-0 place-items-center rounded-card {{ $kpiTones[$kpi['tone']] }}" aria-hidden="true"><x-ui.icon :name="$kpi['icon']" size="size-5" /></span>
        <div class="min-w-0">
            <p class="text-2xl font-bold tabular-nums tracking-tight text-text-main sm:text-3xl">{{ $kpi['value'] }}</p>
            <p class="truncate text-sm font-semibold text-text-main">{{ $kpi['label'] }}</p>
            <p class="mt-0.5 text-xs leading-4 text-text-subtle">{{ $kpi['hint'] }}</p>
        </div>
    </a>
@endforeach
