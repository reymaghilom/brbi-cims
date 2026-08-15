@php
    $questionAnswers = old('template_data.questions', data_get($report?->template_data, 'questions', []));
    $requiredQuestions = array_map('intval', (array) ($schema['required_questions'] ?? []));
@endphp

<section class="business-report-section business-remittance-questions" aria-label="Remittance validation questions">
    <div class="business-remittance-question-list">
        @foreach($schema['questions'] as $index => $question)
            @php
                $questionName = 'template_data.questions.'.$index;
                $isRequired = in_array($index, $requiredQuestions, true);
                $hasError = $errors->has($questionName);
            @endphp
            <div class="business-remittance-question-row">
                <label class="ui-label" for="remittance-question-{{ $index }}">{{ $index + 1 }}. {{ $question }} @if($isRequired)<span class="text-danger" aria-hidden="true">*</span><span class="sr-only">required</span>@endif</label>
                <input id="remittance-question-{{ $index }}" class="ui-control" name="template_data[questions][{{ $index }}]" type="text" value="{{ data_get($questionAnswers, $index) }}" @required($isRequired) @if($hasError) aria-invalid="true" aria-describedby="{{ str($questionName)->replace('.', '-') }}-error" @endif>
                <x-form.validation-message :for="$questionName" class="business-remittance-question-error" />
            </div>
        @endforeach
    </div>
</section>
