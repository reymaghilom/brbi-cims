@php
    $tableKey = $table['key'];
    $columns = collect($table['columns'])->keyBy('key');
    $submittedRows = old("template_data.tables.$tableKey");
    $savedRows = collect($submittedRows ?? data_get($report?->template_data, "tables.$tableKey", []))->values();
    $categories = [
        ['value' => 'Poultry: Chicken', 'label' => 'POULTRY: CHICKEN', 'matches' => ['poultry', 'chicken']],
        ['value' => 'Pork', 'label' => 'PORK', 'matches' => ['pork']],
        ['value' => 'Beef', 'label' => 'BEEF', 'matches' => ['beef']],
        ['value' => 'Fish', 'label' => 'FISH', 'matches' => ['fish']],
        ['value' => 'Packaged/Canned Food', 'label' => 'PACKAGED/CANNED FOOD', 'matches' => ['packaged', 'canned']],
        ['value' => 'Other Products', 'label' => 'OTHER PRODUCTS', 'matches' => []],
    ];
    $usedRows = [];
    $categoryRows = $submittedRows !== null
        ? collect($categories)->map(fn (array $category, int $categoryIndex): array => (array) $savedRows->get($categoryIndex, []))
        : collect($categories)->map(function (array $category, int $categoryIndex) use ($savedRows, &$usedRows) {
        $matchedIndex = $savedRows->search(function ($row, int $rowIndex) use ($category, $categoryIndex, $usedRows): bool {
            if (in_array($rowIndex, $usedRows, true)) {
                return false;
            }
            $type = strtolower((string) data_get($row, 'product_type'));
            if ($categoryIndex === 5) {
                return filled($type);
            }

            return collect($category['matches'])->contains(fn (string $match): bool => str_contains($type, $match));
        });
        if ($matchedIndex === false) {
            return [];
        }
        $usedRows[] = $matchedIndex;

        return (array) $savedRows->get($matchedIndex);
        });
    $questionAnswers = old('template_data.questions', data_get($report?->template_data, 'questions', []));
    $inputColumns = ['main_supplier', 'location', 'contact_number', 'daily_kilos_sold', 'price_range', 'remarks'];
@endphp
<section class="business-report-section">
    <header class="business-distributor-section-title"><h2>PRODUCTS/GOODS SEEN IN INVENTORY AT STORE (plus interview with the butcher):</h2></header>
    <div class="business-report-table-wrap">
        <table class="business-report-table business-meatshop-inventory-table">
            <thead>
                <tr>
                    <th scope="col">Check all that apply:</th>
                    @foreach($inputColumns as $columnKey)<th scope="col"><span>{{ $columns[$columnKey]['label'] }}</span></th>@endforeach
                </tr>
            </thead>
            <tbody>
                @foreach($categories as $index => $category)
                    @php($row = $categoryRows[$index])
                    <tr>
                        <td class="business-meatshop-category-cell">
                            <label class="business-report-choice-option">
                                <input class="business-report-checkbox" name="template_data[tables][{{ $tableKey }}][{{ $index }}][product_type]" type="checkbox" value="{{ data_get($row, 'product_type', $category['value']) }}" @checked(filled(data_get($row, 'product_type')))>
                                <span>{{ $category['label'] }}</span>
                            </label>
                        </td>
                        @foreach($inputColumns as $columnKey)
                            <td>
                                <label class="sr-only" for="meatshop-{{ $columnKey }}-{{ $index }}">{{ $columns[$columnKey]['label'] }}</label>
                                <input id="meatshop-{{ $columnKey }}-{{ $index }}" class="ui-control" name="template_data[tables][{{ $tableKey }}][{{ $index }}][{{ $columnKey }}]" type="text" value="{{ data_get($row, $columnKey) }}">
                                <x-form.validation-message :for="'template_data.tables.'.$tableKey.'.'.$index.'.'.$columnKey" />
                            </td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</section>
<section class="business-report-section business-meatshop-observations">
    <div class="business-distributor-interview-panel">
        <h2>OBSERVATIONS DURING BUSINESS INSPECTION:</h2>
        <div class="business-distributor-question-list">
            @foreach($schema['questions'] as $index => $question)
                <div class="business-distributor-question-row">
                    <label class="ui-label" for="meatshop-question-{{ $index }}">{{ $index + 1 }}. {{ $question }}</label>
                    <input id="meatshop-question-{{ $index }}" class="ui-control" name="template_data[questions][{{ $index }}]" type="text" value="{{ data_get($questionAnswers, $index) }}">
                    <x-form.validation-message :for="'template_data.questions.'.$index" class="business-distributor-question-error" />
                </div>
            @endforeach
        </div>
    </div>
</section>
