@php
    $na = fn (mixed $value) => filled($value) ? $value : 'N/A';
    $mark ??= fn (bool $selected) => $selected ? '( ✓ )' : '(   )';
    $field = fn (string $key) => data_get($business['template_data'] ?? [], "fields.$key");
    $farms = array_pad(array_slice((array) data_get($business['template_data'] ?? [], 'tables.farms', []), 0, 5), 5, []);
@endphp
<table class="business-form-table business-profile business-section-connected{{ ($showCommonHeader ?? true) ? '' : ' business-batch-continuation-first' }}"><colgroup><col span="25" style="width:4%"></colgroup>
<tbody>
@if($showCommonHeader ?? true)
<tr><th colspan="4">CI-IN CHARGE:</th><td colspan="10">{{ $business['ci_in_charge'] }}</td><th colspan="4">BRANCH:</th><td colspan="7">{{ $business['branch'] }}</td></tr>
<tr><th colspan="4">START DATE OF CI:</th><td colspan="10">{{ $business['start_date'] }}</td><th colspan="4">{{ $business['name_label'] }}:</th><td colspan="7">{{ $business['applicant_name'] }}</td></tr>
<tr><th colspan="4">DATE SUBMITTED TO CA:</th><td colspan="10">{{ $business['submitted_date'] }}</td><th colspan="4">ACCOUNT OFFICER:</th><td colspan="7">{{ $business['account_officer'] }}</td></tr>
<tr><td colspan="14" class="business-options"><div class="business-choice-list"><span>{{ $mark($business['party_type'] === 'borrower') }} BORROWER</span><span>{{ $mark($business['party_type'] === 'co_maker') }} CO-MAKER</span></div></td><th colspan="4">AMOUNT APPLIED:</th><td colspan="7">{{ $business['amount_applied'] }}</td></tr>
@endif
<tr class="business-section-bar"><th colspan="25">{{ $business['section_title'] }}</th></tr>
<tr><th colspan="4">TOTAL PROPERTIES DECLARED:</th><td colspan="2">{{ $na($field('total_declared')) }}</td><th colspan="4">TOTAL PROPERTIES INSPECTED:</th><td colspan="2">{{ $na($field('total_inspected')) }}</td><th colspan="4">TOTAL PROP NOT INSPECTED:</th><td>{{ $na($field('total_not_inspected')) }}</td><th colspan="2">REASON NOT INSPECTED:</th><td colspan="6">{{ $na($field('reason_not_inspected')) }}</td></tr>
</tbody></table>
<p class="business-remarks" style="margin-bottom:0"><span style="display:inline">Summary of Properties Inspected:</span></p>
<table class="business-form-table business-grid-table"><colgroup><col span="25" style="width:4%"></colgroup>
<thead><tr><th colspan="5">LOCATION &amp; TOTAL HA</th><th colspan="4">LESSOR (NAME &amp; CONTACT NO. &amp; YEARS RENTING)</th><th colspan="2">W/ CONTRACT? (Y/N)</th><th colspan="3"># OF FARM BUILDINGS</th><th colspan="3"># OF HEADS PER BUILDING</th><th colspan="3">LAYER/BROILER/GROWER</th><th colspan="5">RELEVANT INFORMATION GATHERED (EX. LEASE INCOME SHARED AMONG RELATIVES?)</th></tr></thead>
<tbody>
@foreach($farms as $farm)
<tr><td colspan="5">{{ $farm['location_area'] ?? '' }}</td><td colspan="4">{{ $farm['lessor'] ?? '' }}</td><td colspan="2">{{ $farm['has_contract'] ?? '' }}</td><td colspan="3">{{ $farm['farm_buildings'] ?? '' }}</td><td colspan="3">{{ $farm['heads_per_building'] ?? '' }}</td><td colspan="3">{{ $farm['production_type'] ?? '' }}</td><td colspan="5">{{ $farm['relevant_information'] ?? '' }}</td></tr>
@endforeach
</tbody></table>
<div class="business-remarks"><span>OTHER REMARKS:</span><div class="business-remarks-box">{{ $na($business['report_remarks']) }}</div></div>
