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
    /**
     * Same 4-column split as the edit form's catalog (_business-other-income-source.blade.php)
     * so the printed layout matches what the CI actually saw and checked: Business is split
     * across the first 3 columns (16 / 15 / remainder), matching the reference workbook's own
     * 3-column Business layout, with Agriculture, Professional Services, Remittance, and
     * Employment stacked into the remaining columns.
     */
    $catalogColumns = [
        [['title' => 'Business:', 'choices' => array_slice($businessChoices, 0, 16)]],
        [['title' => 'Business:', 'choices' => array_slice($businessChoices, 16, 15)]],
        [
            ['title' => 'Business:', 'choices' => array_slice($businessChoices, 31)],
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
<tr><th colspan="4">{{ $business['name_label'] }}:</th><td colspan="14">{{ $na($business['applicant_name']) }}</td><th colspan="4">BRANCH:</th><td colspan="3">{{ $na($business['branch']) }}</td></tr>
<tr><th colspan="4">AMOUNT APPLIED:</th><td colspan="14">{{ $na($business['amount_applied']) }}</td><th colspan="4">ACCOUNT OFFICER:</th><td colspan="3">{{ $na($business['account_officer']) }}</td></tr>
@endif
<tr class="business-section-bar"><th colspan="25">RANK ALL INCOME SOURCES THAT CLIENT DECLARED BASED ON CONRTIBUTION (1 BEING THE HIGHEST)</th></tr>
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
