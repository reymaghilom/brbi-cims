<?php

namespace App\Services\Reports;

use App\Enums\OfficialReportType;
use App\Enums\RecordState;
use App\Models\ClientFolder;
use App\Models\IncomeSource;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class OfficialReportDataBuilder
{
    /** @return array<string, mixed> */
    public function build(ClientFolder $folder, OfficialReportType $type, ?IncomeSource $source = null): array
    {
        if ($type->requiresIncomeSource()) {
            $this->validateSource($folder, $type, $source);
        }

        $folder->loadMissing('assignedInvestigator:id,full_name');

        return match ($type) {
            OfficialReportType::Cibi => $this->cibi($folder),
            OfficialReportType::BusinessIncomeSource => $this->business($folder, $source),
            OfficialReportType::GeneralIncomeSource => $this->generalIncome($folder, $source),
            OfficialReportType::ResidenceBusinessPhoto => $this->residenceBusiness($folder),
        };
    }

    private function validateSource(ClientFolder $folder, OfficialReportType $type, ?IncomeSource $source): void
    {
        $valid = $source !== null && $source->client_folder_id === $folder->id && ! $source->trashed();
        if ($valid) {
            $source->loadMissing('template');
            $valid = $type === OfficialReportType::GeneralIncomeSource
                ? $source->template->is_fallback
                : ! $source->template->is_fallback;
        }

        if (! $valid) {
            throw ValidationException::withMessages(['income_source_id' => 'Select a compatible income source from this client folder.']);
        }
    }

    /** @return array<string, mixed> */
    private function base(ClientFolder $folder, OfficialReportType $type): array
    {
        return [
            'type' => $type->value,
            'title' => strtoupper($type->label()),
            'subtitle' => 'BRBI Credit Investigation Management System',
            'folder_number' => $folder->folder_number,
            'client_name' => $folder->display_name,
            'assigned_ci' => $folder->assignedInvestigator?->full_name,
            'generated_display_at' => now()->timezone(config('cims.display_timezone'))->format('F j, Y g:i A'),
            'paper' => ['width' => 8.5, 'height' => 13.0, 'margins' => ['top' => 0.45, 'right' => 0.45, 'bottom' => 0.45, 'left' => 0.45]],
            'header' => [],
            'sections' => [],
            'photo_sections' => [],
        ];
    }

    /** @return array<string, mixed> */
    private function cibi(ClientFolder $folder): array
    {
        $report = $folder->cibiReport()->with(['investigator:id,full_name', 'bankAccounts', 'loanRecords', 'creditChecks', 'incomeSourceSummaries', 'legalFindings'])->first();
        abort_if($report === null, 422, 'Save the CI / BI report before generating an official output.');
        abort_unless($report->state === RecordState::Complete, 422, 'Complete the CI / BI report before generating an official output.');

        $data = $this->base($folder, OfficialReportType::Cibi);
        $data['subtitle'] = 'CREDIT INVESTIGATION REPORT - INDIVIDUAL ACCOUNT';
        $data['source_revision'] = $report->revision;
        $data['header'] = [
            ['Start Date of CI', $this->date($report->start_date)], ['Date Submitted', $this->date($report->submitted_date)],
            ['Applicant Name', $folder->display_name], ['Party Type', $this->human($report->party_type?->value)],
            ['Branch', $report->branch_name], ['Account Officer', $report->account_officer_name],
            ['Amount Applied', $this->amount($report->amount_applied)], ['CI in Charge', $report->investigator?->full_name],
            ['CI Risk Assessment', $this->human($report->ci_risk_level)], ['Purpose', $this->na(collect($report->purpose_codes ?? [])->map(fn ($item) => $this->human($item))->implode(', '))],
        ];
        $personal = collect($report->personal_snapshot ?? [])->filter(fn ($value): bool => filled($value));
        $data['cibi'] = [
            'ci_in_charge' => $this->na($report->investigator?->full_name),
            'branch' => $this->na($report->branch_name),
            'start_date' => $this->shortDate($report->start_date),
            'account_officer' => $this->na($report->account_officer_name),
            'submitted_date' => $this->shortDate($report->submitted_date),
            'amount_applied' => $this->amount($report->amount_applied),
            'party_type' => $report->party_type?->value,
            'risk_level' => $report->ci_risk_level,
            'personal' => collect($report->personal_snapshot ?? [])->map(fn ($value) => $this->na($value))->all(),
            'purpose_codes' => $report->purpose_codes ?? [],
            'purpose_remarks' => $this->na($report->purpose_remarks),
            'bank_accounts' => $report->bankAccounts->map(fn ($row) => [
                $this->na($row->institution), $this->na($row->branch), $this->na($row->year_opened), $this->na($row->adb_level),
                $this->na($row->capital_share_text ?: $this->amount($row->capital_share_amount)), $this->na($row->relevant_remarks),
            ])->all(),
            'loan_records' => $report->loanRecords->map(fn ($row) => [
                $this->na($row->institution), $this->amount($row->original_amount), $this->amount($row->remaining_balance), $this->amount($row->amortization_amount),
                $this->shortDate($row->granted_date).' - '.$this->shortDate($row->maturity_date),
                $this->na($row->cycle_label ?: $row->cycle_number), $this->na($row->security_type), $this->na(trim(($row->payment_performance ?? '').' '.($row->remarks ?? ''))),
            ])->all(),
            'totals' => [
                'checked' => $report->summary_totals['institutions_checked'] ?? $report->creditChecks->whereNotNull('institution')->count(),
                'declared' => $report->summary_totals['institutions_declared'] ?? $report->creditChecks->where('is_declared', true)->count(),
                'loans' => $report->summary_totals['loan_records_found'] ?? $report->loanRecords->whereNotNull('institution')->count(),
            ],
            'negative_credit_findings' => $this->na($report->negative_credit_findings),
            'other_remarks' => $this->na($report->other_remarks),
            'income_summaries' => $report->incomeSourceSummaries->map(fn ($row) => [
                $this->na($row->source_name), $row->stability_result, $this->na($row->key_information),
            ])->all(),
            'prepared_by' => $this->na($report->prepared_by_name),
            'noted_by' => $this->na($report->noted_by_name),
        ];
        $data['sections'] = array_values(array_filter([
            $personal->isNotEmpty() ? $this->details('I. Validated Personal Information', [
                ['Name of Client', $folder->display_name], ['Age', $personal->get('age')],
                ["Spouse's Name", $this->na($personal->get('spouse_name'))], ['Spouse Age', $personal->get('spouse_age')],
                ['Present Address', $this->na($personal->get('present_address'))], ['Length of Stay', $this->na($personal->get('length_of_stay_months'))],
                ['Residence Status', $personal->get('residence_status')], ['From', $this->na($personal->get('residence_status_from'))],
                ['Monthly Rent', $this->na($personal->get('monthly_rent'))], ['OTHER RESIDENCES (OWNED/MORTGAGED)', $this->na($personal->get('other_residences'))],
                ['Home Condition', $personal->get('home_condition')], ['Number of Storeys', $personal->get('number_of_storeys')],
                ['Material Cost', $personal->get('material_cost_level')], ['Living Condition', $personal->get('living_condition')],
                ['Previous Address', $this->na($personal->get('previous_address'))], ['Previous Address Length of Stay', $this->na($personal->get('previous_address_length_of_stay_months'))],
                ['Permanent Address', $this->na($personal->get('parents_address'))], ['Number of Dependents', $this->na($personal->get('dependents_count'))],
                ['Civil Status', $personal->get('civil_status')], ['If Separated, Year', $this->na($personal->get('separated_year'))],
                ['Reputation', $personal->get('reputation')], ['Barangay Level Findings', $personal->get('barangay_findings')],
                ['Court Background', $this->na($personal->get('court_background_status'))],
                ['Lifestyle', $personal->get('lifestyle')], ['Vehicles Owned', $this->na($personal->get('vehicles_owned'))],
                ['Validated Contact Number(s) / Email', $this->na($personal->get('contact_details'))], ['Other Remarks', $this->na($personal->get('other_remarks'))],
            ]) : null,
            $this->table('Bank / Cooperative Accounts', ['Institution', 'Branch', 'Year Opened', 'ADB Level', 'CA / SA / Share Capital', 'Remarks'], $report->bankAccounts->map(fn ($row) => [$this->na($row->institution), $this->na($row->branch), $this->na($row->year_opened), $this->na($row->adb_level), $this->na($row->capital_share_text ?: $this->amount($row->capital_share_amount)), $this->na($row->relevant_remarks)])),
            $this->table('Loan Records', ['Institution', 'Original Amount', 'Balance', 'Amortization', 'Granted / Maturity', 'Cycle / Security', 'Performance & Findings'], $report->loanRecords->map(fn ($row) => [$this->na($row->institution), $this->amount($row->original_amount), $this->amount($row->remaining_balance), $this->amount($row->amortization_amount), $this->shortDate($row->granted_date).' - '.$this->shortDate($row->maturity_date), $this->na(trim(($row->cycle_label ?: $row->cycle_number).' / '.($row->security_type ?? ''), ' /')), $this->na(trim(($row->payment_performance ?? '').' '.($row->remarks ?? '')))])),
            $this->details('Credit / Loan Summary', [['Institutions Checked', $report->summary_totals['institutions_checked'] ?? $report->creditChecks->whereNotNull('institution')->count()], ['Institutions Declared', $report->summary_totals['institutions_declared'] ?? $report->creditChecks->where('is_declared', true)->count()], ['Loan Records Found', $report->summary_totals['loan_records_found'] ?? $report->loanRecords->whereNotNull('institution')->count()]]),
            $this->table('Income Source Validation Summary', ['Source', 'Type', 'Stability', 'Validation', 'Monthly Amount', 'Key Information'], $report->incomeSourceSummaries->map(fn ($row) => [$this->na($row->source_name), $this->na($row->source_type), $this->na($row->stability_result), $this->na($row->validation_status), $this->amount($row->monthly_amount), $this->na($row->key_information)])),
            $this->narrative('Negative Credit Findings', $this->na($report->negative_credit_findings)),
            $this->narrative('Other Remarks', $this->na($report->other_remarks)),
            $this->details('Certification', [['Prepared By', $report->prepared_by_name], ['Noted By', $this->na($report->noted_by_name)], ['Purpose Remarks', $this->na($report->purpose_remarks)]]),
        ]));

        return $data;
    }

    /** @return array<string, mixed> */
    private function generalIncome(ClientFolder $folder, IncomeSource $source): array
    {
        $source->loadMissing('generalReport.declaredItems', 'template');
        abort_if($source->generalReport === null, 422, 'Save the general income source report before generating an official output.');

        $data = $this->base($folder, OfficialReportType::GeneralIncomeSource);
        $data['subtitle'] = 'To be used as guide for credit profiling, credit investigation, and credit evaluation';
        $data['source_name'] = $source->source_name;
        $data['source_revision'] = $source->revision;
        $data['template_version'] = $source->template_version;
        $data['header'] = [['Applicant Name', $source->applicant_name_snapshot], ['Branch', $source->branch_name], ['Amount Applied', $this->money($source->amount_applied)], ['Account Officer', $source->account_officer_name]];
        $data['sections'] = [
            $this->table('Declared Income Sources', ['Rank', 'Source', 'Type', 'Contribution', 'Details', 'Remarks'], $source->generalReport->declaredItems->sortBy('contribution_rank')->map(fn ($row) => [$row->contribution_rank, $row->source_name, $row->source_type, $this->money($row->amount_contribution), $row->description, $row->remarks])),
            $this->narrative('General Remarks / Details', $source->generalReport->general_remarks),
        ];

        return $data;
    }

    /** @return array<string, mixed> */
    private function business(ClientFolder $folder, IncomeSource $source): array
    {
        $source->loadMissing(['template', 'businessReport.properties.tenants', 'businessReport.branches', 'businessReport.products', 'businessReport.suppliers', 'businessReport.observations', 'businessReport.competitors']);
        $report = $source->businessReport;
        abort_if($report === null, 422, 'Save the business report before generating an official output.');

        $data = $this->base($folder, OfficialReportType::BusinessIncomeSource);
        $data['title'] = strtoupper($source->template->name);
        $data['subtitle'] = 'OFFICIAL BUSINESS / INCOME SOURCE VALIDATION REPORT';
        $data['source_name'] = $source->source_name;
        $data['source_revision'] = $source->revision;
        $data['template_version'] = $source->template_version;
        $data['header'] = [['Applicant Name', $folder->display_name], ['Business Name', $report->business_name], ['Business Category', $report->report_category], ['Main Business Address', $report->main_business_address], ['Registered Owner', $report->registered_owner], ['Relationship to Borrower', $report->relationship_to_borrower], ['Year Established', $report->year_established], ['Ownership / Business Type', trim(($report->ownership_type ?? '').' / '.($report->business_type ?? ''), ' /')], ['Scale', $report->scale], ['Informant', $report->informant]];
        $sections = [
            $this->details('Business History', [['Previous Business Address', $report->previous_business_address], ['Reason for Transfer', $report->reason_for_transfer], ['Length of Stay', $report->length_of_stay_months ? $report->length_of_stay_months.' months' : null], ['Monthly Rent', $this->money($report->monthly_rent)]]),
            $this->table('Properties', ['Type', 'Location', 'Declared', 'Inspected', 'Units / Tenants', 'Area', 'Remarks'], $report->properties->map(fn ($row) => [$row->property_type, $row->location, $row->is_declared ? 'Yes' : 'No', $row->is_inspected ? 'Yes' : 'No', trim(($row->units_available ?? '').' / '.($row->units_with_tenants ?? ''), ' /'), $row->area_square_meters, $row->remarks ?: $row->reason_not_inspected])),
            $this->table('Tenants', ['Tenant', 'Property', 'Monthly Rent', 'Years Renting', 'Contract', 'Contact / Remarks'], $report->properties->flatMap(fn ($property) => $property->tenants->map(fn ($row) => [$row->tenant_name, $property->property_type, $this->money($row->monthly_rent), $row->years_renting, $this->yesNo($row->has_contract), trim(($row->contact_details ?? '').' '.($row->remarks ?? ''))]))),
            $this->table('Branches / Operating Locations', ['Location', 'Declared', 'Inspected', 'Hours / Shifts', 'Sales / Inventory', 'Rent / Years', 'Observations'], $report->branches->map(fn ($row) => [$row->location, $row->is_declared ? 'Yes' : 'No', $row->is_inspected ? 'Yes' : 'No', trim(($row->operating_days_hours ?? '').' / '.($row->shifts_count ?? ''), ' /'), trim($this->money($row->average_sales_per_shift).' / '.($row->inventory_level ?? ''), ' /'), trim($this->money($row->monthly_rent).' / '.($row->years_in_area ?? ''), ' /'), $row->nearby_brands ?: $row->reason_not_inspected])),
            $this->table('Products', ['Product', 'Unit / Size', 'Selling Price', 'Stock Level', 'Top Seller'], $report->products->map(fn ($row) => [$row->product_name, $row->unit_size, $this->money($row->selling_price), $row->stock_level, $row->is_top_seller ? 'Yes' : 'No'])),
            $this->table('Suppliers', ['Supplier', 'Office', 'Contact', 'Confirmed', 'Years', 'Performance / Remarks'], $report->suppliers->map(fn ($row) => [$row->supplier_name, $row->office_location, $row->contact_information, $this->yesNo($row->is_confirmed), $row->years_transacting, trim(($row->payment_performance ?? '').' '.($row->remarks ?? ''))])),
            $this->table('Business Observations', ['Question / Observation', 'Answer', 'Remarks'], $report->observations->map(fn ($row) => [$row->question_snapshot, $row->answer, $row->remarks])),
            $this->table('Nearby Competitors', ['Name', 'Location', 'Notes'], $report->competitors->map(fn ($row) => [$row->name, $row->location, $row->notes])),
            $this->narrative('General Report Remarks', $report->report_remarks),
        ];
        $tags = $source->template->compatibility_tags ?? [];
        $allowed = array_merge(['Business History', 'General Report Remarks'], collect($tags)->map(fn ($tag) => match ($tag) {
            'properties' => 'Properties', 'tenants' => 'Tenants', 'branches' => 'Branches / Operating Locations', 'products' => 'Products', 'suppliers' => 'Suppliers', 'observations' => 'Business Observations', 'competitors' => 'Nearby Competitors', default => null
        })->filter()->all());
        $data['sections'] = array_values(array_filter($sections, fn ($section) => $section !== null && in_array($section['title'], $allowed, true) && ($section['kind'] !== 'table' || $section['rows'] !== [])));

        return $data;
    }

    /** @return array<string, mixed> */
    private function residenceBusiness(ClientFolder $folder): array
    {
        $report = $folder->residenceBusinessReport()->with(['investigator:id,full_name', 'sections' => fn ($query) => $query->orderBy('sort_order')->orderBy('id'), 'sections.incomeSource:id,source_name,business_name', 'sections.mediaItems' => fn ($query) => $query->orderBy('sort_order')->orderBy('id'), 'sections.mediaItems.mediaReference'])->first();
        abort_if($report === null, 422, 'Save the Residence & Business report before generating an official output.');

        $data = $this->base($folder, OfficialReportType::ResidenceBusinessPhoto);
        $data['source_revision'] = $report->revision;
        $data['header'] = [['Applicant Name', $folder->display_name], ['Report Date', $this->date($report->report_date)], ['CI', $report->investigator?->full_name], ['Default Location', $report->default_location]];
        $data['photo_sections'] = $report->sections->map(function ($section) use ($folder, $report): array {
            return [
                'category' => $this->human($section->category->value),
                'subject' => $section->subject_party === 'applicant' ? $folder->display_name : $section->subject_name,
                'heading' => $section->heading_subject ?: $report->default_subject,
                'location' => $section->location ?: $report->default_location,
                'business_name' => $section->business_name,
                'income_source' => $section->incomeSource?->source_name,
                'map' => $section->google_maps_link,
                'remarks' => $section->remarks,
                'media' => $section->mediaItems->map(fn ($item) => [
                    'label' => $item->output_label ?: $item->mediaReference->label ?: $item->mediaReference->file_name,
                    'caption' => $item->caption,
                    'file_name' => $item->mediaReference->file_name,
                    'media_type' => $item->mediaReference->media_type->value,
                    'image_path' => $this->safeMediaPath($item->mediaReference->thumbnail_path ?: $item->mediaReference->temporary_local_path),
                ])->all(),
            ];
        })->all();
        $data['sections'] = array_values(array_filter([$this->narrative('Residence Findings / Remarks', $report->residence_remarks), $this->narrative('Business Findings / Remarks', $report->business_remarks)]));

        return $data;
    }

    private function safeMediaPath(?string $path): ?string
    {
        if (! filled($path) || str_contains($path, '..') || preg_match('/^[a-z]+:\/\//i', $path)) {
            return null;
        }
        $absolute = storage_path('app/private/'.ltrim(str_replace('\\', '/', $path), '/'));

        return is_file($absolute) ? $absolute : null;
    }

    /** @return array{kind: string, title: string, columns: array<int, string>, rows: array<int, array<int, mixed>>} */
    private function table(string $title, array $columns, Collection $rows): array
    {
        return ['kind' => 'table', 'title' => $title, 'columns' => $columns, 'rows' => $rows->values()->map(fn ($row) => array_map(fn ($value) => $this->display($value), $row))->all()];
    }

    /** @return array{kind: string, title: string, rows: array<int, array<int, string>>} */
    private function details(string $title, array $rows): array
    {
        return ['kind' => 'details', 'title' => $title, 'rows' => collect($rows)->filter(fn ($row) => filled($row[1]))->map(fn ($row) => [$row[0], $this->display($row[1])])->values()->all()];
    }

    /** @return array{kind: string, title: string, text: string}|null */
    private function narrative(string $title, mixed $text): ?array
    {
        return filled($text) ? ['kind' => 'narrative', 'title' => $title, 'text' => $this->display($text)] : null;
    }

    private function display(mixed $value): string
    {
        return filled($value) ? (string) $value : '—';
    }

    private function human(?string $value): string
    {
        return filled($value) ? str($value)->replace('_', ' ')->title()->toString() : '—';
    }

    private function date(mixed $value): string
    {
        return $value ? $value->format('F j, Y') : '—';
    }

    private function money(mixed $value): string
    {
        return filled($value) ? 'PHP '.number_format((float) $value, 2) : '—';
    }

    private function yesNo(mixed $value): string
    {
        return $value === null ? '—' : ($value ? 'Yes' : 'No');
    }

    private function na(mixed $value): string
    {
        return filled($value) ? (string) $value : 'N/A';
    }

    private function shortDate(mixed $value): string
    {
        return $value ? $value->format('n/j/Y') : 'N/A';
    }

    private function amount(mixed $value): string
    {
        return filled($value) ? number_format((float) $value, 2) : 'N/A';
    }
}
