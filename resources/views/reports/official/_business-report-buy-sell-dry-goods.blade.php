@php
    $na = fn (mixed $value) => filled($value) ? $value : 'N/A';
    $mark ??= fn (bool $selected) => $selected ? '( ✓ )' : '(   )';
    $ownership = (string) ($business['ownership_type'] ?? '');
    $data = (array) ($business['template_data'] ?? []);
    $storeType = (string) data_get($data, 'fields.store_type');
    $branches = array_pad(array_slice((array) data_get($data, 'tables.branches', []), 0, 3), 3, []);
    $products = array_pad(array_slice((array) data_get($data, 'tables.products', []), 0, 8), 8, []);
    $suppliers = array_pad(array_slice((array) data_get($data, 'tables.suppliers', []), 0, 3), 3, []);
    /**
     * The saved answers are keyed by the config schema's question order (config/business-report-templates.php,
     * "buy_sell_dry_goods"), but this section's print layout follows the reference workbook's own row order,
     * which is not the same sequence — the bank question is asked third in the schema but printed last here.
     * Each entry below is [print label, config question index] so the right saved answer lands on the right row.
     */
    $buySellDryGoodsQuestions = [
        ['Who are the competitors near the area?', 0],
        ['Does client have good location? (residential/commercial market?)', 1],
        ['During visit, were there a lot of customers visiting/buying? (day & time)', 2],
        ['What are the most stocked products seen in the store/bodega?', 4],
        ['Do they offer credit/installment plans to customer? (payment terms)', 5],
        ['Proofs of Payment to Suppliers?', 6],
        ['Are items brand new or second hand?', 7],
        ['Bank declared showing business income? (bank, branch) - if any', 3],
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
<tr><th colspan="4">TYPE OF STORE (CHECK APPLICABLE):</th><td colspan="21" class="business-options"><div class="business-choice-list">@foreach(['High End Display', 'Middle Tier Store', 'Ukay-Ukay', 'Stall Type Only'] as $option)<span>{{ $mark(strcasecmp($storeType, $option) === 0) }} {{ mb_strtoupper($option) }}</span>@endforeach</div></td></tr>
<tr><th colspan="4">TOTAL BRANCHES DECLARED:</th><td colspan="2">{{ $na(data_get($data, 'fields.total_declared')) }}</td><th colspan="4">TOTAL BRANCHES INSPECTED:</th><td colspan="2">{{ $na(data_get($data, 'fields.total_inspected')) }}</td><th colspan="4"># BRANCHES NOT INSPECTED:</th><td>{{ $na(data_get($data, 'fields.total_not_inspected')) }}</td><th colspan="2">REASON NOT INSPECTED:</th><td colspan="6">{{ $na(data_get($data, 'fields.reason_not_inspected')) }}</td></tr>
</tbody></table>
<p class="business-remarks" style="margin-bottom:0"><span style="display:inline">Summary of Branches Inspected:</span></p>
<table class="business-form-table business-grid-table"><colgroup><col span="25" style="width:4%"></colgroup>
<thead><tr><th colspan="4">LOCATION</th><th>FRONT (SQM)</th><th>TOTAL SQM</th><th>AIRCON (Y/N)</th><th colspan="3">OPERATING DAYS &amp; HOURS</th><th># OF SHIFTS</th><th colspan="2"># OF EMPLOYEES PER SHIFT</th><th colspan="2">AVE. PHP SALES PER SHIFT</th><th colspan="2">INVENTORY LEVEL (HIGH, MID, LOW)</th><th colspan="2">RENT PER MONTH</th><th colspan="2">YEARS IN THE AREA</th><th colspan="4">BIG BRANDS NEAR THE AREA?</th></tr></thead>
<tbody>
@foreach($branches as $branch)
<tr><td colspan="4">{{ $branch['location'] ?? '' }}</td><td>{{ $branch['frontage'] ?? '' }}</td><td>{{ $branch['total_area'] ?? '' }}</td><td>{{ $branch['air_conditioned'] ?? '' }}</td><td colspan="3">{{ $branch['operating_days_hours'] ?? '' }}</td><td>{{ $branch['shifts'] ?? '' }}</td><td colspan="2">{{ $branch['employees_per_shift'] ?? '' }}</td><td colspan="2" class="business-align-right">{{ $branch['average_sales_per_shift'] ?? '' }}</td><td colspan="2">{{ $branch['inventory_level'] ?? '' }}</td><td colspan="2">{{ $branch['monthly_rent'] ?? '' }}</td><td colspan="2">{{ $branch['years_in_area'] ?? '' }}</td><td colspan="4">{{ $branch['nearby_brands'] ?? '' }}</td></tr>
@endforeach
</tbody></table>
<table class="business-form-table business-grid-table"><colgroup><col span="25" style="width:4%"></colgroup>
<thead><tr><th colspan="4">Top Sellable Products</th><th colspan="3">Selling Price per Item</th><th colspan="18">OBSERVATIONS DURING BUSINESS INSPECTION:</th></tr></thead>
<tbody>
@foreach($buySellDryGoodsQuestions as $index => [$question, $answerIndex])
<tr><td colspan="4">{{ $products[$index]['product'] ?? '' }}</td><td colspan="3" class="business-align-right">{{ $products[$index]['selling_price'] ?? '' }}</td><td colspan="8">{{ ($index + 1).'. '.$question }}</td><td colspan="10">{{ data_get($data, "questions.$answerIndex") }}</td></tr>
@endforeach
</tbody></table>
<p class="business-remarks" style="margin-bottom:0"><span style="display:inline">Supplier Validation - Especially Supplier of Top Sellable Products (if applicable):</span></p>
<table class="business-form-table business-grid-table"><colgroup><col span="25" style="width:4%"></colgroup>
<thead><tr><th colspan="4">SUPPLIER NAME</th><th colspan="5">OFFICE LOCATION</th><th>CONFIRMED (Y/N)</th><th colspan="15">IMPORTANT REMARKS</th></tr></thead>
<tbody>
@foreach($suppliers as $supplier)
<tr><td colspan="4">{{ $supplier['supplier_name'] ?? '' }}</td><td colspan="5">{{ $supplier['office_location'] ?? '' }}</td><td>{{ $supplier['confirmed'] ?? '' }}</td><td colspan="15">{{ $supplier['payment_performance'] ?? '' }}</td></tr>
@endforeach
</tbody></table>
<div class="business-remarks"><span>OTHER REMARKS:</span><div class="business-remarks-box">{{ $na($business['report_remarks']) }}</div></div>
