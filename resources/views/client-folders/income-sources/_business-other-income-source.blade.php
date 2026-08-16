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
    $oldSelectedSources = old('template_data.fields.income_sources');
    $selectedSources = is_array($oldSelectedSources) ? $oldSelectedSources : (array) data_get($report?->template_data, 'fields.income_sources', []);
    if ($oldSelectedSources === null && $selectedSources === []) {
        foreach (array_merge($businessChoices, $incomeSourceGroups['agriculture'] ?? [], $incomeSourceGroups['professional'] ?? [], $incomeSourceGroups['remittance'] ?? [], $incomeSourceGroups['employment'] ?? []) as $savedChoice) {
            if (filled(data_get($report?->template_data, 'fields.'.$savedChoice['key'].'_rank')) && (string) data_get($report?->template_data, 'fields.'.$savedChoice['key'].'_rank') !== '0') {
                $selectedSources[] = $savedChoice['key'];
            }
        }
    }
@endphp

<section class="business-report-section business-other-income-source" data-other-income-source aria-labelledby="other-income-source-heading">
    <div class="business-report-subheading">
        <div>
            <h2 id="other-income-source-heading">SELECT ALL APPLICABLE INCOME SOURCES</h2>
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
                                    @include('client-folders.income-sources._business-other-income-choice', ['choice' => $choice, 'selectedSources' => $selectedSources])
                                @endforeach
                            </section>
                        @endforeach
                    </div>
                @endforeach
            </div>
            <x-form.validation-message for="template_data.fields.income_sources" class="business-section-error" />

        </div>
    </div>

    {{-- Retain previously stored fallback keys without displaying the superseded generic controls. --}}
    @foreach($legacyKeys as $legacyKey)
        @foreach(['selected', 'rank', 'description'] as $suffix)
            <input type="hidden" name="template_data[fields][{{ $legacyKey }}_{{ $suffix }}]" value="{{ old('template_data.fields.'.$legacyKey.'_'.$suffix, data_get($report?->template_data, 'fields.'.$legacyKey.'_'.$suffix)) }}">
        @endforeach
    @endforeach
</section>
