@php
    $questionAnswers = old('template_data.questions', data_get($report?->template_data, 'questions', []));
@endphp

<section class="business-report-section business-restaurant-observations">
    <div class="business-distributor-interview-panel">
        <h2>OBSERVATIONS DURING BUSINESS INSPECTION:</h2>
        <div class="business-distributor-question-list">
            @foreach($schema['questions'] as $index => $question)
                <div class="business-distributor-question-row">
                    <label class="ui-label" for="restaurant-question-{{ $index }}">{{ $index + 1 }}. {{ $question }}</label>
                    <input id="restaurant-question-{{ $index }}" class="ui-control" name="template_data[questions][{{ $index }}]" type="text" value="{{ data_get($questionAnswers, $index) }}">
                    <x-form.validation-message :for="'template_data.questions.'.$index" class="business-distributor-question-error" />
                </div>
            @endforeach
        </div>
    </div>
</section>
