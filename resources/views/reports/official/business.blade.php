@php
    $business = $document['business'];
    $mark = fn (bool $selected) => $selected ? '( ✓ )' : '(   )';
    $excelMappedTemplates = ['retail_grocery_water_refilling', 'leasing_truck_equipment', 'leasing_non_agricultural', 'leasing_agricultural', 'leasing_poultry_farm', 'taxi_operator', 'puj_van_jeepney_operator', 'trucking_services', 'distributorship_wholesaler_b2b', 'pharmacy_drugstore', 'general_merchandise_hardware_parts', 'buy_sell_dry_goods', 'meatshop_store', 'contractor_subcontractor', 'restaurant_food_stall', 'farming_corn', 'farming_sugarcane', 'remittance_income', 'other_business_source_of_income'];
    $hasExcelMappedGrid = in_array($business['template_type'], $excelMappedTemplates, true);
@endphp
@php($showCommonHeader = $showCommonHeader ?? true)
@if($showCommonHeader)
<header class="business-official-header"><div><div class="business-title-line"><h1>CREDIT INVESTIGATION REPORT</h1><strong>INDIVIDUAL ACCOUNT</strong></div><p class="business-scope">(SOURCE OF INCOME VALIDATION)</p><p class="business-confidential">RESTRICTED &amp; CONFIDENTIAL <span>(v.as of -2020.08.03)</span></p></div><img src="{{ $pdfMode ? public_path('assets/branding/binhi-rural-bank-wordmark.png') : asset('assets/branding/binhi-rural-bank-wordmark.png') }}" alt="Binhi Rural Bank Inc."></header>
@unless($hasExcelMappedGrid)
<table class="business-meta"><tbody>
<tr><th>CI-IN CHARGE:</th><td>{{ $business['ci_in_charge'] }}</td><th>BRANCH:</th><td>{{ $business['branch'] }}</td></tr>
<tr><th>START DATE OF CI:</th><td>{{ $business['start_date'] }}</td><th>{{ $business['name_label'] }}:</th><td>{{ $business['applicant_name'] }}</td></tr>
<tr><th>DATE SUBMITTED TO CA:</th><td>{{ $business['submitted_date'] }}</td><th>ACCOUNT OFFICER:</th><td>{{ $business['account_officer'] }}</td></tr>
<tr><td colspan="2" class="business-options"><div class="business-choice-list"><span>{{ $mark($business['party_type'] === 'borrower') }} BORROWER</span><span>{{ $mark($business['party_type'] === 'co_maker') }} CO-MAKER</span></div></td><th>AMOUNT APPLIED:</th><td>{{ $business['amount_applied'] }}</td></tr>
</tbody></table>
@endunless
@endif
@if($business['template_type'] === 'retail_grocery_water_refilling')
    @include('reports.official._business-report-retail', ['business' => $business, 'mark' => $mark, 'showCommonHeader' => $showCommonHeader])
@elseif($business['template_type'] === 'leasing_truck_equipment')
    @include('reports.official._business-report-leasing-truck-equipment', ['business' => $business, 'mark' => $mark, 'showCommonHeader' => $showCommonHeader])
@elseif($business['template_type'] === 'leasing_non_agricultural')
    @include('reports.official._business-report-leasing-non-agricultural', ['business' => $business, 'mark' => $mark, 'showCommonHeader' => $showCommonHeader])
@elseif($business['template_type'] === 'leasing_agricultural')
    @include('reports.official._business-report-leasing-agricultural', ['business' => $business, 'mark' => $mark, 'showCommonHeader' => $showCommonHeader])
@elseif($business['template_type'] === 'leasing_poultry_farm')
    @include('reports.official._business-report-leasing-poultry-farm', ['business' => $business, 'mark' => $mark, 'showCommonHeader' => $showCommonHeader])
@elseif($business['template_type'] === 'taxi_operator')
    @include('reports.official._business-report-taxi-operator', ['business' => $business, 'mark' => $mark, 'showCommonHeader' => $showCommonHeader])
@elseif($business['template_type'] === 'puj_van_jeepney_operator')
    @include('reports.official._business-report-puj-van-jeepney', ['business' => $business, 'mark' => $mark, 'showCommonHeader' => $showCommonHeader])
@elseif($business['template_type'] === 'trucking_services')
    @include('reports.official._business-report-trucking-services', ['business' => $business, 'mark' => $mark, 'showCommonHeader' => $showCommonHeader])
@elseif($business['template_type'] === 'distributorship_wholesaler_b2b')
    @include('reports.official._business-report-distributorship', ['business' => $business, 'mark' => $mark, 'showCommonHeader' => $showCommonHeader])
@elseif($business['template_type'] === 'pharmacy_drugstore')
    @include('reports.official._business-report-pharmacy', ['business' => $business, 'mark' => $mark, 'showCommonHeader' => $showCommonHeader])
@elseif($business['template_type'] === 'general_merchandise_hardware_parts')
    @include('reports.official._business-report-general-merchandise', ['business' => $business, 'mark' => $mark, 'showCommonHeader' => $showCommonHeader])
@elseif($business['template_type'] === 'buy_sell_dry_goods')
    @include('reports.official._business-report-buy-sell-dry-goods', ['business' => $business, 'mark' => $mark, 'showCommonHeader' => $showCommonHeader])
@elseif($business['template_type'] === 'meatshop_store')
    @include('reports.official._business-report-meatshop-store', ['business' => $business, 'mark' => $mark, 'showCommonHeader' => $showCommonHeader])
@elseif($business['template_type'] === 'contractor_subcontractor')
    @include('reports.official._business-report-contractor-subcontractor', ['business' => $business, 'mark' => $mark, 'showCommonHeader' => $showCommonHeader])
@elseif($business['template_type'] === 'restaurant_food_stall')
    @include('reports.official._business-report-restaurant-food-stall', ['business' => $business, 'mark' => $mark, 'showCommonHeader' => $showCommonHeader])
@elseif($business['template_type'] === 'farming_corn')
    @include('reports.official._business-report-farming-corn', ['business' => $business, 'mark' => $mark, 'showCommonHeader' => $showCommonHeader])
@elseif($business['template_type'] === 'farming_sugarcane')
    @include('reports.official._business-report-farming-sugarcane', ['business' => $business, 'mark' => $mark, 'showCommonHeader' => $showCommonHeader])
@elseif($business['template_type'] === 'remittance_income')
    @include('reports.official._business-report-remittance-income', ['business' => $business, 'mark' => $mark, 'showCommonHeader' => $showCommonHeader])
@elseif($business['template_type'] === 'other_business_source_of_income')
    @include('reports.official._business-report-other-income-source', ['business' => $business, 'mark' => $mark, 'showCommonHeader' => $showCommonHeader])
@else
    <table class="business-form-table{{ ($showCommonHeader ?? true) ? '' : ' business-batch-continuation-first' }}"><tbody><tr class="business-section-bar"><th>{{ $business['section_title'] }}</th></tr></tbody></table>
    @include('reports.official._business-report-generic', ['document' => $document])
@endif
