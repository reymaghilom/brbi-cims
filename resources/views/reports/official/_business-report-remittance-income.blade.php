@php
    $na = fn (mixed $value) => filled($value) ? $value : 'N/A';
    $mark ??= fn (bool $selected) => $selected ? '( ✓ )' : '(   )';
    $data = (array) ($business['template_data'] ?? []);
    $remittanceQuestions = [
        'NAME OF THE PERSON REMITTING THE FUNDS (INDICATE CONTACT INFO, IF APPLICABLE)',
        'ADDRESS/ LOCATION OF THE PERSON REMITTING FUNDS',
        'RELATIONSHIP OF REMITTER TO THE APPLICANT?',
        'WHAT IS THE NATURE OF WORK/SOURCE OF INCOME OF REMITTER? (INDICATE BUSINESS/EMPLOYER)',
        'HOW OFTEN DOES REMITTER SEND FUNDS?',
        'WHICH BANK CAN THE REMITTANCE BE SEEN? (PROOF OF REMITTANCE)',
        'IF REMITTER IS EMPLOYED, IS THERE CONTRACT SUBMITTED WITH SALARY AND EMPLOYER INFO?',
        'HOW MUCH MONTHLY REMITTANCE IS RECEIVED THAT CAN BE SEEN IN PROOFS SUBMITTED?',
        'IS THERE ANY INFO GATHERED ON FIELD THAT SHOW REMITTANCE CASH FLOW IS NOT STABLE?',
        'WHEN DID APPLICANT START RECEIVING REMITTANCES:',
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
</tbody></table>
<table class="business-form-table business-grid-table"><colgroup><col span="25" style="width:4%"></colgroup>
<tbody>
@foreach($remittanceQuestions as $index => $question)
<tr><td colspan="12">{{ ($index + 1).'. '.$question }}</td><td colspan="13">{{ data_get($data, "questions.$index") }}</td></tr>
@endforeach
</tbody></table>
<div class="business-remarks"><span>OTHER REMARKS:</span><div class="business-remarks-box">{{ $na($business['report_remarks']) }}</div></div>
