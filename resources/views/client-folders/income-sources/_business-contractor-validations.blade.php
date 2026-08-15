@php
    $questionAnswers = old('template_data.questions', data_get($report?->template_data, 'questions', []));
@endphp
<section class="business-report-section business-contractor-validation-section">
    <div class="business-distributor-interview-panel">
        <h2>OBSERVATIONS DURING BUSINESS INSPECTION:</h2>
        <div class="business-distributor-question-list">
            @foreach(array_slice($schema['questions'], 0, 5) as $index => $question)
                <div class="business-distributor-question-row">
                    <label class="ui-label" for="contractor-question-{{ $index }}">{{ $index + 1 }}. {{ $question }}</label>
                    <input id="contractor-question-{{ $index }}" class="ui-control" name="template_data[questions][{{ $index }}]" type="text" value="{{ data_get($questionAnswers, $index) }}">
                    <x-form.validation-message :for="'template_data.questions.'.$index" class="business-distributor-question-error" />
                </div>
            @endforeach
        </div>
    </div>
</section>

@include('client-folders.income-sources._business-schema-table', ['table' => $supplierTable])

<section class="business-report-section business-contractor-validation-section">
    <div class="business-distributor-interview-panel">
        <h2>For Additional Validation:</h2>
        <div class="business-distributor-question-list">
            @foreach(array_slice($schema['questions'], 5, 4, true) as $index => $question)
                <div class="business-distributor-question-row">
                    <label class="ui-label" for="contractor-question-{{ $index }}">{{ $loop->iteration }}. {{ $question }}</label>
                    <input id="contractor-question-{{ $index }}" class="ui-control" name="template_data[questions][{{ $index }}]" type="text" value="{{ data_get($questionAnswers, $index) }}">
                    <x-form.validation-message :for="'template_data.questions.'.$index" class="business-distributor-question-error" />
                </div>
            @endforeach
        </div>
    </div>
</section>
