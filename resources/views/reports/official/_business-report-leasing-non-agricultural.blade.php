@php
    $na = fn (mixed $value) => filled($value) ? $value : 'N/A';
    $mark ??= fn (bool $selected) => $selected ? '( ✓ )' : '(   )';
    $ownership = (string) ($business['ownership_type'] ?? '');
    $businessType = (string) ($business['business_type'] ?? '');
    $yesNo = fn (mixed $value) => $value === null ? '' : ($value ? 'Y' : 'N');
    $properties = array_pad(array_slice((array) ($business['properties'] ?? []), 0, 5), 5, []);
    $notInspectedCount = max(0, (int) ($business['properties_declared'] ?? 0) - (int) ($business['properties_inspected'] ?? 0));
    $propertyTypeMark = function (?string $type, string $needle): string {
        return str_contains(mb_strtolower((string) $type), $needle) ? '( ✓ )' : '(   )';
    };
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
<tr><th colspan="4">BUSINESS NAME:</th><td colspan="14">{{ $na($business['business_name']) }}</td><th colspan="4">YEAR ESTABLISHED:</th><td colspan="3" class="business-align-left">{{ $na($business['year_established']) }}</td></tr>
<tr><th colspan="4">MAIN BUSINESS ADDRESS:</th><td colspan="14">{{ $na($business['main_business_address']) }}</td><th colspan="4">LENGTH OF STAY:</th><td colspan="3" class="business-align-left">{{ $na($business['length_of_stay_months']) }}</td></tr>
<tr><td colspan="12" class="business-options"><div class="business-choice-list"><span>{{ $mark(strcasecmp($ownership, 'Residence Only') === 0) }} RESIDENCE ONLY</span><span>{{ $mark(strcasecmp($ownership, 'Owned') === 0) }} OWNED</span><span>{{ $mark(strcasecmp($ownership, 'Mortgaged') === 0) }} MORTGAGED FROM/</span><span>{{ $mark(strcasecmp($ownership, 'Rented') === 0) }} RENTED FROM:</span></div></td><td colspan="6">{{ $na($business['rented_from']) }}</td><th colspan="4">PHP MONTHLY RENT:</th><td colspan="3">{{ $na($business['monthly_rent']) }}</td></tr>
<tr><th colspan="4">PREVIOUS BUSINESS ADDRESS:</th><td colspan="14">{{ $na($business['previous_business_address']) }}</td><th colspan="4">LENGTH OF STAY:</th><td colspan="3" class="business-align-left">{{ $na($business['previous_business_address_length_of_stay']) }}</td></tr>
<tr><th colspan="4">REASON FOR TRANSFER:</th><td colspan="14">{{ $na($business['reason_for_transfer']) }}</td><th colspan="4">INFORMANT:</th><td colspan="3">{{ $na($business['informant']) }}</td></tr>
<tr><th colspan="4">REGISTERED OWNER:</th><td colspan="9">{{ $na($business['registered_owner']) }}</td><td colspan="12" class="business-options"><div class="business-choice-list"><span>{{ $mark(strcasecmp($businessType, 'Registered') === 0) }} LEASING BUSINESS REGISTERED</span><span>{{ $mark(strcasecmp($businessType, 'Not Registered') === 0) }} LEASING BUSINESS NOT REGISTERED</span></div></td></tr>
<tr><th colspan="6">TOTAL PROPERTIES DECLARED:</th><td colspan="3">{{ $na($business['properties_declared']) }}</td><th colspan="6">TOTAL PROPERTIES INSPECTED:</th><td colspan="2">{{ $na($business['properties_inspected']) }}</td><th colspan="4">NOT INSPECTED:</th><td colspan="4">{{ $notInspectedCount }}</td></tr>
</tbody></table>
<p class="business-remarks" style="margin-bottom:0"><span style="display:inline">Summary of Properties Inspected:</span></p>
<table class="business-form-table business-grid-table"><colgroup><col span="25" style="width:4%"></colgroup>
<thead><tr><th colspan="6">TYPE OF REAL ESTATE (PER PROPERTY DECLARED)</th><th colspan="2">INSPECTED? (Y/N)</th><th colspan="3">TOTAL UNITS AVAILABLE</th><th colspan="2">UNITS W/ TENANTS</th><th colspan="5">LOCATION &amp; TOTAL SQM OF BUILDING</th><th colspan="5">TENANT INFORMATION (ENUMERATE NAME &amp; MONTHLY RENT &amp; YEARS RENTING)</th><th colspan="2">W/ CONTRACT? (Y/N)</th></tr></thead>
<tbody>
@foreach($properties as $property)
<tr><td colspan="6" class="business-options"><div class="business-choice-list"><span>{{ $propertyTypeMark($property['property_type'] ?? null, 'warehouse') }} WAREHOUSE</span><span>{{ $propertyTypeMark($property['property_type'] ?? null, 'comm') }} COMM'L</span><span>{{ $propertyTypeMark($property['property_type'] ?? null, 'res') }} RES'L</span></div></td><td colspan="2">{{ $yesNo($property['is_inspected'] ?? null) }}</td><td colspan="3">{{ $property['units_available'] ?? '' }}</td><td colspan="2">{{ $property['units_with_tenants'] ?? '' }}</td><td colspan="5">{{ trim(($property['location'] ?? '').(filled($property['area_square_meters'] ?? null) ? ' ('.$property['area_square_meters'].' sqm)' : '')) }}</td><td colspan="5">{{ $property['tenant_information'] ?? '' }}</td><td colspan="2">{{ $yesNo($property['has_contract'] ?? null) }}</td></tr>
@endforeach
</tbody></table>
<div class="business-remarks"><span>OTHER REMARKS:</span><div class="business-remarks-box">{{ $na($business['report_remarks']) }}</div></div>
