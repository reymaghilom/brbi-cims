{{-- The four KPI cards. Rendered in the page, and again on their own after a report is saved
     from the Reports workspace so the counts AUTO-UPDATE without a page reload.

     Each card is a real link to the view it counts, wearing the shared .ui-kpi-card treatment:
     pointer, a soft hairline border that strengthens on hover, a 1px motion-safe lift, a 150ms
     transition, and an active state keyed on aria-current that outranks hover. An earlier
     `hover:border-brand-primary` here was a no-op, because .ui-card carries no border-width of its
     own; the hairline now lives in .ui-kpi-card, which does.

     data-reports-kpi / data-reports-kpi-key are what app.js uses to route a card click through the
     very same async tab path the tab strip uses, and to keep the current card marked when the tab
     strip is what changed. The key is tab|from|to — see reports/_kpis.blade.php. --}}
@foreach($kpis as $kpi)
    <a href="{{ $kpi['url'] }}"
       class="ui-card ui-kpi-card flex items-start gap-3.5 p-4 sm:p-5"
       data-reports-kpi="{{ $kpi['query']['tab'] ?? 'all' }}"
       data-reports-kpi-key="{{ $kpi['key'] }}"
       @if($kpi['active']) aria-current="page" @endif
       aria-label="{{ $kpi['label'] }}: {{ $kpi['value'] }}{{ $kpi['active'] ? ', currently showing' : ', show these reports' }}">
        <span class="grid size-11 shrink-0 place-items-center rounded-card {{ $kpiTones[$kpi['tone']] }}" aria-hidden="true"><x-ui.icon :name="$kpi['icon']" size="size-5" /></span>
        <div class="min-w-0">
            <p class="text-2xl font-bold tabular-nums tracking-tight text-text-main sm:text-3xl">{{ $kpi['value'] }}</p>
            <p class="truncate text-sm font-semibold text-text-main">{{ $kpi['label'] }}</p>
            <p class="mt-0.5 text-xs leading-4 text-text-subtle">{{ $kpi['hint'] }}</p>
        </div>
    </a>
@endforeach
