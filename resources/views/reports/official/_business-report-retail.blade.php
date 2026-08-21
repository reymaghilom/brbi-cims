@php
    $na = fn (mixed $value) => filled($value) ? $value : 'N/A';
    $mark ??= fn (bool $selected) => $selected ? '( ✓ )' : '(   )';
    $ownership = (string) ($business['ownership_type'] ?? '');
    $scale = (string) ($business['scale'] ?? '');
    $yesNo = fn (mixed $value) => $value === null ? '' : ($value ? 'Y' : 'N');
    $branches = array_pad(array_slice($business['branches'], 0, 3), 3, []);
    $products = array_pad(array_slice($business['products'], 0, 4), 4, []);
    $observations = array_pad(array_slice($business['observations'], 0, 7), 7, []);
    $suppliers = array_pad(array_slice($business['suppliers'], 0, 3), 3, []);
    $retailQuestions = [
        'Who are the competitors near the area?', 'Does the client have a good location? (Residential / commercial market)',
        'During the visit, were there a lot of customers visiting or buying? Indicate day and time.', 'What are the most stocked products seen in the store or bodega?',
        'Do they have a Point of Sale machine or cash register?', 'Do they have refrigerated goods? How many refrigerators?',
        'Which declared bank shows the business income? Indicate bank and branch, if any.',
    ];
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
<tr><th colspan="4">REGISTERED OWNER:</th><td colspan="9">{{ $na($business['registered_owner']) }}</td><th colspan="7">IF REGISTERED OWNER NOT BORROWER, RELATIONSHIP:</th><td colspan="5">{{ $na($business['relationship_to_borrower']) }}</td></tr>
<tr><th colspan="4">SCALE OF BUSINESS:</th><td colspan="21" class="business-options"><div class="business-choice-list">@foreach(['Sari-Sari Store' => 'SARI-SARI STORE', 'Grocery Store' => 'GROCERY STORE', 'Convenience Store' => 'CONVENIENCE STORE', 'Supermarket' => 'SUPERMARKET', 'Water Refilling' => 'WATER REFILLING'] as $value => $label)<span>{{ $mark(strcasecmp($scale, $value) === 0) }} {{ $label }}</span>@endforeach</div></td></tr>
<tr><th colspan="4">TOTAL BRANCHES DECLARED:</th><td colspan="2">{{ $na($business['branches_declared']) }}</td><th colspan="4">TOTAL BRANCHES INSPECTED:</th><td colspan="2">{{ $na($business['branches_inspected']) }}</td><th colspan="4"># BRANCHES NOT INSPECTED:</th><td>{{ $na($business['branches_not_inspected']) }}</td><th colspan="2">REASON NOT INSPECTED:</th><td colspan="6">{{ $na($business['branches_reason_not_inspected']) }}</td></tr>
</tbody></table>
<p class="business-remarks" style="margin-bottom:0"><span style="display:inline">Summary of Branches Inspected:</span></p>
<table class="business-form-table business-grid-table"><colgroup><col span="25" style="width:4%"></colgroup>
<thead><tr><th colspan="4">LOCATION</th><th>FRONT (SQM)</th><th>TOTAL SQM</th><th>AIRCON (Y/N)</th><th colspan="3">OPERATING DAYS &amp; HOURS</th><th># OF SHIFTS</th><th colspan="2"># OF EMPLOYEES PER SHIFT</th><th colspan="2">AVE. PHP SALES PER SHIFT</th><th colspan="2">INVENTORY LEVEL (HIGH, MID, LOW)</th><th colspan="2">RENT PER MONTH</th><th colspan="2">YEARS IN THE AREA</th><th colspan="4">BIG BRANDS NEAR THE AREA?</th></tr></thead>
<tbody>
@foreach($branches as $branch)
<tr><td colspan="4">{{ $branch['location'] ?? '' }}</td><td>{{ $branch['frontage_meters'] ?? '' }}</td><td>{{ $branch['total_area_square_meters'] ?? '' }}</td><td>{{ $yesNo($branch['is_air_conditioned'] ?? null) }}</td><td colspan="3">{{ $branch['operating_days_hours'] ?? '' }}</td><td>{{ $branch['shifts_count'] ?? '' }}</td><td colspan="2">{{ $branch['employees_per_shift'] ?? '' }}</td><td colspan="2" class="business-align-right">{{ $branch['average_sales_per_shift'] ?? '' }}</td><td colspan="2">{{ $branch['inventory_level'] ?? '' }}</td><td colspan="2">{{ $branch['monthly_rent'] ?? '' }}</td><td colspan="2">{{ $branch['years_in_area'] ?? '' }}</td><td colspan="4">{{ $branch['nearby_brands'] ?? '' }}</td></tr>
@endforeach
</tbody></table>
<table class="business-form-table business-grid-table"><colgroup><col span="25" style="width:4%"></colgroup>
<thead><tr><th colspan="4">Top Sellable Products</th><th colspan="3">Selling Price per Item</th><th colspan="18">OBSERVATIONS DURING BUSINESS INSPECTION:</th></tr></thead>
<tbody>
@foreach($observations as $index => $observation)
<tr><td colspan="4">{{ $products[$index]['product_name'] ?? '' }}</td><td colspan="3" class="business-align-right">{{ $products[$index]['selling_price'] ?? '' }}</td><td colspan="8">{{ ($index + 1).'. '.$retailQuestions[$index] }}</td><td colspan="10">{{ $observation['answer'] ?? '' }}</td></tr>
@endforeach
</tbody></table>
<p class="business-remarks" style="margin-bottom:0"><span style="display:inline">Supplier Validation - Especially Supplier of Top Sellable Products (if applicable):</span></p>
<table class="business-form-table business-grid-table"><colgroup><col span="25" style="width:4%"></colgroup>
<thead><tr><th colspan="4">SUPPLIER NAME</th><th colspan="5">OFFICE LOCATION</th><th>CONFIRMED (Y/N)</th><th colspan="15">IMPORTANT REMARKS (CONTACT INFORMATION, YEARS TRANSACTING, BAD / GOOD PAYMENT PERFORMANCE, ETC.)</th></tr></thead>
<tbody>
@foreach($suppliers as $supplier)
<tr><td colspan="4">{{ $supplier['supplier_name'] ?? '' }}</td><td colspan="5">{{ $supplier['office_location'] ?? '' }}</td><td>{{ $yesNo($supplier['is_confirmed'] ?? null) }}</td><td colspan="15">{{ $supplier['remarks'] ?? '' }}</td></tr>
@endforeach
</tbody></table>
<div class="business-remarks"><span>OTHER REMARKS:</span><div class="business-remarks-box">{{ $na($business['report_remarks']) }}</div></div>
