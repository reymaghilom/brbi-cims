@php
    $na = fn (mixed $value) => filled($value) ? $value : 'N/A';
    $mark ??= fn (bool $selected) => $selected ? '( ✓ )' : '(   )';
    $ownership = (string) ($business['ownership_type'] ?? '');
    $data = (array) ($business['template_data'] ?? []);
    $scale = (string) data_get($data, 'fields.scale_of_business');
    $scaleOptions = ['Restaurant' => 'RESTAURANT', 'Carenderia' => 'CARENDERIA', 'Cafeteria' => 'CAFETERIA', 'Stall' => 'STALL'];
    $mallOptions = ['Mall - Restaurant' => 'RESTAURANT', 'Mall - Stall Only' => 'STALL ONLY'];
    $branches = array_pad(array_slice((array) data_get($data, 'tables.branches', []), 0, 3), 3, []);
    $restaurantQuestions = [
        'EQUIPMENT SEEN ONSITE? (REFRIGERATOR, STOVES, OVENS, ETC.)',
        'HOW MANY WORKERS WERE ON SITE? (COOKS, WAIT STAFF, CASHIER, ETC.)',
        'INVENTORY LEVEL? (HIGH, MEDIUM, LOW, NONE)',
        'DO THEY HAVE DELIVERY (IN HOUSE, THIRD PARTY APP SERVICE?)',
        'HOW MUCH IS THEIR MENU? (TAKE PICTURE OF MENU) - INDICATE MOST SELLABLE ITEMS',
        'TARGET MARKET BASED ON LOCATION & PRICE POINT? (FAMILY, EMPLOYEES, STUDENTS?)',
        'WHERE DO THEY SOURCE INGREDIENTS/SUPPLY FOR FOOD AND DRINKS? (LOCATION/CONTACT INFO)',
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
<tr><th colspan="4">SCALE OF BUSINESS:</th><td colspan="21" class="business-options"><div class="business-choice-list">@foreach($scaleOptions as $value => $label)<span>{{ $mark($scale === $value) }} {{ $label }}</span>@endforeach<span>/ MALL OPERATIONS:</span>@foreach($mallOptions as $value => $label)<span>{{ $mark($scale === $value) }} {{ $label }}</span>@endforeach</div></td></tr>
<tr><th colspan="4">TOTAL BRANCHES DECLARED:</th><td colspan="2">{{ $na(data_get($data, 'fields.total_declared')) }}</td><th colspan="4">TOTAL BRANCHES INSPECTED:</th><td colspan="2">{{ $na(data_get($data, 'fields.total_inspected')) }}</td><th colspan="4"># BRANCHES NOT INSPECTED:</th><td>{{ $na(data_get($data, 'fields.total_not_inspected')) }}</td><th colspan="2">REASON NOT INSPECTED:</th><td colspan="6">{{ $na(data_get($data, 'fields.reason_not_inspected')) }}</td></tr>
</tbody></table>
<p class="business-remarks" style="margin-bottom:0"><span style="display:inline">Summary of Branches Inspected:</span></p>
<table class="business-form-table business-grid-table"><colgroup><col span="25" style="width:4%"></colgroup>
<thead><tr><th colspan="4">LOCATION</th><th>FRONT (SQM)</th><th>TOTAL SQM</th><th>AIRCON (Y/N)</th><th colspan="3">OPERATING DAYS &amp; HOURS</th><th># OF SHIFTS</th><th colspan="2"># OF EMPLOYEES PER SHIFT</th><th colspan="2">AVE. PHP SALES PER SHIFT</th><th colspan="2">INVENTORY LEVEL (HIGH, MID, LOW)</th><th colspan="2">RENT PER MONTH</th><th colspan="2">YEARS IN THE AREA</th><th colspan="4">IN-STORE DINING CAPACITY (# OF TABLES &amp; CHAIRS)</th></tr></thead>
<tbody>
@foreach($branches as $branch)
<tr><td colspan="4">{{ $branch['location'] ?? '' }}</td><td>{{ $branch['frontage'] ?? '' }}</td><td>{{ $branch['total_area'] ?? '' }}</td><td>{{ $branch['air_conditioned'] ?? '' }}</td><td colspan="3">{{ $branch['operating_days_hours'] ?? '' }}</td><td>{{ $branch['shifts'] ?? '' }}</td><td colspan="2">{{ $branch['employees_per_shift'] ?? '' }}</td><td colspan="2" class="business-align-right">{{ $branch['average_sales_per_shift'] ?? '' }}</td><td colspan="2">{{ $branch['inventory_level'] ?? '' }}</td><td colspan="2">{{ $branch['monthly_rent'] ?? '' }}</td><td colspan="2">{{ $branch['years_in_area'] ?? '' }}</td><td colspan="4">{{ $branch['dining_capacity'] ?? '' }}</td></tr>
@endforeach
</tbody></table>
<p class="business-remarks" style="margin-bottom:0"><span style="display:inline">OBSERVATIONS DURING BUSINESS INSPECTION:</span></p>
<table class="business-form-table business-grid-table"><colgroup><col span="25" style="width:4%"></colgroup>
<tbody>
@foreach($restaurantQuestions as $index => $question)
<tr><td colspan="12">{{ ($index + 1).'. '.$question }}</td><td colspan="13">{{ data_get($data, "questions.$index") }}</td></tr>
@endforeach
</tbody></table>
<div class="business-remarks"><span>OTHER REMARKS:</span><div class="business-remarks-box">{{ $na($business['report_remarks']) }}</div></div>
