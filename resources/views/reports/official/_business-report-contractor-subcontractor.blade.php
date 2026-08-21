@php
    $na = fn (mixed $value) => filled($value) ? $value : 'N/A';
    $mark ??= fn (bool $selected) => $selected ? '( ✓ )' : '(   )';
    $ownership = (string) ($business['ownership_type'] ?? '');
    $data = (array) ($business['template_data'] ?? []);
    $projects = array_pad(array_slice((array) data_get($data, 'tables.projects', []), 0, 3), 3, []);
    $suppliers = array_pad(array_slice((array) data_get($data, 'tables.suppliers', []), 0, 3), 3, []);
    $observationQuestions = [
        'EQUIPMENT (& # OF UNITS) SEEN ONSITE? (DT, MINI DT, BACKHOE, GRADER, ROLLER, ELF, ETC.)',
        'INDICATE IF ABOVE EQUIPMENT ARE OWNED OR RENTED (PER VALIDATION)?',
        'IF THERE ARE UNITS THAT ARE RENTED (ASK WHO THE SUPPLIER IS) WITH CONTACT INFORAMTION.',
        'IS CONSTRUCTION ONGOING, STALLED, OR COMPLETED DURING YOUR VISIT (DAY AND TIME OF VISIT)',
        'HOW MANY WORKERS WERE ON SITE? (ENGRS, FOREMAN/LEADMAN, SKILLED, LABOR)',
    ];
    $additionalValidationQuestions = [
        'Does contractor/subcontractor have PhilGepps for Government Contracts?',
        'Does contractor/subcontractor have PCAB license?',
        'If none above, then who is the contractor they tie up with for the necessary licenses? (Contact details)',
        'BANK DECLARED SHOWING BUSINESS INCOME? (BANK, BRANCH) - IFY ANY',
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
<tr><th colspan="4">DECLARED ONGOING PROJECTS:</th><td colspan="2">{{ $na(data_get($data, 'fields.projects_declared')) }}</td><th colspan="4"># OF PROJECTS INSPECTED:</th><td colspan="2">{{ $na(data_get($data, 'fields.projects_inspected')) }}</td><th colspan="4"># PROJECTS NOT INSPECTED:</th><td>{{ $na(data_get($data, 'fields.projects_not_inspected')) }}</td><th colspan="2">REASON NOT INSPECTED:</th><td colspan="6">{{ $na(data_get($data, 'fields.reason_not_inspected')) }}</td></tr>
</tbody></table>
<p class="business-remarks" style="margin-bottom:0"><span style="display:inline">Summary of Projects Inpsected (Validation with onsite personnel):</span></p>
<table class="business-form-table business-grid-table"><colgroup><col span="25" style="width:4%"></colgroup>
<thead><tr><th colspan="6">PROJECT OWNER (CLIENT) AND/ OR MAIN CONTRACTOR</th><th colspan="5">LOCATION OF PROJECT</th><th>GOV'T? (Y/N)</th><th colspan="5">SCOPE OF WORK (VALIDATED AND OBSERVED)</th><th colspan="3">START DATE</th><th colspan="3">TARGET COMPLETION DATE</th><th colspan="2">% COMPLETED?</th></tr></thead>
<tbody>
@foreach($projects as $project)
<tr><td colspan="6">{{ $project['project_owner'] ?? '' }}</td><td colspan="5">{{ $project['location'] ?? '' }}</td><td>{{ $project['government'] ?? '' }}</td><td colspan="5">{{ $project['scope_of_work'] ?? '' }}</td><td colspan="3">{{ $project['start_date'] ?? '' }}</td><td colspan="3">{{ $project['target_completion_date'] ?? '' }}</td><td colspan="2">{{ $project['percent_completed'] ?? '' }}</td></tr>
@endforeach
</tbody></table>
<p class="business-remarks" style="margin-bottom:0"><span style="display:inline">OBSERVATIONS DURING BUSINESS INSPECTION:</span></p>
<table class="business-form-table business-grid-table"><colgroup><col span="25" style="width:4%"></colgroup>
<tbody>
@foreach($observationQuestions as $index => $question)
<tr><td colspan="12">{{ ($index + 1).'. '.$question }}</td><td colspan="13">{{ data_get($data, "questions.$index") }}</td></tr>
@endforeach
</tbody></table>
<p class="business-remarks" style="margin-bottom:0"><span style="display:inline">Supplier Validation - Especially Suppliers for Main materials or services required (ex. Fuel gas station, CHB, cement, kabilya, aggregates &amp; filling materials, tiles, glass, equipment rental service):</span></p>
<table class="business-form-table business-grid-table"><colgroup><col span="25" style="width:4%"></colgroup>
<thead><tr><th colspan="4">SUPPLIER NAME</th><th colspan="5">OFFICE LOCATION</th><th>CONFIRMED (Y/N)</th><th colspan="15">IMPORTANT REMARKS (CONTACT INFORMATION, YEARS TRANSACTING, BAD / GOOD PAYMENT PERFORMANCE, ETC.)</th></tr></thead>
<tbody>
@foreach($suppliers as $supplier)
<tr><td colspan="4">{{ $supplier['supplier_name'] ?? '' }}</td><td colspan="5">{{ $supplier['office_location'] ?? '' }}</td><td>{{ $supplier['confirmed'] ?? '' }}</td><td colspan="15">{{ $supplier['payment_performance'] ?? '' }}</td></tr>
@endforeach
</tbody></table>
<p class="business-remarks" style="margin-bottom:0"><span style="display:inline">For Additional Validation:</span></p>
<table class="business-form-table business-grid-table"><colgroup><col span="25" style="width:4%"></colgroup>
<tbody>
@foreach($additionalValidationQuestions as $index => $question)
<tr><td colspan="12">{{ ($index + 1).'. '.$question }}</td><td colspan="13">{{ data_get($data, 'questions.'.($index + 5)) }}</td></tr>
@endforeach
</tbody></table>
<div class="business-remarks"><span>OTHER REMARKS:</span><div class="business-remarks-box">{{ $na($business['report_remarks']) }}</div></div>
