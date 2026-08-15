@php
    $incomeSourceGroups = $schema['income_source_groups'] ?? [];
    $businessChoices = $incomeSourceGroups['business'] ?? [];
    $catalogColumns = [
        [['title' => 'Business:', 'choices' => array_slice($businessChoices, 0, 16)]],
        [['title' => 'Business:', 'choices' => array_slice($businessChoices, 16, 15)]],
        [
            ['title' => 'Business:', 'choices' => array_slice($businessChoices, 31)],
            ['title' => 'Agriculture Production:', 'choices' => $incomeSourceGroups['agriculture'] ?? []],
        ],
        [
            ['title' => 'Professional Services:', 'choices' => $incomeSourceGroups['professional'] ?? []],
            ['title' => 'Remittance:', 'choices' => $incomeSourceGroups['remittance'] ?? []],
            ['title' => 'Employment (Borrower / Spouse):', 'choices' => $incomeSourceGroups['employment'] ?? []],
        ],
    ];
    $legacyKeys = ['business', 'agriculture', 'professional', 'remittance', 'employment_borrower', 'employment_spouse'];
@endphp

<section class="business-report-section business-other-income-source" data-other-income-source aria-labelledby="other-income-source-heading">
    <div class="business-report-subheading">
        <div>
            <h2 id="other-income-source-heading">RANK ALL INCOME SOURCES THAT CLIENT DECLARED BASED ON CONTRIBUTION (1 BEING THE HIGHEST)</h2>
        </div>
    </div>

    <div class="business-other-income-scroll">
        <div class="business-other-income-layout">
            <div class="business-other-income-catalog">
                @foreach($catalogColumns as $columnGroups)
                    <div class="business-other-income-column">
                        @foreach($columnGroups as $group)
                            <section class="business-other-income-group" aria-label="{{ $group['title'] }}">
                                <h3>{{ $group['title'] }}</h3>
                                @foreach($group['choices'] as $choice)
                                    @include('client-folders.income-sources._business-other-income-choice', ['choice' => $choice])
                                @endforeach
                            </section>
                        @endforeach
                    </div>
                @endforeach
            </div>

            <aside class="business-other-income-summary" aria-label="Selected income sources">
                <h3>INCOME SOURCE:</h3>
                <ol data-income-source-summary></ol>
                <p data-income-source-empty>No income source ranked.</p>
            </aside>
        </div>
    </div>

    {{-- Retain previously stored fallback keys without displaying the superseded generic controls. --}}
    @foreach($legacyKeys as $legacyKey)
        @foreach(['selected', 'rank', 'description'] as $suffix)
            <input type="hidden" name="template_data[fields][{{ $legacyKey }}_{{ $suffix }}]" value="{{ old('template_data.fields.'.$legacyKey.'_'.$suffix, data_get($report?->template_data, 'fields.'.$legacyKey.'_'.$suffix)) }}">
        @endforeach
    @endforeach
</section>
