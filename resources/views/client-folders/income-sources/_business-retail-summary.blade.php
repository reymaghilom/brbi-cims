@php
    $declared = (int) ($report?->branches_declared ?? 0);
    $inspected = (int) ($report?->branches_inspected ?? 0);
    $notInspected = max(0, $declared - $inspected);
    $reasonNotInspected = $report?->branches
        ?->where('is_inspected', false)
        ->pluck('reason_not_inspected')
        ->filter()
        ->implode('; ');
@endphp
<section class="business-report-section" aria-label="Branch inspection totals">
    <div class="business-property-summary">
        <label><span class="ui-label">TOTAL BRANCHES DECLARED:</span><input class="ui-control" type="text" value="{{ $declared ?: '' }}" readonly aria-readonly="true"></label>
        <label><span class="ui-label">TOTAL BRANCHES INSPECTED:</span><input class="ui-control" type="text" value="{{ $inspected ?: '' }}" readonly aria-readonly="true"></label>
        <label><span class="ui-label"># BRANCHES NOT INSPECTED:</span><input class="ui-control" type="text" value="{{ $notInspected ?: '' }}" readonly aria-readonly="true"></label>
        <label class="business-property-summary-reason"><span class="ui-label">REASON NOT INSPECTED:</span><input class="ui-control" type="text" value="{{ $reasonNotInspected }}" readonly aria-readonly="true"></label>
    </div>
</section>
