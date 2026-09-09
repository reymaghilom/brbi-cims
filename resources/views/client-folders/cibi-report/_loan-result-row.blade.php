{{--
    ONE ROW of IV. Summary on Credit / Loan Information — the digital equivalent of one line in
    the official Excel sheet, and still exactly one flat `cibi_loan_records` row.

    A Bank / Coop occupies one row per loan result (and one row when it has no loan result at
    all). `institution` is a single column — cibi_loan_records has no separate `branch` — so this
    one field deliberately carries BOTH the bank/coop and its branch, exactly like the official
    sheet's "BANK/COOP - BRANCH (PER ACCOUNT)" column; its label and placeholder say so.
    Every row of a group carries its own institution input, because that is what the flat
    table actually persists — but only the FIRST row of a consecutive group shows it: the rest
    hide the control so the name is never repeated down the column, exactly like the Excel form,
    while still posting the mirrored value app.js keeps in sync. That means no institution value
    is ever lost on save, whichever row the encoder happens to edit or remove.

    The group's own actions live beside that first input: "+" adds a loan result, and the trash
    drops the whole institution ("Remove Bank / Coop"). The trailing action column carries the
    row-level trash ("Remove Loan"), which is hidden on a zero-result row because there is no loan
    to remove there. The two destructive actions carry DIFFERENT icons on purpose — a plain X for
    dropping the whole institution, a trash can for dropping one loan — so they can be told apart
    at a glance; both stay icon-only to keep the row compact, and each carries its own title and
    aria-label so neither is ever anonymous.

    Paired controls (Granted/Maturity, Cycle/Security) sit side by side inside their own cell and
    are identified by title/aria-label rather than stacked labels, so the row stays one line.
--}}
@php $prefix = "loan_records[{$index}]"; $id = data_get($row, 'id'); @endphp
<tr data-repeater-row data-loan-result data-loan-group="{{ $groupId }}" @if($empty) data-loan-empty @endif @if(data_get($row, '_delete')) hidden @endif>
    <td class="cibi-loan-institution-cell"><div class="cibi-loan-institution-controls" data-loan-institution-controls @unless($first) hidden @endunless><input aria-label="Bank / Coop / Branch" title="Bank / Coop / Branch" name="{{ $prefix }}[institution]" value="{{ $institution }}" placeholder="Enter bank/coop name" class="ui-control cibi-loan-institution-input" data-loan-institution-input><span class="cibi-loan-institution-actions"><button type="button" class="cibi-loan-icon-button" title="Add Another Loan" aria-label="Add Another Loan" data-loan-add-result><x-ui.icon name="plus" size="size-3.5" /></button><button type="button" class="cibi-remove-entry-button" title="Remove Bank / Coop" aria-label="Remove Bank / Coop" data-loan-group-remove><x-ui.icon name="close" size="size-3.5" /></button></span></div></td>
    @foreach(['original_amount'=>'Original amount','remaining_balance'=>'Remaining balance','amortization_amount'=>'Amortization amount'] as $field=>$label)<td data-loan-detail-cell><div data-loan-detail-controls @if($empty) hidden @endif><input aria-label="{{ $label }}" title="{{ $label }}" name="{{ $prefix }}[{{ $field }}]" value="{{ $empty ? '' : data_get($row, $field) }}" inputmode="decimal" class="ui-control cibi-loan-amount-input" data-number-format></div><span class="cibi-loan-blank" data-loan-detail-blank @unless($empty) hidden @endunless>&mdash;</span></td>@endforeach
    <td data-loan-detail-cell><div class="cibi-loan-date-controls cibi-loan-paired-controls" data-loan-detail-controls @if($empty) hidden @endif><input aria-label="Granted date" title="Granted Date" type="date" name="{{ $prefix }}[granted_date]" value="{{ ! $empty && data_get($row, 'granted_date') ? Illuminate\Support\Carbon::parse(data_get($row, 'granted_date'))->format('Y-m-d') : '' }}" class="ui-control cibi-compact-date-input"><input aria-label="Maturity date" title="Maturity Date" type="date" name="{{ $prefix }}[maturity_date]" value="{{ ! $empty && data_get($row, 'maturity_date') ? Illuminate\Support\Carbon::parse(data_get($row, 'maturity_date'))->format('Y-m-d') : '' }}" class="ui-control cibi-compact-date-input"></div><span class="cibi-loan-blank" data-loan-detail-blank @unless($empty) hidden @endunless>&mdash;</span></td>
    <td data-loan-detail-cell><div class="cibi-loan-meta-controls cibi-loan-paired-controls" data-loan-detail-controls @if($empty) hidden @endif><input aria-label="Cycle number" title="Cycle No." name="{{ $prefix }}[cycle_label]" value="{{ $empty ? '' : data_get($row, 'cycle_label') }}" placeholder="Cycle" class="ui-control cibi-cycle-input"><input aria-label="Type of security" title="Type of Security" name="{{ $prefix }}[security_type]" value="{{ $empty ? '' : data_get($row, 'security_type') }}" placeholder="Security" class="ui-control cibi-security-input"><input type="hidden" name="{{ $prefix }}[cycle_number]" value="{{ $empty ? '' : data_get($row, 'cycle_number') }}"></div><span class="cibi-loan-blank" data-loan-detail-blank @unless($empty) hidden @endunless>&mdash;</span></td>
    <td class="cibi-loan-findings-cell"><textarea aria-label="Payment performance and relevant findings" name="{{ $prefix }}[combined_findings]" rows="2" class="ui-control">{{ data_get($row, 'combined_findings') }}</textarea></td>
    <td class="cibi-entry-action-cell"><input type="hidden" name="{{ $prefix }}[id]" value="{{ $id }}"><input type="hidden" name="{{ $prefix }}[_delete]" value="0" data-delete-field><button type="button" class="cibi-remove-entry-button" title="Remove Loan" aria-label="Remove Loan" data-loan-result-remove @if($empty) hidden @endif><x-ui.icon name="trash" size="size-3.5" /></button></td>
</tr>
