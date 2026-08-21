@php
    $productFields = [
        ['name' => 'product_name', 'label' => 'Top Sellable Products'],
        ['name' => 'selling_price', 'label' => 'Selling Price per Item', 'type' => 'number', 'step' => '0.01'],
    ];
    $productRows = old('products');
    if ($productRows === null) {
        $productRows = ($report?->products ?? collect())->map(fn ($record) => [
            'id' => $record->id,
            'product_name' => $record->product_name,
            'selling_price' => $record->selling_price,
        ])->all();
    }
    $productRows = count($productRows) ? array_values($productRows) : array_fill(0, 3, []);
    // A table with 1 or 2 saved rows must still show 3 rows total (the same minimum as a
    // brand-new report) — the line above only fills blanks when nothing was saved at all.
    if (count($productRows) < 3) {
        $productRows = array_merge($productRows, array_fill(0, 3 - count($productRows), []));
    }
    $retailQuestions = [
        ['code' => 'competitors', 'label' => 'Who are the competitors near the area?'],
        ['code' => 'location', 'label' => 'Does the client have a good location? (Residential / commercial market)'],
        ['code' => 'customers', 'label' => 'During the visit, were there a lot of customers visiting or buying? Indicate day and time.'],
        ['code' => 'stocked_products', 'label' => 'What are the most stocked products seen in the store or bodega?'],
        ['code' => 'pos_machine', 'label' => 'Do they have a Point of Sale machine or cash register?'],
        ['code' => 'refrigerated_goods', 'label' => 'Do they have refrigerated goods? How many refrigerators?'],
        ['code' => 'declared_bank', 'label' => 'Which declared bank shows the business income? Indicate bank and branch, if any.'],
    ];
    $oldObservationRows = old('observations');
    $savedObservationRows = ($report?->observations ?? collect())->values();
@endphp
<section class="business-report-section business-pharmacy-products-observations">
    <div class="business-pharmacy-products-panel" data-repeater="products">
        <header class="business-distributor-products-action"><button type="button" class="business-add-entry" data-repeater-add>+ Add Row</button></header>
        <div class="business-report-table-wrap">
            <table class="business-report-table business-pharmacy-products-table">
                <thead><tr>@foreach($productFields as $field)<th scope="col"><span>{{ $field['label'] }}</span></th>@endforeach<th scope="col" class="business-report-action-heading">Action</th></tr></thead>
                <tbody data-repeater-rows>
                    @foreach($productRows as $index => $row)
                        @include('client-folders.income-sources._business-repeater-row', ['section' => 'products', 'fields' => $productFields, 'template' => false])
                    @endforeach
                </tbody>
            </table>
        </div>
        <template data-repeater-template>@include('client-folders.income-sources._business-repeater-row', ['section' => 'products', 'fields' => $productFields, 'row' => [], 'index' => '__INDEX__', 'template' => true])</template>
        @error('products')<p class="ui-error business-section-error" role="alert">{{ $message }}</p>@enderror
    </div>
    <div class="business-distributor-interview-panel business-pharmacy-observations-panel">
        <h2>OBSERVATIONS DURING BUSINESS INSPECTION:</h2>
        <div class="business-distributor-question-list">
            @foreach($retailQuestions as $index => $question)
                @php
                    $savedRow = $savedObservationRows->get($index);
                    $row = $oldObservationRows[$index] ?? [
                        'id' => $savedRow?->id,
                        'answer' => $savedRow?->answer,
                        'remarks' => $savedRow?->remarks,
                    ];
                @endphp
                <div class="business-distributor-question-row">
                    <label class="ui-label" for="retail-observation-{{ $index }}">{{ $index + 1 }}. {{ $question['label'] }}</label>
                    <div class="business-retail-observation-control">
                        <input type="hidden" name="observations[{{ $index }}][id]" value="{{ data_get($row, 'id') }}">
                        <input type="hidden" name="observations[{{ $index }}][_delete]" value="0">
                        <input type="hidden" name="observations[{{ $index }}][observation_code]" value="{{ $question['code'] }}">
                        <input type="hidden" name="observations[{{ $index }}][question_snapshot]" value="{{ $question['label'] }}">
                        <input id="retail-observation-{{ $index }}" class="ui-control" name="observations[{{ $index }}][answer]" type="text" value="{{ data_get($row, 'answer') }}">
                        <input type="hidden" name="observations[{{ $index }}][remarks]" value="{{ data_get($row, 'remarks') }}">
                        <x-form.validation-message :for="'observations.'.$index.'.question_snapshot'" />
                    </div>
                </div>
            @endforeach
        </div>
    </div>
</section>
