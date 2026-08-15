@php
    $tableKey = $table['key'];
    $columns = $table['columns'];
    $visibleColumns = $columns;
    $rows = old("template_data.tables.$tableKey", data_get($report?->template_data, "tables.$tableKey", []));
    $rows = count((array) $rows) ? $rows : array_fill(0, 5, []);
@endphp
<section class="business-report-section business-distributor-interview-products">
    <div class="business-distributor-interview-panel">
        <h2>Detailed Information Gathered from Employee/Driver/Manager Interview:</h2>
        <div class="business-distributor-question-list">
            @foreach($schema['questions'] as $index => $question)
            @php
                $answer = (string) old('template_data.questions.'.$index, data_get($report?->template_data, 'questions.'.$index));
                $paymentSelection = $index === 5
                    ? (str_starts_with(strtoupper($answer), 'TERM:') ? 'TERM' : strtoupper($answer))
                    : null;
                $paymentTerm = $paymentSelection === 'TERM' ? trim(substr($answer, strlen('TERM:'))) : '';
            @endphp
            <div class="business-distributor-question-row">
                <label class="ui-label" @if(in_array($index, [2, 4, 5], true)) id="template-data-questions-{{ $index }}-label" @else for="template-data-questions-{{ $index }}" @endif>{{ $question }}</label>
                @if($index === 2)
                    <div class="business-report-choice-group business-address-status business-distributor-question-choice" role="radiogroup" aria-labelledby="template-data-questions-{{ $index }}-label">
                        @foreach(['1X', '2X', '3X', '4X'] as $option)
                            <label class="business-report-choice-option"><input class="business-report-checkbox" name="template_data[questions][{{ $index }}]" type="radio" value="{{ $option }}" @checked(strtoupper($answer) === $option)><span>{{ $option }}</span></label>
                        @endforeach
                    </div>
                @elseif($index === 4)
                    <div class="business-report-choice-group business-address-status business-distributor-question-choice" role="radiogroup" aria-labelledby="template-data-questions-{{ $index }}-label">
                        @foreach(['BANK DEPOSIT', 'REMITTANCE AGENT', 'OFFICE'] as $option)
                            <label class="business-report-choice-option"><input class="business-report-checkbox" name="template_data[questions][{{ $index }}]" type="radio" value="{{ $option }}" @checked(strtoupper($answer) === $option)><span>{{ $option }}</span></label>
                        @endforeach
                    </div>
                @elseif($index === 5)
                    <div class="business-report-choice-group business-address-status business-distributor-question-choice" role="radiogroup" aria-labelledby="template-data-questions-{{ $index }}-label" data-distributor-payment-terms>
                        @foreach(['COD', 'COLLECT ON NEXT DELIVERY'] as $option)
                            <label class="business-report-choice-option"><input class="business-report-checkbox" name="template_data[questions][{{ $index }}]" type="radio" value="{{ $option }}" data-distributor-payment-option @checked($paymentSelection === $option)><span>{{ $option }}</span></label>
                        @endforeach
                        <div class="business-distributor-term-option"><label class="business-report-choice-option"><input class="business-report-checkbox" name="template_data[questions][{{ $index }}]" type="radio" value="TERM:{{ filled($paymentTerm) ? ' '.$paymentTerm : '' }}" data-distributor-payment-option="term" @checked($paymentSelection === 'TERM')><span>TERM:</span></label><input class="ui-control business-distributor-term-input" type="text" value="{{ $paymentTerm }}" aria-label="Payment term" data-distributor-payment-term-input @disabled($paymentSelection !== 'TERM')></div>
                    </div>
                @else
                    <input id="template-data-questions-{{ $index }}" class="ui-control" name="template_data[questions][{{ $index }}]" type="text" value="{{ $answer }}">
                @endif
                <x-form.validation-message :for="'template_data.questions.'.$index" class="business-distributor-question-error" />
            </div>
            @endforeach
        </div>
    </div>
    <div class="business-distributor-products-panel" data-repeater="template-{{ $tableKey }}">
        <header class="business-distributor-products-action"><button type="button" class="business-add-entry" data-repeater-add>+ Add Row</button></header>
        <div class="business-report-table-wrap">
            <table class="business-report-table business-distributor-products-table">
                <thead><tr>@foreach($columns as $column)<th scope="col"><span>{{ $column['label'] }}</span>@if(filled($column['guide'] ?? null))<small class="business-report-column-guide">{{ $column['guide'] }}</small>@endif</th>@endforeach<th scope="col" class="business-report-action-heading">Action</th></tr></thead>
                <tbody data-repeater-rows>@foreach($rows as $index => $row)@include('client-folders.income-sources._business-schema-row')@endforeach</tbody>
            </table>
        </div>
        <template data-repeater-template>@include('client-folders.income-sources._business-schema-row', ['row' => [], 'index' => '__INDEX__'])</template>
        <x-form.validation-message :for="'template_data.tables.'.$tableKey" />
    </div>
</section>
