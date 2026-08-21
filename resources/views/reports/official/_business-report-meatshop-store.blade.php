@php
    $na = fn (mixed $value) => filled($value) ? $value : 'N/A';
    $mark ??= fn (bool $selected) => $selected ? '( ✓ )' : '(   )';
    // "Check all that apply" uses a clean checkmark only — no parenthesis-style formatting —
    // unlike the single-choice marks above (BORROWER/CO-MAKER, ownership type).
    $checked = fn (bool $selected) => $selected ? '✓' : '';
    $ownership = (string) ($business['ownership_type'] ?? '');
    $data = (array) ($business['template_data'] ?? []);
    $branches = array_pad(array_slice((array) data_get($data, 'tables.branches', []), 0, 3), 3, []);
    /**
     * The saved "product_type" is itself the check-all-that-apply flag — the edit form's
     * checkbox submits the category name (e.g. "Beef") when checked and nothing at all when
     * unchecked (see _business-meatshop-inventory-observations.blade.php), and unfilled rows
     * are dropped and the table re-indexed on save, so rows can't be matched by position.
     * Each fixed category is matched by its saved product_type text instead.
     */
    $meatshopCategories = [
        ['value' => 'Poultry: Chicken', 'label' => 'POULTRY: CHICKEN'],
        ['value' => 'Pork', 'label' => 'PORK'],
        ['value' => 'Beef', 'label' => 'BEEF'],
        ['value' => 'Fish', 'label' => 'FISH'],
        ['value' => 'Packaged/Canned Food', 'label' => 'PACKAGED/CANNED FOODS'],
        ['value' => 'Other Products', 'label' => 'OTHER PRODUCTS'],
    ];
    $inventoryRows = collect((array) data_get($data, 'tables.inventory', []));
    $inventory = collect($meatshopCategories)->map(function (array $category) use ($inventoryRows): array {
        $row = $inventoryRows->first(fn ($row) => strcasecmp((string) data_get($row, 'product_type'), $category['value']) === 0) ?? [];

        return $row + ['product_type' => $category['label'], 'is_checked' => filled(data_get($row, 'product_type'))];
    });
    $meatshopQuestions = [
        'Does client have good location? (residential/commercial market?)',
        'During visit, were there a lot of customers visiting/buying? (day & time)',
        'What are the most stocked products seen in the store/bodega?',
        'Do they have Point Of Sale Machine/Cash Register?',
        'How many refrigerators? What are other specialized equipment available?',
        'Were you able to see the butcher area? How many live animals are present?',
        'How many employees? How many butchers? How long employed? How much compensation per fxn?',
        'For pigs/pork/beef, how much cost per live weight kilo? When converted to carcass: how many kilos?',
        'BANK DECLARED SHOWING BUSINESS INCOME? (BANK, BRANCH) - IF ANY',
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
<p class="business-remarks" style="margin-bottom:0"><span style="display:inline">PRODUCTS/GOODS SEEN IN INVENTORY AT STORE (plus interview with the butcher):</span></p>
<table class="business-form-table business-grid-table"><colgroup><col span="25" style="width:4%"></colgroup>
<thead><tr><th>Check all that apply:</th><th colspan="3"></th><th colspan="3">MAIN SUPPLIER</th><th colspan="4">LOCATION</th><th colspan="4">CONTACT NO.</th><th colspan="3">Ave. Daily Kilos Sold</th><th colspan="3">Range of Price per Kilo</th><th colspan="4">Other Remarks</th></tr></thead>
<tbody>
@foreach($inventory as $item)
<tr><td>{{ $checked((bool) ($item['is_checked'] ?? false)) }}</td><td colspan="3">{{ $item['product_type'] ?? '' }}</td><td colspan="3">{{ $item['main_supplier'] ?? '' }}</td><td colspan="4">{{ $item['location'] ?? '' }}</td><td colspan="4">{{ $item['contact_number'] ?? '' }}</td><td colspan="3">{{ $item['daily_kilos_sold'] ?? '' }}</td><td colspan="3">{{ $item['price_range'] ?? '' }}</td><td colspan="4">{{ $item['remarks'] ?? '' }}</td></tr>
@endforeach
</tbody></table>
<p class="business-remarks" style="margin-bottom:0"><span style="display:inline">OBSERVATIONS DURING BUSINESS INSPECTION:</span></p>
<table class="business-form-table business-grid-table"><colgroup><col span="25" style="width:4%"></colgroup>
<tbody>
@foreach($meatshopQuestions as $index => $question)
<tr><td colspan="12">{{ ($index + 1).'. '.$question }}</td><td colspan="13">{{ data_get($data, "questions.$index") }}</td></tr>
@endforeach
</tbody></table>
<div class="business-remarks"><span>OTHER REMARKS:</span><div class="business-remarks-box">{{ $na($business['report_remarks']) }}</div></div>
