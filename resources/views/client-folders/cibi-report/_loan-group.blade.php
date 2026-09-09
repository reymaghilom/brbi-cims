{{--
    Bank / Coop / Branch GROUP header for IV. Summary on Credit / Loan Information.

    The institution is encoded once here for the whole group, so the label never repeats down the
    table. Its input is named for the group's FIRST loan-result row so inline validation errors
    still land on a visible field; app.js keeps one hidden mirror per additional row in
    [data-loan-institution-mirrors], so every row in the group posts the same institution and the
    flat cibi_loan_records shape stays untouched.
--}}
<tr class="cibi-loan-group-row" data-loan-group-header data-loan-group="{{ $groupId }}">
    <td colspan="8" class="cibi-loan-group-cell">
        <div class="cibi-loan-group-bar">
            <label class="cibi-loan-group-label" for="loan-institution-{{ $groupId }}">Bank / Coop / Branch</label>
            <input id="loan-institution-{{ $groupId }}" aria-label="Bank cooperative or branch" name="loan_records[{{ $institutionIndex }}][institution]" value="{{ $institution }}" placeholder="Institution name" class="ui-control cibi-loan-group-input" data-loan-institution-input>
            <span data-loan-institution-mirrors></span>
            <span class="cibi-loan-group-actions">
                <button type="button" class="cibi-loan-inline-button" title="Add a loan result under this bank / coop" data-loan-add-result><x-ui.icon name="plus" size="size-3.5" />Add Loan Result</button>
                <button type="button" class="cibi-remove-entry-button" title="Remove this bank / coop and its loan results" aria-label="Remove this bank or coop" data-loan-group-remove><x-ui.icon name="trash" size="size-4" /></button>
            </span>
        </div>
    </td>
</tr>
