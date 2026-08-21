@php
    $tableKey = $table['key'];
    $columns = $table['columns'];
    $rows = old("template_data.tables.$tableKey", data_get($report?->template_data, "tables.$tableKey", []));
    $rows = count((array) $rows) ? $rows : array_fill(0, max(1, (int) ($table['default_rows'] ?? 1)), []);
    // Pharmacy / Drugstore, General Merchandise / Hardware / Auto or Motor Parts, and Buy &
    // Sell Store: Dry Goods: Top Sellable Products always shows at least 3 rows, padding a
    // partially-saved table (1 or 2 saved rows) up to 3 — not just the "0 saved" case above.
    if (in_array($template->template_type, ['pharmacy_drugstore', 'general_merchandise_hardware_parts', 'buy_sell_dry_goods'], true) && count((array) $rows) < 3) {
        $rows = array_merge(array_values((array) $rows), array_fill(0, 3 - count((array) $rows), []));
    }
    $questionAnswers = old('template_data.questions', data_get($report?->template_data, 'questions', []));
    $questionOrder = $template->template_type === 'buy_sell_dry_goods'
        ? [0, 1, 2, 4, 5, 6, 7, 3]
        : array_keys($schema['questions']);
    if ($template->template_type === 'general_merchandise_hardware_parts'
        && !session()->hasOldInput('template_data.questions')
        && count((array) $questionAnswers) === 7) {
        $legacyAnswers = array_values((array) $questionAnswers);
        $questionAnswers = [$legacyAnswers[0] ?? null, $legacyAnswers[1] ?? null, $legacyAnswers[2] ?? null, $legacyAnswers[4] ?? null, $legacyAnswers[5] ?? null, $legacyAnswers[6] ?? null, null, $legacyAnswers[3] ?? null];
    }
@endphp
<section class="business-report-section business-pharmacy-products-observations">
    <div class="business-pharmacy-products-panel" data-repeater="template-{{ $tableKey }}">
        <header class="business-distributor-products-action"><button type="button" class="business-add-entry" data-repeater-add>+ Add Row</button></header>
        <div class="business-report-table-wrap">
            <table class="business-report-table business-pharmacy-products-table">
                <thead><tr>@foreach($columns as $column)<th scope="col"><span>{{ $column['label'] }}</span>@if(filled($column['guide'] ?? null))<small class="business-report-column-guide">{{ $column['guide'] }}</small>@endif</th>@endforeach<th scope="col" class="business-report-action-heading">Action</th></tr></thead>
                <tbody data-repeater-rows>@foreach($rows as $index => $row)@include('client-folders.income-sources._business-schema-row')@endforeach</tbody>
            </table>
        </div>
        <template data-repeater-template>@include('client-folders.income-sources._business-schema-row', ['row' => [], 'index' => '__INDEX__'])</template>
        <x-form.validation-message :for="'template_data.tables.'.$tableKey" />
    </div>
    <div class="business-distributor-interview-panel business-pharmacy-observations-panel">
        <h2>OBSERVATIONS DURING BUSINESS INSPECTION:</h2>
        <div class="business-distributor-question-list">
            @foreach($questionOrder as $displayIndex => $index)
                @php($question = $schema['questions'][$index])
                <div class="business-distributor-question-row">
                    <label class="ui-label" for="pharmacy-template-data-question-{{ $index }}">{{ $displayIndex + 1 }}. {{ $question }}</label>
                    <input id="pharmacy-template-data-question-{{ $index }}" class="ui-control" name="template_data[questions][{{ $index }}]" type="text" value="{{ data_get($questionAnswers, $index) }}">
                    <x-form.validation-message :for="'template_data.questions.'.$index" class="business-distributor-question-error" />
                </div>
            @endforeach
        </div>
    </div>
</section>
