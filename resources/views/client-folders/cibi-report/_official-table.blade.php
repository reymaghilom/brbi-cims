@php
    $rows = old($section);
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
    // III (Bank / Financial Institution) always shows a minimum of 3 rows so encoders have room
    // to work in without first clicking "Add" — the extra blank rows are display-only and are
    // dropped on save (SaveCibiReport never persists a child row that has no id and no filled
    // field). Every other section (including IV) shows only its actual saved rows, padded up to
    // exactly 1 blank row only when there are none yet.
    $minRows = $section === 'bank_accounts' ? 3 : 1;
    $rows = array_pad($rows, $minRows, []);
    $headers = match($section) {
        'bank_accounts' => ['Institution', 'Branch', 'Year Opened', 'ADB Level', 'CA / SA / Share Capital', 'Remarks', ''],
        'loan_records' => ['Bank / Coop / Branch', 'Original Amount', 'Balance', 'Amortization', 'Granted / Maturity', 'Cycle / Security', 'Performance & Findings', ''],
        default => ['Income Source Validated', 'Stability', 'Key Information', ''],
    };
@endphp
<section id="{{ $section }}-section" class="cibi-paper-section scroll-mt-24" data-repeater="{{ $section }}"><header class="cibi-section-heading flex items-start justify-between gap-3"><h2>{{ $title }}</h2><button type="button" class="ui-button-secondary !min-h-9 !px-3 !py-1.5 text-xs" data-repeater-add>+ {{ $addLabel }}</button></header><div class="cibi-entry-table-wrap overflow-x-auto rounded-control border border-ui-border"><table @class(['cibi-entry-table min-w-full', 'cibi-bank-entry-table' => $section === 'bank_accounts', 'cibi-loan-entry-table' => $section === 'loan_records', 'cibi-income-entry-table' => $section === 'income_summaries'])><thead><tr>@foreach($headers as $header)<th scope="col">{{ $header }}</th>@endforeach</tr></thead><tbody data-repeater-rows>@foreach($rows as $index => $row)@include('client-folders.cibi-report._official-table-row', compact('section', 'index', 'row') + ['template' => false])@endforeach</tbody></table></div><template data-repeater-template>@include('client-folders.cibi-report._official-table-row', ['section' => $section, 'index' => '__INDEX__', 'row' => [], 'template' => true])</template>@error($section)<p class="ui-error mt-3" role="alert">{{ $message }}</p>@enderror</section>
