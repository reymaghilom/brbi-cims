@php
    use Carbon\CarbonImmutable;

    // The one definition of the four KPI cards, shared by the full page and the post-save
    // AUTO-UPDATE fragment. Every card is a normal GET link into the same filters the tabs use.
    $month = CarbonImmutable::now(config('cims.display_timezone'));
    $kpis = [
        ['label' => 'Total Reports', 'value' => $summary['total'], 'hint' => 'All report records', 'icon' => 'report', 'tone' => 'brand', 'query' => ['tab' => 'all']],
        ['label' => 'Pending', 'value' => $summary['pending'], 'hint' => 'Reports awaiting completion', 'icon' => 'clock', 'tone' => 'amber', 'query' => ['tab' => 'pending']],
        ['label' => 'Completed', 'value' => $summary['completed'], 'hint' => 'Completed reports available to view', 'icon' => 'check-circle', 'tone' => 'success', 'query' => ['tab' => 'completed']],
        ['label' => 'Completed This Month', 'value' => $summary['completed_this_month'], 'hint' => 'Reports completed this month', 'icon' => 'calendar', 'tone' => 'brand', 'query' => ['tab' => 'completed', 'from' => $month->startOfMonth()->toDateString(), 'to' => $month->endOfMonth()->toDateString()]],
    ];
    $kpiTones = [
        'brand' => 'bg-brand-soft text-brand-primary',
        'success' => 'bg-success-soft text-success',
        'amber' => 'bg-progress-soft text-progress',
    ];

    // A KPI card is a "jump to this view" shortcut, so it owns the tab AND the date range that
    // define it — Completed This Month IS its month range, and plain Completed is the same tab
    // without one. Everything else the user has applied (search, the pinned client, report type,
    // client type, the active sort) rides along untouched, exactly as the tab links carry it.
    // Previously the href was built from the card's own query alone, which silently threw those
    // filters away the moment a KPI was clicked.
    $kpiCarried = collect($filters ?? [])->except(['tab', 'from', 'to'])->filter(fn ($value) => filled($value))->all();

    // Identity of the view a card stands for: tab + date range. This is the single key both the
    // active state below and syncKpiState() in app.js compare against, so a server render and a
    // client-side tab switch can never disagree about which card is current.
    $kpiKey = fn (array $query): string => implode('|', [
        $query['tab'] ?? 'all',
        $query['from'] ?? '',
        $query['to'] ?? '',
    ]);
    $activeKpiKey = $kpiKey([
        'tab' => $filters['tab'] ?? 'all',
        'from' => $filters['from'] ?? '',
        'to' => $filters['to'] ?? '',
    ]);

    $kpis = array_map(function (array $kpi) use ($kpiCarried, $kpiKey, $activeKpiKey): array {
        $kpi['key'] = $kpiKey($kpi['query']);
        $kpi['url'] = route('reports.index', array_merge($kpiCarried, $kpi['query']));
        $kpi['active'] = $kpi['key'] === $activeKpiKey;

        return $kpi;
    }, $kpis);
@endphp

@include('reports._summary', ['kpis' => $kpis, 'kpiTones' => $kpiTones])
