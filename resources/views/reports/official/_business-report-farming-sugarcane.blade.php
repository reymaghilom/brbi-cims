@php
    $na = fn (mixed $value) => filled($value) ? $value : 'N/A';
    $mark ??= fn (bool $selected) => $selected ? '( ✓ )' : '(   )';
    $data = (array) ($business['template_data'] ?? []);
    $field = fn (string $key) => data_get($data, "fields.$key");
    $researchDate = $field('research_date');
    $farms = array_pad(array_slice((array) data_get($data, 'tables.farms', []), 0, 5), 5, []);
    $sugarmills = array_pad(array_slice((array) data_get($data, 'tables.sugarmills', []), 0, 2), 2, []);
    /**
     * Supplier rows where only the fixed "supplier_category" label is present (no other
     * data entered) are dropped and the table re-indexed on save (see prepareForValidation()
     * in UpdateBusinessIncomeSourceRequest, which exempts "supplier_category" from the
     * has-data check) — the same instability as Farming: Corn Production's suppliers table
     * (they share the same config schema columns/defaults). Each fixed category is matched
     * by its saved supplier_category text instead of array position.
     */
    $supplierCategories = ['SEEDS SUPPLIER', 'FERTILIZER SUPPLIER', 'TRUCKING PROVIDER (IF ANY)'];
    $savedSuppliers = collect((array) data_get($data, 'tables.suppliers', []));
    $suppliers = collect($supplierCategories)->map(fn (string $category) => $savedSuppliers->first(fn ($row) => strcasecmp((string) data_get($row, 'supplier_category'), $category) === 0) ?? ['supplier_category' => $category]);
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
<tr><th colspan="4" rowspan="2">INDUSTRY RESEARCH/SURVEY:<br>AS OF DATE: {{ $na($researchDate) }}</th><th colspan="4">AVE. SELLING PRICE PER LKG</th><td colspan="3">{{ $na($field('average_selling_price')) }}</td><th colspan="3">SEEDS COST PER HA:</th><td colspan="3">{{ $na($field('seed_cost_per_ha')) }}</td><th colspan="4">FERTILIZER COST PER HA:</th><td colspan="4">{{ $na($field('fertilizer_cost_per_ha')) }}</td></tr>
<tr><th colspan="4">AVE. TONNES YIELD PER HA</th><td colspan="3">{{ $na($field('average_yield_per_ha')) }}</td><th colspan="3">CROP CYCLE TERM (MOS):</th><td colspan="3">{{ $na($field('crop_cycle_months')) }}</td><th colspan="4">PEAK HARVEST MONTHS:</th><td colspan="4">{{ $na($field('peak_harvest_months')) }}</td></tr>
<tr><th colspan="3">TOTAL HA PLANTED</th><td>{{ $na($field('total_ha_planted')) }}</td><th colspan="3">TOTAL SITES/AREAS</th><td>{{ $na($field('total_sites')) }}</td><th colspan="3">TOTAL SITES VALIDATED:</th><td>{{ $na($field('sites_validated')) }}</td><th colspan="4">TOTAL SITES NOT INSPECTED:</th><td>{{ $na($field('sites_not_inspected')) }}</td><th colspan="2">REASON NOT INSPECTED:</th><td colspan="6">{{ $na($field('reason_not_inspected')) }}</td></tr>
</tbody></table>
<p class="business-remarks" style="margin-bottom:0"><span style="display:inline">Summary of Properties Inspected:</span></p>
<table class="business-form-table business-grid-table"><colgroup><col span="25" style="width:4%"></colgroup>
<thead><tr><th colspan="6">LOCATION &amp; SIZE OF LAND (HA)</th><th colspan="2">TOTAL HA</th><th>RATOON CYCLE</th><th colspan="2">OWNED/RENTED/PRENDA</th><th colspan="2">IF RENTED, ANNUAL RENT PER HA</th><th colspan="2">IF PRENDA, AMOUNT PER HA</th><th colspan="2">IF PRENDA/RENT, EXPIRY DATE</th><th colspan="3">TARGET HARVEST MONTH</th><th colspan="5">RELEVANT INFORMATION (EX. IF OWNED - NOT TRANSFERRED, INFO ON PROPERTY OWNER)</th></tr></thead>
<tbody>
@foreach($farms as $farm)
<tr><td colspan="6">{{ $farm['location_size'] ?? '' }}</td><td colspan="2">{{ $farm['total_ha'] ?? '' }}</td><td>{{ $farm['ratoon_cycle'] ?? '' }}</td><td colspan="2">{{ $farm['tenure'] ?? '' }}</td><td colspan="2">{{ $farm['annual_rent'] ?? '' }}</td><td colspan="2">{{ $farm['prenda_amount'] ?? '' }}</td><td colspan="2">{{ $farm['expiry_date'] ?? '' }}</td><td colspan="3">{{ $farm['target_harvest_month'] ?? '' }}</td><td colspan="5">{{ $farm['relevant_information'] ?? '' }}</td></tr>
@endforeach
</tbody></table>
<table class="business-form-table business-grid-table"><colgroup><col span="25" style="width:4%"></colgroup>
<thead><tr><th colspan="4">SUPPLIERS:</th><th colspan="5">BUSINESS NAME/CONTACT PERSON:</th><th colspan="5">ADDRESS:</th><th colspan="2">YEARS TRANSACTING</th><th colspan="9">PAYMENT PERFORMANCE / OTHER REMARKS:</th></tr></thead>
<tbody>
@foreach($suppliers as $supplier)
<tr><td colspan="4">{{ $supplier['supplier_category'] ?? '' }}</td><td colspan="5">{{ $supplier['supplier_name'] ?? '' }}</td><td colspan="5">{{ $supplier['office_location'] ?? '' }}</td><td colspan="2">{{ $supplier['years_transacting'] ?? '' }}</td><td colspan="9">{{ $supplier['payment_performance'] ?? '' }}</td></tr>
@endforeach
</tbody></table>
<table class="business-form-table business-grid-table"><colgroup><col span="25" style="width:4%"></colgroup>
<thead><tr><th colspan="4">SUGARMILL</th><th colspan="5">ASSOCIATION:</th><th colspan="5">ADDRESS:</th><th colspan="2">YEARS TRANSACTING</th><th colspan="9">PRODUCTION DATA LAST MILLING / OTHER REMARKS (LOANS):</th></tr></thead>
<tbody>
@foreach($sugarmills as $sugarmill)
@php($mill = (string) ($sugarmill['sugarmill'] ?? ''))
<tr><td colspan="4" class="business-options"><div class="business-choice-list"><span>{{ $mark(strcasecmp($mill, 'BUSCO') === 0) }} BUSCO</span><span>{{ $mark(strcasecmp($mill, 'CRYSTAL') === 0) }} CRYSTAL</span></div></td><td colspan="5">{{ $sugarmill['association'] ?? '' }}</td><td colspan="5">{{ $sugarmill['address'] ?? '' }}</td><td colspan="2">{{ $sugarmill['years_transacting'] ?? '' }}</td><td colspan="9">{{ $sugarmill['production_data'] ?? '' }}</td></tr>
@endforeach
</tbody></table>
<div class="business-remarks"><span>OTHER REMARKS:</span><div class="business-remarks-box">{{ $na($business['report_remarks']) }}</div></div>
