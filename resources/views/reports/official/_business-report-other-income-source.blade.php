@php
    $na = fn (mixed $value) => filled($value) ? $value : 'N/A';
    // This template's income-source checklist uses a clean square checkbox (☑/☐) instead of
    // the parenthesis-style mark used elsewhere — shadows the $mark passed in from
    // business.blade.php rather than the shared closure itself, since that shared mark is also
    // used by every other Business Template's BORROWER/CO-MAKER and ownership-type rows.
    $mark = fn (bool $selected) => $selected ? '☑' : '☐';
    $data = (array) ($business['template_data'] ?? []);
    $selected = (array) data_get($data, 'fields.income_sources', []);
    $groups = (array) ($business['schema']['income_source_groups'] ?? []);
    $businessChoices = $groups['business'] ?? [];
    $customBusinessChoices = array_values(array_filter(
        $businessChoices,
        fn (array $choice) => \App\Models\CustomBusinessCategory::isCustomKey($choice['key'] ?? null),
    ));
    $defaultBusinessChoices = array_values(array_filter(
        $businessChoices,
        fn (array $choice) => ! \App\Models\CustomBusinessCategory::isCustomKey($choice['key'] ?? null),
    ));
    /**
     * Same 4-column split as the edit form's catalog (_business-other-income-source.blade.php)
     * so the printed layout matches what the CI actually saw and checked. Custom businesses
     * continue the first Business column directly after STL/Lotto Outlet, while the default
     * catalog keeps its original 16 / 15 / remainder split across the first 3 columns.
     */
    $catalogColumns = [
        [['title' => 'Business:', 'choices' => array_merge(array_slice($defaultBusinessChoices, 0, 16), $customBusinessChoices)]],
        [['title' => 'Business:', 'choices' => array_slice($defaultBusinessChoices, 16, 15)]],
        [
            ['title' => 'Business:', 'choices' => array_slice($defaultBusinessChoices, 31)],
            ['title' => 'Agriculture Production:', 'choices' => $groups['agriculture'] ?? []],
        ],
        [
            ['title' => 'Professional Services:', 'choices' => $groups['professional'] ?? []],
            ['title' => 'Remittance:', 'choices' => $groups['remittance'] ?? []],
            ['title' => 'Employment (Borrower / Spouse):', 'choices' => $groups['employment'] ?? []],
        ],
    ];
@endphp
<table class="business-form-table business-profile business-section-connected{{ ($showCommonHeader ?? true) ? '' : ' business-batch-continuation-first' }}"><colgroup><col span="25" style="width:4%"></colgroup>
<tbody>
@if($showCommonHeader ?? true)
{{-- This report intentionally follows the original worksheet's compact two-row client block.
     The applicant value remains the active person resolved by the shared report builder. --}}
<tr><th colspan="4">NAME OF APPLICANT:</th><td colspan="10">{{ $na($business['applicant_name']) }}</td><th colspan="4">BRANCH:</th><td colspan="7">{{ $na($business['branch']) }}</td></tr>
<tr><th colspan="4">AMOUNT APPLIED:</th><td colspan="10">{{ $na($business['amount_applied']) }}</td><th colspan="4">ACCOUNT OFFICER:</th><td colspan="7">{{ $na($business['account_officer']) }}</td></tr>
@endif
{{-- The section bar now carries the template's own name from the shared data (the same key every
     other template's bar uses), replacing the old ranking instruction outright. --}}
<tr class="business-section-bar"><th colspan="25">{{ $business['section_title'] }}</th></tr>
</tbody></table>
<table class="business-form-table business-grid-table business-other-income-grid"><colgroup><col span="25" style="width:4%"></colgroup>
<tbody>
<tr>
@foreach($catalogColumns as $index => $columnGroups)
<td colspan="{{ $index === 0 ? 7 : 6 }}">
@foreach($columnGroups as $group)
<p class="business-other-income-group-title">{{ $group['title'] }}</p>
@foreach($group['choices'] as $choice)
<span class="business-other-income-item"><span class="business-other-income-check">{{ $mark(in_array($choice['key'], $selected, true)) }}</span> {{ $choice['label'] }}</span>
@endforeach
@endforeach
</td>
@endforeach
</tr>
</tbody></table>
<div class="business-remarks"><span>OTHER REMARKS:</span><div class="business-remarks-box">{{ $na($business['report_remarks']) }}</div></div>
