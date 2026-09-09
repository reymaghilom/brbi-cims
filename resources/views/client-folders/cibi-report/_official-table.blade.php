@php
    $rows = old($section);
    $hasOldInput = $rows !== null;
    if ($rows === null) {
        $rows = $records->map(function ($record) use ($section) {
            $row = $record->toArray();
            if ($section === 'bank_accounts') {
                $level = (string) $record->adb_level;
                preg_match('/^(low|mid|high)(?:\s*\/\s*figures:\s*(.*))?$/i', $level, $matches);
                $row['adb_level_choice'] = isset($matches[1]) ? strtolower($matches[1]) : null;
                $row['adb_level_figures'] = $matches[2] ?? ($row['adb_level_choice'] ? null : $level);
                $row['capital_share_text'] = $record->capital_share_text ?? $record->capital_share_amount;
            }
            if ($section === 'loan_records') {
                $row['cycle_label'] = $record->cycle_label ?? $record->cycle_number;
                $row['combined_findings'] = collect([$record->payment_performance, $record->remarks])->filter()->implode("\n");
            }
            return $row;
        })->all();
    }
    $prefillRows = match($section) {
        'bank_accounts' => $bankAccountPrefillRows ?? [],
        'loan_records' => $loanRecordPrefillRows ?? [],
        default => [],
    };
    if (! $hasOldInput && $prefillRows !== []) {
        $rows = array_merge($rows, array_map(fn (array $row): array => $row + ['_prefill' => true], $prefillRows));
    }
    // III (Bank) shows a minimum of 3 rows so encoders have room to work in without first clicking
    // "Add"; V (Income Sources Validation) starts with just 1. Those blank rows are display-only
    // and are dropped on save (SaveCibiReport never persists a child row that has no id and no
    // filled field). IV (Loan) pads NOTHING: a Bank/Coop group is a real institution, so only
    // saved, runtime-prefilled and explicitly added ones are ever rendered.
    $minRows = match($section) {
        'loan_records' => 0,
        'income_summaries' => 1,
        default => 3,
    };
    $rows = array_pad($rows, $minRows, []);
    $headers = match($section) {
        'bank_accounts' => ['Institution', 'Branch', 'Year Opened', 'ADB Level', 'CA / SA / Share Capital', 'Remarks', ''],
        'loan_records' => ['Bank / Coop / Branch', 'Original Amount', 'Balance', 'Amortization', 'Granted / Maturity', 'Cycle / Security', 'Performance & Findings', ''],
        default => ['Income Source Validated', 'Stability', 'Key Information', ''],
    };
    // IV. stays a flat Excel-style table: one row per loan result, and one row for a Bank/Coop
    // that has no loan result at all. Rows carrying the same institution are gathered into a
    // consecutive run (first-appearance order) so the institution name can be printed on the run's
    // FIRST row only, exactly like the official sheet; a blank institution always starts its own
    // run so blank rows never collapse into each other. Row order inside the rendered table is
    // what determines the submitted index — and therefore sort_order — so indices are assigned
    // here in final DOM order rather than from the source array's own keys.
    $loanGroups = [];
    if ($section === 'loan_records') {
        $groupKeys = [];
        foreach ($rows as $row) {
            $institution = trim((string) (data_get($row, 'institution') ?? ''));
            $key = $institution === '' ? null : mb_strtolower($institution);
            if ($key !== null && isset($groupKeys[$key])) {
                $loanGroups[$groupKeys[$key]]['rows'][] = $row;
                continue;
            }
            if ($key !== null) {
                $groupKeys[$key] = count($loanGroups);
            }
            $loanGroups[] = ['institution' => $institution, 'rows' => [$row]];
        }
        $loanDetailFields = ['original_amount', 'remaining_balance', 'amortization_amount', 'granted_date', 'maturity_date', 'cycle_label', 'cycle_number', 'security_type'];
        $loanRowIndex = 0;
    }
@endphp
<section id="{{ $section }}-section" class="cibi-paper-section scroll-mt-24" data-repeater="{{ $section }}" @if($section === 'loan_records') data-loan-groups @endif><header class="cibi-section-heading flex items-start justify-between gap-3"><h2>{{ $title }}</h2>@unless($section === 'loan_records')<button type="button" class="ui-button-secondary !min-h-9 !px-3 !py-1.5 text-xs" data-repeater-add>+ {{ $addLabel }}</button>@endunless</header><div class="cibi-entry-table-wrap overflow-x-auto rounded-control border border-ui-border"><table @class(['cibi-entry-table min-w-full', 'cibi-bank-entry-table' => $section === 'bank_accounts', 'cibi-loan-entry-table' => $section === 'loan_records', 'cibi-income-entry-table' => $section === 'income_summaries'])><thead><tr>@foreach($headers as $header)<th scope="col">{{ $header }}</th>@endforeach</tr></thead><tbody data-repeater-rows>
@if($section === 'loan_records')
@foreach($loanGroups as $groupPosition => $group)
@php $groupId = 'loan-group-'.$groupPosition; $groupEmpty = count($group['rows']) === 1 && ! collect($loanDetailFields)->contains(fn (string $field): bool => filled(data_get($group['rows'][0], $field))); @endphp
@foreach($group['rows'] as $groupRowPosition => $row)
@include('client-folders.cibi-report._loan-result-row', ['index' => $loanRowIndex++, 'row' => $row, 'groupId' => $groupId, 'institution' => $group['institution'], 'first' => $groupRowPosition === 0, 'empty' => $groupEmpty, 'template' => false])
@endforeach
@endforeach
<tr class="cibi-loan-empty-state-row" data-loan-empty-state @if($loanGroups !== []) hidden @endif><td colspan="8">No bank or cooperative added yet.</td></tr>
@else
@foreach($rows as $index => $row)
@include('client-folders.cibi-report._official-table-row', compact('section', 'index', 'row') + ['template' => false])
@endforeach
@endif
</tbody></table></div>
@if($section === 'loan_records')
<div class="cibi-loan-group-add"><button type="button" class="ui-button-secondary !min-h-9 !px-3 !py-1.5 text-xs" data-repeater-add><x-ui.icon name="plus" size="size-3.5" />{{ $addLabel }}</button></div>
<template data-loan-result-template>@include('client-folders.cibi-report._loan-result-row', ['index' => '__INDEX__', 'row' => [], 'groupId' => '__GROUP__', 'institution' => '', 'first' => true, 'empty' => false, 'template' => true])</template>
@else
<template data-repeater-template>@include('client-folders.cibi-report._official-table-row', ['section' => $section, 'index' => '__INDEX__', 'row' => [], 'template' => true])</template>
@endif
@error($section)<p class="ui-error mt-3" role="alert">{{ $message }}</p>@enderror</section>
