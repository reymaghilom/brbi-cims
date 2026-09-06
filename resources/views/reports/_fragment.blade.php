{{-- The AUTO-UPDATE payload for the results region: always the listing, plus the freshly
     counted KPI cards when the caller asked for them (only a save does — sorting and pagination
     cannot change counts that are deliberately unfiltered). --}}
@include('reports._listing', ['items' => $items, 'filters' => $filters, 'sort' => $sort, 'direction' => $direction])
@isset($summary)
    <template data-reports-summary-html>@include('reports._kpis', ['summary' => $summary])</template>
@endisset
