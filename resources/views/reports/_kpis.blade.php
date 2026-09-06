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
@endphp

@include('reports._summary', ['kpis' => $kpis, 'kpiTones' => $kpiTones])
