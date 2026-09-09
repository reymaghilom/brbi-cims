@php
    $incomeSourceGroups = $schema['income_source_groups'] ?? [];
    // The catalog is deliberately the complete default list: a category may legitimately have BOTH
    // its own dedicated Business Template and a checkbox here, so nothing is filtered out on the
    // grounds that a template exists for it. The 16/15/rest split matches the official report
    // output's own three Business columns (_business-report-other-income-source.blade.php).
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
    /**
     * CI-created options, offered alongside the defaults and never in place of them. Active ones
     * are selectable as usual; a category this exact report already selected is still listed even
     * once it has been removed from future selection, so an unrelated save can never silently drop
     * it from the stored combination. Its identity is the row id, so a rename is invisible here.
     */
    $savedKeys = (array) data_get($report?->template_data, 'fields.income_sources', []);
    $customChoices = \App\Models\CustomBusinessCategory::catalogChoices();
    $activeCustomKeys = array_column($customChoices, 'key');
    foreach (\App\Models\CustomBusinessCategory::labelsForKeys($savedKeys) as $retiredKey => $retiredLabel) {
        if (! in_array($retiredKey, $activeCustomKeys, true)) {
            $customChoices[] = ['key' => $retiredKey, 'label' => $retiredLabel, 'retired' => true];
        }
    }
    $canManageCustomBusinesses = auth()->user()?->can('update', $clientFolder) ?? false;

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

{{-- The visible "SELECT ALL APPLICABLE INCOME SOURCES" heading was removed; the section keeps its
     name for assistive technology through aria-label rather than a now-absent heading element. --}}
<section class="business-report-section business-other-income-source" data-other-income-source aria-label="Other Business / Source of Income">
    <div class="business-other-income-scroll">
        <div class="business-other-income-layout">
            <div class="business-other-income-catalog">
                @foreach($catalogColumns as $columnIndex => $columnGroups)
                    <div class="business-other-income-column">
                        @foreach($columnGroups as $groupIndex => $group)
                            <section class="business-other-income-group" aria-label="{{ $group['title'] }}">
                                <h3>{{ $group['title'] }}</h3>
                                @foreach($group['choices'] as $choice)
                                    @include('client-folders.income-sources._business-other-income-choice', ['choice' => $choice, 'selectedSources' => $selectedSources])
                                @endforeach
                                @if($columnIndex === 0 && $groupIndex === 0)
                                    {{-- Custom rows share the first Business group and follow its final
                                         default (STL/Lotto Outlet). JavaScript appends to this same list,
                                         so newly created rows stay here without a page reload. --}}
                                    <div data-custom-business-list>
                                        @foreach($customChoices as $choice)
                                            @include('client-folders.income-sources._business-other-income-choice', ['choice' => $choice, 'selectedSources' => $selectedSources, 'canManageCustomBusinesses' => $canManageCustomBusinesses])
                                        @endforeach
                                    </div>
                                    @if($canManageCustomBusinesses)
                                        <button type="button" class="business-other-income-add"
                                                data-modal-open="custom-business-add-dialog" data-custom-business-add>
                                            <x-ui.icon name="plus" size="" />Add Business
                                        </button>
                                    @endif
                                @endif
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

    @if($canManageCustomBusinesses)
        {{-- These dialogs deliberately contain no <form> of their own: the whole catalog sits inside
             the Business Report form, and nesting a form inside it is invalid HTML. They post through
             fetch instead (see app.js), which is also what keeps the CI's unsaved checkbox state —
             adding a business never navigates away from a half-encoded report. --}}
        <div data-custom-business-manager data-custom-business-base-url="{{ route('client-folders.custom-business-categories.store', $clientFolder) }}" hidden></div>

        {{-- The one definition of a custom row's markup. A row added through
             "Add Business" is cloned from this instead of being rebuilt in JS,
             so a newly inserted row is structurally identical to a
             server-rendered one — same classes, same icons, same height — and
             there is no second visual structure to drift out of sync. --}}
        <template data-custom-business-row-template>
            @include('client-folders.income-sources._business-other-income-choice', [
                'choice' => ['key' => '__OPTION_KEY__', 'label' => '__LABEL__', 'custom_id' => '__CUSTOM_ID__'],
                'selectedSources' => [],
                'canManageCustomBusinesses' => true,
            ])
        </template>

        <x-ui.modal id="custom-business-add-dialog" title="Add Custom Business" description="Adds a new checkbox to the business options." size="max-w-md" class="custom-business-dialog">
            <div class="custom-business-dialog-field">
                <label class="ui-label" for="custom-business-add-name">Business Name</label>
                <input id="custom-business-add-name" type="text" class="ui-control" maxlength="120" autocomplete="off" data-custom-business-add-name>
                <p class="text-sm font-semibold text-danger" role="alert" data-custom-business-add-error hidden></p>
            </div>
            <x-slot:footer>
                <button type="button" class="ui-button-secondary" data-modal-close><x-ui.icon name="close" size="size-4" /> Cancel</button>
                <button type="button" class="ui-button-primary" data-custom-business-add-submit><x-ui.icon name="plus" size="size-4" /> Add Business</button>
            </x-slot:footer>
        </x-ui.modal>

        <x-ui.modal id="custom-business-edit-dialog" title="Edit Custom Business" description="Renames this business option everywhere it is offered." size="max-w-md" class="custom-business-dialog">
            <div class="custom-business-dialog-field">
                <label class="ui-label" for="custom-business-edit-name">Business Name</label>
                <input id="custom-business-edit-name" type="text" class="ui-control" maxlength="120" autocomplete="off" data-custom-business-edit-name>
                <p class="text-sm font-semibold text-danger" role="alert" data-custom-business-edit-error hidden></p>
            </div>
            <x-slot:footer>
                <button type="button" class="ui-button-secondary" data-modal-close><x-ui.icon name="close" size="size-4" /> Cancel</button>
                <button type="button" class="ui-button-primary" data-custom-business-edit-submit><x-ui.icon name="check" size="size-4" /> Save Changes</button>
            </x-slot:footer>
        </x-ui.modal>

        <x-ui.modal id="custom-business-remove-dialog" title="Remove Custom Business?">
            <p class="text-sm text-text-muted" data-custom-business-remove-message></p>
            <p class="mt-2 text-xs leading-5 text-text-muted">A business already used by a saved Business Report is removed from future selection only — every existing report keeps it.</p>
            <x-slot:footer>
                <button type="button" class="ui-button-secondary" data-modal-close><x-ui.icon name="close" size="size-4" /> Cancel</button>
                <button type="button" class="ui-button-danger" data-custom-business-remove-submit><x-ui.icon name="trash" size="size-4" /> Remove</button>
            </x-slot:footer>
        </x-ui.modal>
    @endif
</section>
