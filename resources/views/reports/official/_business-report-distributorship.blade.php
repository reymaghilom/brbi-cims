@php
    $na = fn (mixed $value) => filled($value) ? $value : 'N/A';
    $mark ??= fn (bool $selected) => $selected ? '( ✓ )' : '(   )';
    $ownership = (string) ($business['ownership_type'] ?? '');
    $field = fn (string $key) => data_get($business['template_data'] ?? [], "fields.$key");
    $questionAnswer = fn (int $index) => (string) data_get($business['template_data'] ?? [], "questions.$index");
    $normalize = fn (string $value): string => preg_replace('/[^A-Z0-9]+/', '', mb_strtoupper($value));
    $selectedProducts = collect(preg_split('/\s*,\s*/', (string) $field('products_seen'), -1, PREG_SPLIT_NO_EMPTY))->map($normalize);
    $inventoryLevel = mb_strtoupper($field('inventory_level'));
    $splitStockroomValues = function (?string $value): array {
        $rows = preg_split('/\R/', (string) $value);
        if (count($rows) <= 1 && str_contains((string) $value, ',')) {
            $rows = preg_split('/\s*,\s*/', trim((string) $value), -1, PREG_SPLIT_NO_EMPTY);
        }

        return array_pad(array_slice(array_map('trim', $rows ?: []), 0, 4), 4, '');
    };
    $brandRows = $splitStockroomValues($field('top_brands'));
    $priceRows = $splitStockroomValues($field('top_brand_prices'));
    $stockroomRows = [
        ['employee_key' => 'office_staff', 'employee_label' => '# OF OFFICE STAFF:', 'inventory' => 'HIGH', 'products' => ['PHARMACEUTICALS', 'FRESH/REFRIGERATED GOODS']],
        ['employee_key' => 'agents', 'employee_label' => '# OF AGENTS:', 'inventory' => 'MODERATE', 'products' => ['BEVERAGES', 'EQUIPMENT/DEVICES/MACHINES']],
        ['employee_key' => 'drivers', 'employee_label' => '# OF DRIVERS:', 'inventory' => 'LOW', 'products' => ['CONSTRUCTION MATS.', 'HOME/CONSUMER GOODS']],
        ['employee_key' => 'helpers', 'employee_label' => '# OF HELPERS:', 'inventory' => 'NONE', 'products' => ['PACKAGED DRY FOOD', 'AGRI FEEDS/FERTILIZER/SEEDS']],
    ];
    $units = array_pad(array_slice((array) data_get($business['template_data'] ?? [], 'tables.units', []), 0, 5), 5, []);
    $products = array_pad(array_slice((array) data_get($business['template_data'] ?? [], 'tables.products', []), 0, 6), 6, []);
    $questions = [
        'WHERE ARE STOCKS PURCHASED FROM (LOCATION)?',
        'HOW MANY STORES VISITED PER DAY PER TRUCK?',
        null,
        'HOW MANY ITEMS (OR PHP VALUE) ON AVE. DOES EACH STORE BUY?',
        null,
        null,
    ];
    $answer2 = mb_strtoupper($questionAnswer(2));
    $answer4 = mb_strtoupper($questionAnswer(4));
    $answer5Raw = $questionAnswer(5);
    $answer5 = mb_strtoupper($answer5Raw);
    $isTerm = str_starts_with($answer5, 'TERM:');
    $term = $isTerm ? trim(substr($answer5Raw, 5)) : '';
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
</tbody></table>
<p class="business-remarks" style="margin-bottom:0"><span style="display:inline">Summary of Office/Warehouse/Stockroom:</span></p>
<table class="business-form-table business-grid-table"><colgroup><col span="25" style="width:4%"></colgroup>
<thead><tr><th colspan="5">EMPLOYEES</th><th colspan="3">INVENTORY LEVEL</th><th colspan="6">PRODUCTS/GOODS SEEN IN INVENTORY</th><th colspan="6">TOP BRANDS STOCKED</th><th colspan="5">SELLING PRICE</th></tr></thead>
<tbody>
@foreach($stockroomRows as $index => $stockroomRow)
<tr><td colspan="3">{{ $stockroomRow['employee_label'] }}</td><td colspan="2">{{ $na($field($stockroomRow['employee_key'])) }}</td><td colspan="3">{{ $mark($inventoryLevel === $stockroomRow['inventory']) }} {{ $stockroomRow['inventory'] }}</td><td colspan="3">{{ $mark($selectedProducts->contains($normalize($stockroomRow['products'][0]))) }} {{ $stockroomRow['products'][0] }}</td><td colspan="3">{{ $mark($selectedProducts->contains($normalize($stockroomRow['products'][1]))) }} {{ $stockroomRow['products'][1] }}</td><td colspan="6">{{ $brandRows[$index] }}</td><td colspan="5">{{ $priceRows[$index] }}</td></tr>
@endforeach
</tbody></table>
<table class="business-form-table business-profile"><colgroup><col span="25" style="width:4%"></colgroup>
<tbody>
<tr><th colspan="4">TOTAL UNITS DECLARED:</th><td colspan="2">{{ $na($field('total_declared')) }}</td><th colspan="4">TOTAL UNITS INSPECTED:</th><td colspan="2">{{ $na($field('total_inspected')) }}</td><th colspan="4">TOTAL UNITS NOT INSPECTED:</th><td>{{ $na($field('total_not_inspected')) }}</td><th colspan="2">REASON NOT INSPECTED:</th><td colspan="6">{{ $na($field('reason_not_inspected')) }}</td></tr>
</tbody></table>
<p class="business-remarks" style="margin-bottom:0"><span style="display:inline">Summary of Units Inspected:</span></p>
<table class="business-form-table business-grid-table"><colgroup><col span="25" style="width:4%"></colgroup>
<thead>
<tr><th colspan="4" rowspan="2">BRAND/VEHICLE MODEL</th><th colspan="2" rowspan="2">YEAR MODEL</th><th colspan="2" rowspan="2">PLATE NO.</th><th colspan="9">INTERVIEW WITH DRIVER/AGENT</th><th colspan="8">ACTUAL OR/CR CHECKING</th></tr>
<tr><th colspan="2">YEARS EMPLOYED</th><th colspan="5">TYPES OF GOODS/BRANDS TRANSPORTED</th><th colspan="2">AREAS/TERRITORIES?</th><th colspan="4">REGISTERED OWNER</th><th colspan="4">ENCUMBRANCES</th></tr>
</thead>
<tbody>
@foreach($units as $unit)
<tr><td colspan="4">{{ $unit['brand_model'] ?? '' }}</td><td colspan="2">{{ $unit['year_model'] ?? '' }}</td><td colspan="2">{{ $unit['plate_number'] ?? '' }}</td><td colspan="2">{{ $unit['years_employed'] ?? '' }}</td><td colspan="5">{{ $unit['goods_transported'] ?? '' }}</td><td colspan="2">{{ $unit['areas_territories'] ?? '' }}</td><td colspan="4">{{ $unit['registered_owner'] ?? '' }}</td><td colspan="4">{{ $unit['encumbrances'] ?? '' }}</td></tr>
@endforeach
</tbody></table>
<p class="business-remarks" style="margin-bottom:0"><span style="display:inline">Detailed Information Gathered from Employee/Driver/Manager Interview: / Top Sellable Products</span></p>
<table class="business-form-table business-grid-table"><colgroup><col span="25" style="width:4%"></colgroup>
<thead><tr><th colspan="8">INTERVIEW QUESTION</th><th colspan="6">ANSWER</th><th colspan="4">TOP SELLABLE PRODUCTS</th><th colspan="4">SELLING PRICE PER UNIT (ex. case/box/etc.)</th><th colspan="3">SALES PER MONTH</th></tr></thead>
<tbody>
<tr><td colspan="8">WHERE ARE STOCKS PURCHASED FROM (LOCATION)?</td><td colspan="6">{{ $questionAnswer(0) }}</td><td colspan="4">{{ $products[0]['product'] ?? '' }}</td><td colspan="4">{{ $products[0]['selling_price_per_unit'] ?? '' }}</td><td colspan="3">{{ $products[0]['sales_per_month'] ?? '' }}</td></tr>
<tr><td colspan="8">HOW MANY STORES VISITED PER DAY PER TRUCK?</td><td colspan="6">{{ $questionAnswer(1) }}</td><td colspan="4">{{ $products[1]['product'] ?? '' }}</td><td colspan="4">{{ $products[1]['selling_price_per_unit'] ?? '' }}</td><td colspan="3">{{ $products[1]['sales_per_month'] ?? '' }}</td></tr>
<tr><td colspan="8">HOW MANY DELIVERIES ARE MADE PER STORE IN A MONTH?</td><td colspan="6" class="business-options"><div class="business-choice-list">@foreach(['1X','2X','3X','4X'] as $option)<span>{{ $mark($answer2 === $option) }} {{ $option }}</span>@endforeach</div></td><td colspan="4">{{ $products[2]['product'] ?? '' }}</td><td colspan="4">{{ $products[2]['selling_price_per_unit'] ?? '' }}</td><td colspan="3">{{ $products[2]['sales_per_month'] ?? '' }}</td></tr>
<tr><td colspan="8">HOW MANY ITEMS (OR PHP VALUE) ON AVE. DOES EACH STORE BUY?</td><td colspan="6">{{ $questionAnswer(3) }}</td><td colspan="4">{{ $products[3]['product'] ?? '' }}</td><td colspan="4">{{ $products[3]['selling_price_per_unit'] ?? '' }}</td><td colspan="3">{{ $products[3]['sales_per_month'] ?? '' }}</td></tr>
<tr><td colspan="8">HOW DO YOU GIVE YOUR DAILY COLLECTIONS?</td><td colspan="6" class="business-options"><div class="business-choice-list">@foreach(['BANK DEPOSIT','REMITTANCE AGENT','OFFICE'] as $option)<span>{{ $mark($answer4 === $option) }} {{ $option }}</span>@endforeach</div></td><td colspan="4">{{ $products[4]['product'] ?? '' }}</td><td colspan="4">{{ $products[4]['selling_price_per_unit'] ?? '' }}</td><td colspan="3">{{ $products[4]['sales_per_month'] ?? '' }}</td></tr>
<tr><td colspan="8">ARE STORES GIVEN PAYMENT TERMS TO PAY FOR DELIVERIES?</td><td colspan="6" class="business-options"><div class="business-choice-list"><span>{{ $mark($answer5 === 'COD') }} COD</span><span>{{ $mark($answer5 === 'COLLECT ON NEXT DELIVERY') }} COLLECT ON NEXT DELIVERY</span><span>{{ $mark($isTerm) }} TERM: {{ $term }}</span></div></td><td colspan="4">{{ $products[5]['product'] ?? '' }}</td><td colspan="4">{{ $products[5]['selling_price_per_unit'] ?? '' }}</td><td colspan="3">{{ $products[5]['sales_per_month'] ?? '' }}</td></tr>
</tbody></table>
<div class="business-remarks"><span>OTHER REMARKS:</span><div class="business-remarks-box">{{ $na($business['report_remarks']) }}</div></div>
