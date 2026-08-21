@php
    $na = fn (mixed $value) => filled($value) ? $value : 'N/A';
    $mark ??= fn (bool $selected) => $selected ? '( ✓ )' : '(   )';
    $ownership = (string) ($business['ownership_type'] ?? '');
    $field = fn (string $key) => data_get($business['template_data'] ?? [], "fields.$key");
    $units = array_pad(array_slice((array) data_get($business['template_data'] ?? [], 'tables.units', []), 0, 4), 4, []);
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
<tr><th colspan="4">BUSINESS NAME:</th><td colspan="14">{{ $na($business['business_name']) }}</td><th colspan="4">YEARS OPERATING:</th><td colspan="3" class="business-align-left">{{ $na($business['year_established']) }}</td></tr>
<tr><th colspan="4">MAIN BUSINESS ADDRESS:</th><td colspan="14">{{ $na($business['main_business_address']) }}</td><th colspan="4">LENGTH OF STAY:</th><td colspan="3" class="business-align-left">{{ $na($business['length_of_stay_months']) }}</td></tr>
<tr><td colspan="12" class="business-options"><div class="business-choice-list"><span>{{ $mark(strcasecmp($ownership, 'Residence Only') === 0) }} RESIDENCE ONLY</span><span>{{ $mark(strcasecmp($ownership, 'Owned') === 0) }} OWNED</span><span>{{ $mark(strcasecmp($ownership, 'Mortgaged') === 0) }} MORTGAGED FROM/</span><span>{{ $mark(strcasecmp($ownership, 'Rented') === 0) }} RENTED FROM:</span></div></td><td colspan="6">{{ $na($business['rented_from']) }}</td><th colspan="4">PHP MONTHLY RENT:</th><td colspan="3">{{ $na($business['monthly_rent']) }}</td></tr>
<tr><th colspan="4">LTFRB RESEARCH (date):</th><td colspan="5">{{ $na($field('ltfrb_research_date')) }}</td><th colspan="3">FRANCHISE/LICENSE FEE PER UNIT:</th><td colspan="3">{{ $na($field('franchise_fee')) }}</td><th colspan="4">MINIMUM YEAR MODEL REQUIREMENT:</th><td colspan="3">{{ $na($field('minimum_year_model')) }}</td><th colspan="2">MIN. UNITS:</th><td colspan="1">{{ $na($field('minimum_units')) }}</td></tr>
<tr><th colspan="4">TOTAL UNITS DECLARED:</th><td colspan="2">{{ $na($field('total_declared')) }}</td><th colspan="4">TOTAL UNITS INSPECTED:</th><td colspan="2">{{ $na($field('total_inspected')) }}</td><th colspan="4">NOT INSPECTED:</th><td colspan="1">{{ $na($field('total_not_inspected')) }}</td><th colspan="2">REASON:</th><td colspan="6">{{ $na($field('reason_not_inspected')) }}</td></tr>
</tbody></table>
<p class="business-remarks" style="margin-bottom:0"><span style="display:inline">Summary of Units Inspected:</span></p>
<table class="business-form-table business-grid-table"><colgroup><col span="25" style="width:4%"></colgroup>
<thead><tr><th colspan="4">BRAND/CAR MODEL (ONLY THOSE INSPECTED)</th><th colspan="2">YEAR MODEL</th><th colspan="2">PLATE NO.</th><th colspan="2">W/ DRIVER?</th><th colspan="2">SINCE? (MONTH/YEAR)</th><th colspan="3">DAILY BOUNDARY</th><th colspan="7">FRANCHISE/OPERATOR NAME &amp; CONTACT NO. (AS SEEN IN TAXI UNIT)</th><th colspan="3">AREA PARKED?</th></tr></thead>
<tbody>
@foreach($units as $unit)
<tr><td colspan="4">{{ $unit['brand_model'] ?? '' }}</td><td colspan="2">{{ $unit['year_model'] ?? '' }}</td><td colspan="2">{{ $unit['plate_number'] ?? '' }}</td><td colspan="2">{{ $unit['with_driver'] ?? '' }}</td><td colspan="2">{{ $unit['operating_since'] ?? '' }}</td><td colspan="3">{{ $unit['daily_boundary'] ?? '' }}</td><td colspan="7">{{ $unit['franchise_operator'] ?? '' }}</td><td colspan="3">{{ $unit['area_parked'] ?? '' }}</td></tr>
@endforeach
</tbody></table>
<div class="business-remarks"><span>OTHER REMARKS:</span><div class="business-remarks-box">{{ $na($business['report_remarks']) }}</div></div>
