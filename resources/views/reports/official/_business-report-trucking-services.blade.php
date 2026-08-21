@php
    $na = fn (mixed $value) => filled($value) ? $value : 'N/A';
    $mark ??= fn (bool $selected) => $selected ? '( ✓ )' : '(   )';
    $ownership = (string) ($business['ownership_type'] ?? '');
    $field = fn (string $key) => data_get($business['template_data'] ?? [], "fields.$key");
    $units = array_pad(array_slice((array) data_get($business['template_data'] ?? [], 'tables.units', []), 0, 5), 5, []);
    $suppliers = array_pad(array_slice((array) data_get($business['template_data'] ?? [], 'tables.suppliers', []), 0, 3), 3, []);
    $supplierCategories = ['Main Fuel Supplier:', 'Repair & Maintenance', 'Main Supplier/Lender:'];
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
<tr><th colspan="4">GARAGE/ OFFICE ADDRESS:</th><td colspan="14">{{ $na($business['main_business_address']) }}</td><th colspan="4">LENGTH OF STAY:</th><td colspan="3" class="business-align-left">{{ $na($business['length_of_stay_months']) }}</td></tr>
<tr><td colspan="12" class="business-options"><div class="business-choice-list"><span>{{ $mark(strcasecmp($ownership, 'Residence Only') === 0) }} RESIDENCE ONLY</span><span>{{ $mark(strcasecmp($ownership, 'Owned') === 0) }} OWNED</span><span>{{ $mark(strcasecmp($ownership, 'Mortgaged') === 0) }} MORTGAGED FROM/</span><span>{{ $mark(strcasecmp($ownership, 'Rented') === 0) }} RENTED FROM:</span></div></td><td colspan="6">{{ $na($business['rented_from']) }}</td><th colspan="4">PHP MONTHLY RENT:</th><td colspan="3">{{ $na($business['monthly_rent']) }}</td></tr>
<tr><th colspan="4">PREVIOUS BUSINESS ADDRESS:</th><td colspan="14">{{ $na($business['previous_business_address']) }}</td><th colspan="4">LENGTH OF STAY:</th><td colspan="3" class="business-align-left">{{ $na($business['previous_business_address_length_of_stay']) }}</td></tr>
<tr><th colspan="4">REASON FOR TRANSFER:</th><td colspan="14">{{ $na($business['reason_for_transfer']) }}</td><th colspan="4">INFORMANT:</th><td colspan="3">{{ $na($business['informant']) }}</td></tr>
<tr><th colspan="4">REGISTERED OWNER:</th><td colspan="9">{{ $na($business['registered_owner']) }}</td><th colspan="7">IF REGISTERED OWNER NOT BORROWER, RELATIONSHIP:</th><td colspan="5">{{ $na($business['relationship_to_borrower']) }}</td></tr>
<tr><th colspan="4">TOTAL UNITS DECLARED:</th><td colspan="2">{{ $na($field('total_declared')) }}</td><th colspan="4">TOTAL UNITS INSPECTED:</th><td colspan="2">{{ $na($field('total_inspected')) }}</td><th colspan="4">TOTAL UNITS NOT INSPECTED:</th><td>{{ $na($field('total_not_inspected')) }}</td><th colspan="2">REASON NOT INSPECTED:</th><td colspan="6">{{ $na($field('reason_not_inspected')) }}</td></tr>
</tbody></table>
<p class="business-remarks" style="margin-bottom:0"><span style="display:inline">Summary of Units Inspected:</span></p>
<table class="business-form-table business-grid-table"><colgroup><col span="25" style="width:4%"></colgroup>
<thead>
<tr><th colspan="4" rowspan="2">BRAND/VEHICLE MODEL</th><th colspan="2" rowspan="2">YEAR MODEL</th><th colspan="2" rowspan="2">PLATE NO.</th><th colspan="9">INTERVIEW WITH DRIVER</th><th colspan="8">ACTUAL OR/CR CHECKING</th></tr>
<tr><th colspan="2">YEARS EMPLOYED</th><th colspan="5">TYPES OF GOODS/BRANDS TRANSPORTED</th><th colspan="2">AREAS PICKUP / DELIVERY SITES?</th><th colspan="4">REGISTERED OWNER</th><th colspan="4">ENCUMBRANCES</th></tr>
</thead>
<tbody>
@foreach($units as $unit)
<tr><td colspan="4">{{ $unit['brand_model'] ?? '' }}</td><td colspan="2">{{ $unit['year_model'] ?? '' }}</td><td colspan="2">{{ $unit['plate_number'] ?? '' }}</td><td colspan="2">{{ $unit['years_employed'] ?? '' }}</td><td colspan="5">{{ $unit['goods_transported'] ?? '' }}</td><td colspan="2">{{ $unit['pickup_delivery_areas'] ?? '' }}</td><td colspan="4">{{ $unit['registered_owner'] ?? '' }}</td><td colspan="4">{{ $unit['encumbrances'] ?? '' }}</td></tr>
@endforeach
</tbody></table>
<table class="business-form-table business-grid-table"><colgroup><col span="25" style="width:4%"></colgroup>
<thead><tr><th colspan="4">EMPLOYEES (HIRED BY BORROWER):</th><th colspan="4">SUPPLIERS</th><th colspan="5">BUSINESS NAME/CONTACT PERSON</th><th colspan="5">ADDRESS</th><th colspan="2">YEARS TRANSACTING</th><th colspan="5">MONTHLY AVE EXPENSE &amp; PAYMENT TRACK RECORD</th></tr></thead>
<tbody>
@foreach([['operators_count', '# OF OPERATORS:'], ['drivers_count', '# OF DRIVERS:'], ['helpers_count', '# OF HELPERS:']] as $index => [$key, $label])
    @php($supplier = $suppliers[$index] ?? [])
<tr><td colspan="2">{{ $label }}</td><td colspan="2">{{ $na($field($key)) }}</td><td colspan="4">{{ $supplier['supplier_category'] ?? $supplierCategories[$index] }}</td><td colspan="5">{{ $supplier['supplier_name'] ?? '' }}</td><td colspan="5">{{ $supplier['office_location'] ?? '' }}</td><td colspan="2">{{ $supplier['years_transacting'] ?? '' }}</td><td colspan="5">{{ $supplier['payment_performance'] ?? '' }}</td></tr>
@endforeach
</tbody></table>
<div class="business-remarks"><span>OTHER REMARKS:</span><div class="business-remarks-box">{{ $na($business['report_remarks']) }}</div></div>
