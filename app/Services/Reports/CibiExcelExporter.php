<?php

namespace App\Services\Reports;

use App\Enums\RecordState;
use App\Models\ClientFolder;
use App\Models\CoMaker;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class CibiExcelExporter
{
    private const SHEET = 'CI REPORT - CIBI';

    private const BANK_CAPACITY = 5;

    private const LOAN_CAPACITY = 5;

    private const INCOME_CAPACITY = 3;

    public function generate(ClientFolder $folder, ?CoMaker $activePerson = null): string
    {
        $report = $folder->cibiReport()->where('co_maker_id', $activePerson?->id)->with([
            'investigator:id,full_name',
            'bankAccounts' => fn ($query) => $query->orderBy('sort_order')->orderBy('id'),
            'loanRecords' => fn ($query) => $query->orderBy('sort_order')->orderBy('id'),
            'incomeSourceSummaries' => fn ($query) => $query->orderBy('sort_order')->orderBy('id'),
        ])->first();

        abort_if($report === null, 422, 'Save the CI / BI report before downloading Excel.');
        abort_unless($report->state === RecordState::Complete, 422, 'Complete the CI / BI report before downloading Excel.');

        $banks = $this->populated($report->bankAccounts, ['institution', 'branch', 'year_opened', 'adb_level', 'capital_share_amount', 'capital_share_text', 'relevant_remarks']);
        $loans = $this->populated($report->loanRecords, ['institution', 'original_amount', 'remaining_balance', 'amortization_amount', 'granted_date', 'maturity_date', 'cycle_number', 'cycle_label', 'security_type', 'payment_performance', 'remarks']);
        $incomes = $this->populated($report->incomeSourceSummaries, ['source_name', 'stability_result', 'key_information']);
        abort_if($banks->count() > self::BANK_CAPACITY, 422, 'The official Excel template supports up to 5 Bank / Financial Institution records.');
        abort_if($loans->count() > self::LOAN_CAPACITY, 422, 'The official Excel template supports up to 5 Credit / Loan records.');
        abort_if($incomes->count() > self::INCOME_CAPACITY, 422, 'The official Excel template supports up to 3 Income Source records.');

        $template = resource_path('report-templates/cibi-report.xlsx');
        abort_unless(is_file($template), 500, 'The official CI / BI Excel template is unavailable.');
        $book = IOFactory::load($template);
        $sheet = $book->getSheetByName(self::SHEET);
        abort_unless($sheet instanceof Worksheet, 500, 'The official CI / BI worksheet is unavailable.');
        $this->assertTemplate($book->getSheetCount(), $sheet);

        $personal = $report->personal_snapshot ?? [];
        $this->value($sheet, 'G6', mb_strtoupper($this->na($report->investigator?->full_name)));
        $this->value($sheet, 'T6', $this->na($report->branch_name));
        $this->date($sheet, 'G7', $report->start_date);
        $this->value($sheet, 'T7', $this->na($report->account_officer_name));
        $this->date($sheet, 'G8', $report->submitted_date);
        $this->value($sheet, 'T8', $this->numberOrNa($report->amount_applied));
        // Derived from $activePerson (the report's actual owner), not the stored party_type
        // column — see the matching note in OfficialReportDataBuilder::cibi().
        $this->value($sheet, 'C9', $this->choices($activePerson ? 'co_maker' : 'borrower', ['borrower' => 'BORROWER', 'co_maker' => 'CO-MAKER']));
        $this->value($sheet, 'T9', $this->choices($report->ci_risk_level, ['very_low' => 'VERY LOW', 'low' => 'LOW', 'mid' => 'MID', 'high' => 'HIGH', 'very_high' => 'VERY HIGH']));

        $this->value($sheet, 'G11', $activePerson?->full_name ?? $folder->display_name);
        $this->value($sheet, 'Y11', $this->na($personal['age'] ?? null));
        $this->value($sheet, 'G12', $this->na($personal['spouse_name'] ?? null));
        $this->value($sheet, 'Y12', $this->na($personal['spouse_age'] ?? null));
        $this->value($sheet, 'G13', $this->na($personal['present_address'] ?? null));
        $this->value($sheet, 'Y13', $this->na($personal['length_of_stay_months'] ?? null));
        $residence = (string) ($personal['residence_status'] ?? '');
        $this->value($sheet, 'C14', $this->choices($residence, ['Owned' => 'OWNED', 'Mortgaged' => 'MORTGAGED FROM', 'Rented' => 'RENTED FROM:']));
        $this->value($sheet, 'L14', $this->na($personal['residence_status_from'] ?? null));
        $this->value($sheet, 'Y14', $this->na($personal['monthly_rent'] ?? null));
        $this->value($sheet, 'C15', $this->choices($residence === 'Living with Parents' ? $residence : null, ['Living with Parents' => 'LIVING W PARENTS:', 'Owned' => 'OWNED', 'Mortgaged' => 'MORTGAGED', 'Rented' => 'RENTED']));
        $this->value($sheet, 'R15', $this->na($personal['other_residences'] ?? null));
        $this->value($sheet, 'G16', $this->choices($personal['home_condition'] ?? null, ['New' => 'NEW', 'Slightly New' => 'SLIGHTLY NEW', 'Ancestral' => 'ANCESTRAL', 'Apartment' => 'APARTMENT', 'Dorm' => 'DORM', 'Shanty' => 'SHANTY']));
        $this->value($sheet, 'Y16', $this->na($personal['number_of_storeys'] ?? null));
        $this->value($sheet, 'G17', $this->choices($personal['material_cost_level'] ?? null, ['Expensive' => 'EXPENSIVE', 'Medium' => 'MEDIUM', 'Low' => 'LOW']));
        $this->value($sheet, 'Q17', $this->choices($personal['living_condition'] ?? null, ['Excellent' => 'EXCELLENT', 'Good' => 'GOOD', 'Poor' => 'POOR']));
        $this->value($sheet, 'G18', $this->na($personal['previous_address'] ?? null));
        $this->value($sheet, 'Y18', $this->na($personal['previous_address_length_of_stay_months'] ?? null));
        $this->value($sheet, 'G19', $this->na($personal['parents_address'] ?? null));
        $this->value($sheet, 'Y19', $this->na($personal['dependents_count'] ?? null));
        $this->value($sheet, 'G20', $this->choices($personal['civil_status'] ?? null, ['Single' => 'SINGLE', 'Married' => 'MARRIED', 'Separated' => 'SEPARATED / DIVORCED', 'Divorced' => 'SEPARATED / DIVORCED', 'Common Law' => 'COMMON LAW', 'Widowed' => 'WIDOWED']));
        $this->value($sheet, 'Y20', $this->na($personal['separated_year'] ?? null));
        $reputation = $personal['reputation'] ?? null;
        $this->value($sheet, 'G21', $this->choices(in_array($reputation, ['Heavily Indebted', 'Frequent Collector Visits'], true) ? 'Heavily Indebted' : $reputation, ['Unknown' => 'UNKNOWN', 'Good' => 'GOOD', 'Rich' => 'RICH', 'Drunkard' => 'DRUNKARD', 'Adulterous' => 'ADULTEROUS', 'Gambler' => 'GAMBLER', 'Heavily Indebted' => 'HEAVILY INDEBTED/FREQUENT COLLECTOR VISITS']));
        $this->value($sheet, 'G22', $this->choices($personal['barangay_findings'] ?? null, ['No Legal Cases' => 'NO LEGAL CASES', 'With Legal Case' => 'WITH LEGAL CASE:']));
        $this->value($sheet, 'P22', $this->na(($personal['barangay_findings'] ?? null) === 'With Legal Case' ? ($personal['court_background'] ?? null) : null));
        $this->value($sheet, 'G23', $this->choices($personal['court_background_status'] ?? null, ['N/A' => 'N/A', 'No Legal Cases' => 'NO LEGAL CASES', 'With Legal Case' => 'WITH LEGAL CASE:']));
        $this->value($sheet, 'P23', $this->na(($personal['court_background_status'] ?? null) === 'With Legal Case' ? ($personal['court_background'] ?? null) : null));
        $this->value($sheet, 'G24', $this->choices($personal['lifestyle'] ?? null, ['Modest' => 'MODEST', 'Extravagant' => 'EXTRAVAGANT', 'Below Average' => 'BELOW AVERAGE']));
        $this->value($sheet, 'T24', $this->na($personal['vehicles_owned'] ?? null));
        $this->value($sheet, 'J25', $this->na($personal['contact_details'] ?? null));
        $this->suppressNumberStoredAsTextWarning($sheet, 'J25', $personal['contact_details'] ?? null);
        $this->value($sheet, 'E26', $this->na($personal['other_remarks'] ?? null));

        $purposes = $report->purpose_codes ?? [];
        foreach ([
            'C28' => ['working_capital', 'WORKING CAPITAL (INVENTORY/RECEIVABLES)'],
            'N28' => ['business_expansion', 'BUSINESS EXPANSION: RENOVATION / START UP INVENTORY'],
            'C29' => ['buyout_debt_consolidation', 'BUYOUT/DEBT CONSOLIDATION'],
            'N29' => ['chattel_property_acquisition', 'CHATTEL PROPERTY ACQUISITION'],
            'C30' => ['building_construction_home_renovation', 'BUILDING CONSTRUCTION / HOME RENOVATION'],
            'N30' => ['real_estate_property_acquisition', 'REAL ESTATE PROPERTY ACQUISITION'],
            'C31' => ['personal', 'PERSONAL:  MEDICAL EXPENSES / EDUCATION / TRAVEL / ESTATE MGT'],
            'N31' => ['others', 'OTHERS'],
        ] as $coordinate => [$code, $label]) {
            $suffix = $code === 'others' && filled($report->purpose_other) ? ': '.$report->purpose_other : '';
            $this->value($sheet, $coordinate, $this->mark(in_array($code, $purposes, true)).' '.$label.$suffix);
        }
        $this->value($sheet, 'E32', $this->na($report->purpose_remarks));

        // The reference template's own data rows are size 12, and some columns (institution,
        // branch, original amount) are bold — inconsistent with the size-8 headers and with
        // every other data column, which is why the amounts and remarks looked mismatched.
        // Every written cell is normalized to size 10, not bold, matching what the reference
        // workbook already uses consistently everywhere else.
        $bankColumns = ['C', 'G', 'J', 'L', 'Q', 'R', 'U'];
        foreach ($banks as $index => $bank) {
            $row = 36 + $index;
            [$adbChoices, $adbFigures] = $this->adb($bank->adb_level);
            $this->value($sheet, 'C'.$row, $this->na($bank->institution));
            $this->value($sheet, 'G'.$row, $this->na($bank->branch));
            $this->value($sheet, 'J'.$row, $this->na($bank->year_opened));
            $this->value($sheet, 'L'.$row, $adbChoices);
            $this->value($sheet, 'Q'.$row, $adbFigures);
            $this->value($sheet, 'R'.$row, filled($bank->capital_share_text) ? $bank->capital_share_text : $this->numberOrNa($bank->capital_share_amount));
            $this->value($sheet, 'U'.$row, $this->na($bank->relevant_remarks));
            foreach ($bankColumns as $column) {
                $sheet->getStyle($column.$row)->getFont()->setSize(10)->setBold(false);
            }
        }
        $this->value($sheet, 'E42', 'N/A');

        $loanColumns = ['C', 'G', 'J', 'M', 'P', 'S', 'T', 'V'];
        foreach ($loans as $index => $loan) {
            $row = 45 + $index;
            $this->value($sheet, 'C'.$row, $this->na($loan->institution));
            $this->value($sheet, 'G'.$row, $this->numberOrNa($loan->original_amount));
            $this->value($sheet, 'J'.$row, $this->numberOrNa($loan->remaining_balance));
            $this->value($sheet, 'M'.$row, $this->numberOrNa($loan->amortization_amount));
            $this->value($sheet, 'P'.$row, $this->dateText($loan->granted_date).' - '.$this->dateText($loan->maturity_date));
            $this->value($sheet, 'S'.$row, $this->na($loan->cycle_label ?: $loan->cycle_number));
            $this->value($sheet, 'T'.$row, $this->na($loan->security_type));
            $this->value($sheet, 'V'.$row, $this->joined([$loan->payment_performance, $loan->remarks], ' '));
            foreach ($loanColumns as $column) {
                $sheet->getStyle($column.$row)->getFont()->setSize(10)->setBold(false);
            }
        }
        $totals = $report->summary_totals ?? [];
        $this->value($sheet, 'H52', (int) ($totals['institutions_checked'] ?? $report->creditChecks()->whereNotNull('institution')->count()));
        $this->value($sheet, 'H53', (int) ($totals['institutions_declared'] ?? $report->creditChecks()->where('is_declared', true)->count()));
        $this->value($sheet, 'H54', (int) ($totals['loan_records_found'] ?? $loans->count()));
        $this->value($sheet, 'J53', $this->na($report->negative_credit_findings));
        $this->value($sheet, 'E56', $this->na($report->other_remarks));

        foreach ($incomes as $index => $income) {
            $row = 60 + $index;
            $this->value($sheet, 'C'.$row, $this->na($income->source_name));
            $this->value($sheet, 'G'.$row, $this->choices($income->stability_result, ['existing_strong_capacity' => 'EXISTING WITH STRONG CAPACITY', 'existing_weak_capacity' => 'EXISTING BUT WEAK CAPACITY', 'not_validated' => 'WAS NOT/CANNOT BE VALIDATED'], ' / '));
            $this->value($sheet, 'P'.$row, $this->na($income->key_information));
        }

        $this->value($sheet, 'G63', mb_strtoupper($this->na($report->prepared_by_name ?: $report->investigator?->full_name)));
        $this->value($sheet, 'S63', null);
        $book->getProperties()
            ->setCreator('Binhi Rural Bank Inc.')
            ->setTitle('CI / BI Report - '.($activePerson?->full_name ?? $folder->display_name))
            ->setSubject('Saved CI / BI report data');
        $book->setActiveSheetIndexByName(self::SHEET);

        $temporary = tempnam(sys_get_temp_dir(), 'brbi-cibi-xlsx-');
        abort_if($temporary === false, 500, 'Unable to prepare the Excel report.');
        try {
            (new Xlsx($book))->save($temporary);
            $bytes = file_get_contents($temporary);
            abort_if($bytes === false, 500, 'Unable to read the generated Excel report.');

            return $bytes;
        } finally {
            $book->disconnectWorksheets();
            @unlink($temporary);
        }
    }

    private function assertTemplate(int $sheetCount, Worksheet $sheet): void
    {
        abort_unless(
            $sheetCount === 1
            && $sheet->getPageSetup()->getPrintArea() === 'B2:AB64'
            && $sheet->getPageSetup()->getPaperSize() === 14
            && count($sheet->getMergeCells()) >= 190,
            500,
            'The official CI / BI Excel template failed its integrity check.',
        );
    }

    private function populated(Collection $records, array $fields): Collection
    {
        return $records->filter(fn ($record): bool => collect($record->only($fields))->contains(fn ($value): bool => filled($value)))->values();
    }

    private function value(Worksheet $sheet, string $coordinate, mixed $value): void
    {
        $sheet->setCellValue($coordinate, $value);
    }

    private function date(Worksheet $sheet, string $coordinate, mixed $value): void
    {
        $this->value($sheet, $coordinate, $value ? ExcelDate::dateTimeToExcel($value) : 'N/A');
    }

    private function dateText(mixed $value): string
    {
        return $value ? $value->format('n/j/Y') : 'N/A';
    }

    private function mark(bool $selected): string
    {
        return $selected ? '( ✓ )' : '(   )';
    }

    /**
     * Marks a cell to ignore Excel's "Number Stored as Text" check — only when the written
     * value is the kind that actually triggers it (an all-digit string; a leading zero, as in
     * a mobile number, is exactly why it was written as text in the first place, not a real
     * mismatch). A contact value with a "/" separator, an email address, or multiple numbers
     * never matches this, so it's left alone and never flagged by Excel to begin with. Leaves
     * the cell's own data type — already correctly text, via PhpSpreadsheet's own value binder
     * — untouched; this only suppresses the workbook-side warning indicator.
     */
    private function suppressNumberStoredAsTextWarning(Worksheet $sheet, string $coordinate, mixed $value): void
    {
        if (is_string($value) && preg_match('/^\d+$/', $value) === 1) {
            $sheet->getCell($coordinate)->getIgnoredErrors()->setNumberStoredAsText(true);
        }
    }

    private function choices(mixed $selected, array $options, string $separator = '  '): string
    {
        return collect($options)->map(fn (string $label, string $value): string => $this->mark(strcasecmp((string) $selected, $value) === 0 || strcasecmp((string) $selected, $label) === 0).' '.$label)->implode($separator);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function adb(mixed $value): array
    {
        $text = trim((string) $value);
        $level = str($text)->before('/')->trim()->lower()->toString();
        $figures = str_contains($text, '/')
            ? str($text)->after('/')->replaceMatches('/^\s*figures:\s*/i', '')->trim()->toString()
            : '';

        if (! in_array($level, ['low', 'mid', 'high'], true)) {
            $figures = $text;
            $level = '';
        }

        return [
            $this->choices($level, ['low' => 'LOW', 'mid' => 'MID', 'high' => 'HIGH']).' / FIGURES:',
            $this->na($figures),
        ];
    }

    private function numberOrNa(mixed $value): float|string
    {
        return filled($value) ? (float) $value : 'N/A';
    }

    private function na(mixed $value): string
    {
        return filled($value) ? (string) $value : 'N/A';
    }

    private function joined(array $values, string $separator): string
    {
        $joined = collect($values)->filter(fn ($value) => filled($value))->implode($separator);

        return filled($joined) ? $joined : 'N/A';
    }
}
