<?php

namespace App\Services\Reports;

use App\Enums\OfficialReportType;
use App\Enums\RecordState;
use App\Models\BusinessCheck;
use App\Models\BusinessReport;
use App\Models\ClientFolder;
use App\Models\CoMaker;
use App\Models\IncomeSource;
use App\Models\IncomeSourceTemplate;
use App\Models\ResidenceCheck;
use App\Services\ClientFolders\CiParticipantService;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class OfficialReportDataBuilder
{
    public function __construct(private readonly CiParticipantService $participants) {}

    /** @return array<string, mixed> */
    public function build(ClientFolder $folder, OfficialReportType $type, ?IncomeSource $source = null, ?CoMaker $activePerson = null): array
    {
        if ($type->requiresIncomeSource()) {
            $this->validateSource($folder, $type, $source);
        }

        $folder->loadMissing('assignedInvestigator:id,full_name');

        return match ($type) {
            OfficialReportType::Cibi => $this->cibi($folder, $activePerson),
            OfficialReportType::BusinessIncomeSource => $this->business($folder, $source),
            OfficialReportType::GeneralIncomeSource => $this->generalIncome($folder, $source),
            OfficialReportType::ResidenceBusinessPhoto => $this->residenceBusiness($folder, $activePerson),
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
    private function base(ClientFolder $folder, OfficialReportType $type, ?string $personName = null): array
    {
        return [
            'type' => $type->value,
            'title' => strtoupper($type->label()),
            'subtitle' => 'BRBI Credit Investigation Management System',
            'folder_number' => $folder->folder_number,
            'client_name' => $personName ?? $folder->display_name,
            'assigned_ci' => $folder->assignedInvestigator?->full_name,
            'generated_display_at' => now()->timezone(config('cims.display_timezone'))->format('F j, Y g:i A'),
            'paper' => ['width' => 8.5, 'height' => 13.0, 'margins' => ['top' => 0.45, 'right' => 0.45, 'bottom' => 0.45, 'left' => 0.45]],
            'header' => [],
            'sections' => [],
            'photo_sections' => [],
        ];
    }

    /** @return array<string, mixed> */
    private function cibi(ClientFolder $folder, ?CoMaker $activePerson = null): array
    {
        $report = $folder->cibiReport()->where('co_maker_id', $activePerson?->id)->with(['investigator:id,full_name', 'bankAccounts', 'loanRecords', 'creditChecks', 'incomeSourceSummaries', 'legalFindings'])->first();
        abort_if($report === null, 422, 'Save the CI / BI report before generating an official output.');
        abort_unless($report->state === RecordState::Complete, 422, 'Complete the CI / BI report before generating an official output.');
        $personName = $activePerson?->full_name ?? $folder->display_name;

        $data = $this->base($folder, OfficialReportType::Cibi, $personName);
        $data['subtitle'] = 'CREDIT INVESTIGATION REPORT - INDIVIDUAL ACCOUNT';
        $data['source_revision'] = $report->revision;
        $data['header'] = [
            ['Start Date of CI', $this->date($report->start_date)], ['Date Submitted', $this->date($report->submitted_date)],
            ['Applicant Name', $personName], ['Party Type', $this->human($report->party_type?->value)],
            ['Branch', $report->branch_name], ['Account Officer', $report->account_officer_name],
            ['Amount Applied', $this->amount($report->amount_applied)], ['CI in Charge', $report->investigator?->full_name],
            ['CI Risk Assessment', $this->human($report->ci_risk_level)], ['Purpose', $this->na(collect($report->purpose_codes ?? [])->map(fn ($item) => $this->human($item))->implode(', '))],
        ];
        $personal = collect($report->personal_snapshot ?? [])->filter(fn ($value): bool => filled($value));
        $folder->loadMissing('coMakers');
        $data['cibi'] = [
            'ci_in_charge' => strtoupper($this->na($report->investigator?->full_name)),
            'branch' => $this->na($report->branch_name),
            'start_date' => $this->shortDate($report->start_date),
            'account_officer' => $this->na($report->account_officer_name),
            'submitted_date' => $this->shortDate($report->submitted_date),
            'amount_applied' => $this->amount($report->amount_applied),
            // Derived from the report's actual owner (co_maker_id via $activePerson), not the
            // stored party_type column — a pre-co-maker CI/BI report could have party_type
            // saved as 'co_maker' while still being the Applicant's own (co_maker_id null) row,
            // so trusting the column alone could show the wrong checkmark on a legacy report.
            'party_type' => $activePerson ? 'co_maker' : 'borrower',
            'name_label' => $activePerson ? 'NAME OF COMAKER:' : 'NAME OF CLIENT:',
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
            'negative_credit_findings' => (string) $report->negative_credit_findings,
            'other_remarks' => $this->na($report->other_remarks),
            'income_summaries' => $report->incomeSourceSummaries->map(fn ($row) => [
                $this->na($row->source_name), $row->stability_result, $this->na($row->key_information),
            ])->all(),
            'prepared_by' => strtoupper($this->na($report->prepared_by_name)),
            'noted_by' => $this->na($report->noted_by_name),
            // Available for consumption wherever a future template placement is added; the folder's
            // optional Co-Maker record(s) are distinct from party_type (which describes whether the
            // *applicant themself* is filed as borrower or co-maker, not a separate person). A folder
            // may have more than one Co-Maker, so every saved one is listed here.
            'co_makers' => $folder->coMakers->map(fn ($coMaker) => [
                'full_name' => $coMaker->full_name,
                'relationship_to_applicant' => $this->na($coMaker->relationship_to_applicant),
                'contact_number' => $this->na($coMaker->contact_number),
                'address' => $this->na($coMaker->address),
            ])->all(),
        ];
        $data['sections'] = array_values(array_filter([
            $personal->isNotEmpty() ? $this->details('I. Validated Personal Information', [
                ['Name of Client', $personName], ['Age', $personal->get('age')],
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

        $data = $this->base($folder, OfficialReportType::GeneralIncomeSource, $source->applicant_name_snapshot);
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
        $cibiReport = $folder->cibiReport()->where('co_maker_id', $source->co_maker_id)->first();
        $personName = $source->applicant_name_snapshot ?: $folder->display_name;
        // Authoritative CI In-Charge for the official Business Report: the exact IncomeSource's
        // saved primary creator plus companions, in saved order — never the folder's own
        // (unrelated) assigned_ci_id, and never Business Check's separate participant list.
        $ciInCharge = $this->participants->fullNames($source);

        $data = $this->base($folder, OfficialReportType::BusinessIncomeSource, $personName);
        $data['title'] = strtoupper($source->template->name);
        $data['subtitle'] = 'OFFICIAL BUSINESS / INCOME SOURCE VALIDATION REPORT';
        $data['source_name'] = $source->source_name;
        $data['source_revision'] = $source->revision;
        $data['template_version'] = $source->template_version;
        $data['header'] = [
            ['CI in Charge', strtoupper($ciInCharge)], ['Branch', $source->branch_name ?: $cibiReport?->branch_name],
            ['Start Date of CI', $this->date($report->start_date)], [$source->co_maker_id ? 'Co-Maker Name' : 'Applicant Name', $personName],
            ['Date Submitted to CA', $this->date($report->submitted_date)], ['Account Officer', $source->account_officer_name ?: $cibiReport?->account_officer_name],
            // Derived from the business report's own owner (co_maker_id), not the linked CI/BI
            // report's stored party_type column — see the matching note in cibi() above.
            ['Party Type', $this->human($source->co_maker_id ? 'co_maker' : 'borrower')], ['Amount Applied', $this->amount($cibiReport?->amount_applied)],
            ['Business Name', $report->business_name], ['Business Category', $report->report_category], ['Main Business Address', $report->main_business_address], ['Registered Owner', $report->registered_owner], ['Relationship to Borrower', $report->relationship_to_borrower], ['Year Established', $report->year_established], ['Ownership / Business Type', trim(($report->ownership_type ?? '').' / '.($report->business_type ?? ''), ' /')], ['Scale', $report->scale], ['Informant', $report->informant],
        ];
        $data['business'] = [
            'template_type' => $source->template_type,
            'section_title' => strtoupper($source->template->name),
            'ci_in_charge' => strtoupper($this->na($ciInCharge)),
            'branch' => $this->na($source->branch_name ?: $cibiReport?->branch_name),
            'start_date' => $this->shortDate($report->start_date),
            'applicant_name' => $personName,
            // Drives the "NAME OF APPLICANT:" / "NAME OF CO-MAKER:" label printed across every
            // dedicated business-report partial — same ownership derivation as party_type below.
            'name_label' => $source->co_maker_id ? 'NAME OF CO-MAKER' : 'NAME OF APPLICANT',
            'submitted_date' => $this->shortDate($report->submitted_date),
            'account_officer' => $this->na($source->account_officer_name ?: $cibiReport?->account_officer_name),
            // Derived from the business report's own owner (co_maker_id), not the linked CI/BI
            // report's stored party_type column — see the matching note in cibi() above.
            'party_type' => $source->co_maker_id ? 'co_maker' : 'borrower',
            'amount_applied' => $this->amount($cibiReport?->amount_applied),
            'business_name' => $report->business_name,
            'main_business_address' => $report->main_business_address,
            'year_established' => $report->year_established,
            'length_of_stay_months' => $report->length_of_stay_months,
            'ownership_type' => $report->ownership_type,
            'rented_from' => $report->rented_from,
            'monthly_rent' => $report->monthly_rent,
            'previous_business_address' => $report->previous_business_address,
            'previous_business_address_length_of_stay' => $report->previous_business_address_length_of_stay,
            'reason_for_transfer' => $report->reason_for_transfer,
            'informant' => $report->informant,
            'registered_owner' => $report->registered_owner,
            'relationship_to_borrower' => $report->relationship_to_borrower,
            'scale' => $report->scale,
            'business_type' => $report->business_type,
            'properties_declared' => $report->properties_declared,
            'properties_inspected' => $report->properties_inspected,
            'branches_declared' => $report->branches_declared,
            'branches_inspected' => $report->branches_inspected,
            'branches_not_inspected' => $report->branches_not_inspected,
            'branches_reason_not_inspected' => $report->branches_reason_not_inspected,
            'report_remarks' => $report->report_remarks,
            'template_data' => (array) $report->template_data,
            'schema' => $source->template->businessReportSchema(),
            'properties' => $report->properties->sortBy('sort_order')->values()->map(fn ($row) => [
                'property_type' => $row->property_type, 'is_inspected' => $row->is_inspected, 'units_available' => $row->units_available,
                'units_with_tenants' => $row->units_with_tenants, 'location' => $row->location, 'area_square_meters' => $row->area_square_meters,
                'has_contract' => $row->has_contract,
                'tenant_information' => filled($row->remarks) ? $row->remarks : $row->tenants->map(fn ($tenant) => collect([
                    $tenant->tenant_name, filled($tenant->monthly_rent) ? 'PHP '.$tenant->monthly_rent : null, filled($tenant->years_renting) ? $tenant->years_renting.' years' : null,
                ])->filter()->implode(' / '))->filter()->implode('; '),
            ])->all(),
            'branches' => $report->branches->sortBy('sort_order')->values()->map(fn ($row) => [
                'location' => $row->location, 'frontage_meters' => $row->frontage_meters, 'total_area_square_meters' => $row->total_area_square_meters,
                'is_air_conditioned' => $row->is_air_conditioned, 'operating_days_hours' => $row->operating_days_hours, 'shifts_count' => $row->shifts_count,
                'employees_per_shift' => $row->employees_per_shift, 'average_sales_per_shift' => $row->average_sales_per_shift, 'inventory_level' => $row->inventory_level,
                'monthly_rent' => $row->monthly_rent, 'years_in_area' => $row->years_in_area, 'nearby_brands' => $row->nearby_brands,
            ])->all(),
            'products' => $report->products->sortBy('sort_order')->values()->map(fn ($row) => ['product_name' => $row->product_name, 'selling_price' => $row->selling_price])->all(),
            'observations' => $report->observations->sortBy('sort_order')->values()->map(fn ($row) => ['answer' => $row->answer])->all(),
            'suppliers' => $report->suppliers->sortBy('sort_order')->values()->map(fn ($row) => [
                'supplier_name' => $row->supplier_name, 'office_location' => $row->office_location, 'is_confirmed' => $row->is_confirmed,
                'remarks' => trim(($row->payment_performance ?? '').' '.($row->contact_information ?? '').' '.($row->years_transacting ? $row->years_transacting.' yrs' : '').' '.($row->remarks ?? '')),
            ])->all(),
        ];
        $sections = [
            $this->details('Business History', [['Previous Business Address', $report->previous_business_address], ['Length of Stay', $report->previous_business_address_length_of_stay], ['Reason for Transfer', $report->reason_for_transfer], ['Monthly Rent', $this->na($report->monthly_rent)]]),
            $this->table('Properties', ['Type', 'Location', 'Declared', 'Inspected', 'Units / Tenants', 'Area', 'Remarks'], $report->properties->map(fn ($row) => [$row->property_type, $row->location, $row->is_declared ? 'Yes' : 'No', $row->is_inspected ? 'Yes' : 'No', trim(($row->units_available ?? '').' / '.($row->units_with_tenants ?? ''), ' /'), $row->area_square_meters, $row->remarks ?: $row->reason_not_inspected])),
            $this->table('Tenants', ['Tenant', 'Property', 'Monthly Rent', 'Years Renting', 'Contract', 'Contact / Remarks'], $report->properties->flatMap(fn ($property) => $property->tenants->map(fn ($row) => [$row->tenant_name, $property->property_type, $this->money($row->monthly_rent), $row->years_renting, $this->yesNo($row->has_contract), trim(($row->contact_details ?? '').' '.($row->remarks ?? ''))]))),
            $this->details('Branch Inspection Summary', [['Total Branches Declared', $report->branches_declared], ['Total Branches Inspected', $report->branches_inspected], ['Branches Not Inspected', $report->branches_not_inspected], ['Reason Not Inspected', $report->branches_reason_not_inspected]]),
            $this->table('Branches / Operating Locations', ['Location', 'Declared', 'Inspected', 'Hours / Shifts', 'Sales / Inventory', 'Rent / Years', 'Observations'], $report->branches->map(fn ($row) => [$row->location, $row->is_declared ? 'Yes' : 'No', $row->is_inspected ? 'Yes' : 'No', trim(($row->operating_days_hours ?? '').' / '.($row->shifts_count ?? ''), ' /'), trim($this->money($row->average_sales_per_shift).' / '.($row->inventory_level ?? ''), ' /'), trim($this->money($row->monthly_rent).' / '.($row->years_in_area ?? ''), ' /'), $row->nearby_brands ?: $row->reason_not_inspected])),
            $this->table('Products', ['Product', 'Unit / Size', 'Selling Price', 'Stock Level', 'Top Seller'], $report->products->map(fn ($row) => [$row->product_name, $row->unit_size, $this->money($row->selling_price), $row->stock_level, $row->is_top_seller ? 'Yes' : 'No'])),
            $this->table('Suppliers', ['Supplier', 'Office', 'Contact', 'Confirmed', 'Years', 'Performance / Remarks'], $report->suppliers->map(fn ($row) => [$row->supplier_name, $row->office_location, $row->contact_information, $this->yesNo($row->is_confirmed), $row->years_transacting, trim(($row->payment_performance ?? '').' '.($row->remarks ?? ''))])),
            $this->table('Business Observations', ['Question / Observation', 'Answer', 'Remarks'], $report->observations->map(fn ($row) => [$row->question_snapshot, $row->answer, $row->remarks])),
            $this->table('Nearby Competitors', ['Name', 'Location', 'Notes'], $report->competitors->map(fn ($row) => [$row->name, $row->location, $row->notes])),
            $this->narrative('General Report Remarks', $report->report_remarks),
        ];
        $tags = $source->template->compatibility_tags ?? [];
        $allowed = array_merge(['Business History', 'General Report Remarks'], collect($tags)->flatMap(fn ($tag) => match ($tag) {
            'properties' => ['Properties'], 'tenants' => ['Tenants'], 'branches' => ['Branches / Operating Locations', 'Branch Inspection Summary'], 'products' => ['Products'], 'suppliers' => ['Suppliers'], 'observations' => ['Business Observations'], 'competitors' => ['Nearby Competitors'], default => []
        })->all());
        $sections = array_values(array_filter($sections, fn ($section) => $section !== null && in_array($section['title'], $allowed, true) && ($section['kind'] !== 'table' || $section['rows'] !== [])));
        array_splice($sections, 1, 0, $this->templateSchemaSections($source->template, $report));
        $data['sections'] = $sections;

        return $data;
    }

    /** @return array<int, array<string, mixed>> */
    private function templateSchemaSections(IncomeSourceTemplate $template, BusinessReport $report): array
    {
        $schema = $template->businessReportSchema();
        if (empty($schema)) {
            return [];
        }
        $saved = (array) $report->template_data;
        $sections = [];

        $fields = collect($schema['fields'] ?? []);
        if ($fields->isNotEmpty()) {
            $rows = $fields->map(function (array $field) use ($saved) {
                $value = data_get($saved, "fields.{$field['key']}");
                $display = match ($field['type'] ?? 'text') {
                    'checkbox' => $value ? 'Yes' : 'No',
                    'array' => is_array($value) ? implode(', ', $value) : $value,
                    default => $value,
                };

                return [$field['label'], $display];
            })->all();
            $sections[] = $this->details('Template Details', $rows);
        }

        foreach (($schema['tables'] ?? []) as $table) {
            $columns = collect($table['columns'])->pluck('label')->all();
            $rows = collect(data_get($saved, "tables.{$table['key']}", []))
                ->map(fn ($row) => collect($table['columns'])->map(fn ($column) => data_get($row, $column['key']))->all());
            $sections[] = $this->table($table['title'], $columns, $rows);
        }

        if (! empty($schema['questions'])) {
            $rows = collect($schema['questions'])->map(fn ($question, $index) => [$index + 1, $question, data_get($saved, "questions.{$index}")]);
            $sections[] = $this->table('Validation Questions / Findings', ['#', 'Question', 'Answer'], $rows);
        }

        return array_values(array_filter($sections, fn ($section) => $section['kind'] !== 'table' || $section['rows'] !== []));
    }

    /** @return array<string, mixed> */
    private function residenceBusiness(ClientFolder $folder, ?CoMaker $activePerson = null): array
    {
        $personId = $activePerson?->id;
        $residenceChecks = $folder->residenceChecks()->where('co_maker_id', $personId)->with(['photos', 'investigator:id,full_name'])->orderBy('ci_date')->orderBy('id')->get();
        $businessChecks = $folder->businessChecks()->where('co_maker_id', $personId)->with(['photos', 'photoGroups.photos', 'incomeSource:id,source_name,business_name,income_source_template_id', 'incomeSource.template:id,template_type'])->orderBy('ci_date')->orderBy('id')->get();
        abort_if($residenceChecks->isEmpty() && $businessChecks->isEmpty(), 422, 'Save at least one Residence Check or Business Check before generating an official output.');
        $personName = $activePerson?->full_name ?? $folder->display_name;

        $data = $this->base($folder, OfficialReportType::ResidenceBusinessPhoto, $personName);
        $data['header'] = [['Applicant / Co-Maker', $personName], ['Residence Checks', (string) $residenceChecks->count()], ['Business Checks', (string) $businessChecks->count()]];
        $data['photo_sections'] = $residenceChecks->map(fn ($check) => $this->residenceCheckSection($check, $personName))
            ->concat($businessChecks->map(fn ($check) => $this->businessCheckSection($check, $personName)))
            ->all();
        $data['sections'] = [];

        return $data;
    }

    /**
     * Maps one saved Residence Check into the shared `photo_sections` element shape consumed by
     * the HTML/PDF preview and the DOCX generator. Public so batch print/export can reuse it
     * without duplicating the mapping.
     *
     * Every media item carries an `image_path` (a local absolute file path — the only thing
     * Dompdf/PhpWord can embed for PDF/DOCX directly; null for a Cloudinary-backed item until
     * ReportMediaResolver downloads it on demand, right before a PDF/DOCX render actually needs
     * it), a `web_url` (this app's own authorized photo-serving route — redirects straight to a
     * signed Cloudinary URL when the item is cloud-backed, or streams the local file otherwise; a
     * raw Windows/private-storage path or Cloudinary public_id is meaningless as an `<img src>` on
     * its own), and — only for a Cloudinary-backed item — a `cloud` descriptor ReportMediaResolver
     * needs to fetch it. Local-storage photos use the full-resolution original (falling back to the
     * 640x480 thumbnail only if the original is missing); Cloudinary photos use the already-
     * optimized stored master (see CloudinaryMediaStorage) — never a raw UUID filename as the
     * visible caption either way.
     *
     * The Google Map evidence is the saved Map Screenshot alone — never generated from
     * latitude/longitude, never a live Google API call — and returned separately as `google_map`
     * so it renders on its own dedicated page instead of mixed in with the Residence Pictures.
     */
    public function residenceCheckSection(ResidenceCheck $check, string $personName): array
    {
        $media = $check->photos->map(fn ($photo) => [
            'caption' => $photo->caption,
            'media_type' => 'photo',
            'image_path' => $photo->isCloud() ? null : $this->safeMediaPath($photo->path ?: $photo->thumbnail_path),
            'cloud' => $this->cloudDescriptor($photo->isCloud(), $photo->cloud_public_id, $photo->cloud_resource_type, $photo->cloud_delivery_type),
            'web_url' => route('client-folders.residence-checks.photo', [$check->client_folder_id, $check->id, $photo->id]),
        ])->all();

        return [
            'category' => 'Residence',
            'subject' => $personName,
            'party_label' => $check->co_maker_id ? 'Co-Maker Name' : 'Applicant Name',
            'heading' => 'Residence Check',
            'location' => $check->location,
            'business_name' => null,
            'income_source' => null,
            'map' => $check->google_maps_link,
            'google_map' => $this->googleMapEvidence($check),
            'remarks' => $check->remarks,
            'ci_date' => $this->date($check->ci_date),
            // Residence Check's own saved participant list (primary CI first, then companions in
            // saved order), first names only — e.g. "Juan / Pedro / Maria". Never derived from the
            // folder's assigned_ci_id, whoever last updated the record, or any other check's own
            // participant list.
            'ci' => $this->participants->firstNames($check),
            'media' => $media,
        ];
    }

    /**
     * Resolves this Residence Check's saved Map Screenshot (if any) into the `google_map` shape —
     * the same convention BusinessCheck's own evidence resolver returns. `web_url` is set whenever
     * the screenshot exists in the database, the same convention as every Residence Picture's own
     * `web_url` below, since the authorized route it points at does its own proper
     * (Storage-disk-aware) existence check on request rather than the raw local-filesystem check
     * `image_path` needs for Dompdf/PhpWord to embed it directly.
     *
     * @return array{image_path: ?string, cloud: ?array, web_url: ?string}|null
     */
    private function googleMapEvidence(ResidenceCheck $check): ?array
    {
        if (! $check->hasMapScreenshot()) {
            return null;
        }

        return [
            'image_path' => $check->hasCloudMapScreenshot() ? null : $this->safeMediaPath($check->map_screenshot_path ?: $check->map_screenshot_thumbnail_path),
            'cloud' => $this->cloudDescriptor($check->hasCloudMapScreenshot(), $check->map_screenshot_cloud_public_id, $check->map_screenshot_cloud_resource_type, $check->map_screenshot_cloud_delivery_type),
            'web_url' => route('client-folders.residence-checks.map-screenshot', [$check->client_folder_id, $check->id]),
        ];
    }

    /**
     * Maps one saved Business Check into the shared `photo_sections` element shape. Business
     * Photos render as `photo_groups` (one entry per saved BusinessCheckPhotoGroup, its own
     * optional caption shown once above its own photos — matching the real-world report format
     * this feature was built from), with any historical ungrouped photo (saved before Photo Groups
     * existed) appended as its own caption-less trailing group so nothing saved before this feature
     * ever disappears from the report. Competitors stay a completely separate concept — never a
     * Photo Group — with their own optional caption (Competitor Remarks) and photo list, rendered
     * after every Photo Group. Public so batch print/export can reuse it without duplicating the
     * mapping.
     */
    public function businessCheckSection(BusinessCheck $check, string $personName): array
    {
        $mapPhoto = fn ($photo) => [
            'file_name' => $photo->file_name,
            'media_type' => 'photo',
            // Cloudinary photos have no separately-stored thumbnail asset to prefer — the report
            // always embeds the already-optimized stored master (see CloudinaryMediaStorage).
            'image_path' => $photo->isCloud() ? null : $this->safeMediaPath($photo->thumbnail_path ?: $photo->path),
            'cloud' => $this->cloudDescriptor($photo->isCloud(), $photo->cloud_public_id, $photo->cloud_resource_type, $photo->cloud_delivery_type),
            // Full (never thumbnail) delivery for the Web Preview — same convention as Residence
            // Check's own photo mapping — so a Cloudinary-backed photo still renders large there
            // instead of falling through to a null local $image_path (PDF/DOCX always use
            // $image_path directly and never consult this).
            'web_url' => route('client-folders.business-checks.photo', [$check->client_folder_id, $check->id, $photo->id]),
        ];

        $groupedPhotoIds = [];
        $photoGroups = $check->photoGroups->map(function ($group) use ($mapPhoto, &$groupedPhotoIds) {
            $groupedPhotoIds = [...$groupedPhotoIds, ...$group->photos->pluck('id')->all()];

            return ['caption' => $group->caption, 'photos' => $group->photos->map($mapPhoto)->all()];
        })->all();

        $legacyPhotos = $check->photos->filter(fn ($photo) => $photo->category?->value === 'business' && ! in_array($photo->id, $groupedPhotoIds, true));
        if ($legacyPhotos->isNotEmpty()) {
            $photoGroups[] = ['caption' => null, 'photos' => $legacyPhotos->map($mapPhoto)->values()->all()];
        }

        $competitorPhotos = $check->photos->filter(fn ($photo) => $photo->category?->value === 'competitor')->map($mapPhoto)->values()->all();

        return [
            'category' => 'Business',
            'subject' => $personName,
            // Same convention as Residence Check's own party_label — a Business Check can belong to
            // either the Applicant or a specific Co-Maker's own business.
            'party_label' => $check->co_maker_id ? 'Co-Maker Name' : 'Applicant Name',
            'heading' => 'Business Check',
            'location' => $check->location,
            'ci_date' => $this->date($check->ci_date),
            'business_name' => $check->incomeSource?->displayName() ?? $check->incomeSource?->business_name,
            'income_source' => $check->incomeSource?->source_name,
            // The Business Check form's own "Google Maps Link" input was removed — historical
            // records saved before that removal may still carry a custom link, which stays
            // meaningful here; anything saved from now on falls back to Location instead of going
            // blank.
            'map' => $check->google_maps_link ?: $check->location,
            'google_map' => $this->businessMapEvidence($check),
            'remarks' => $check->remarks,
            // Business Check's own saved participant list (primary CI first, then companions in
            // saved order) — first names only, per the existing Residence Check CI convention.
            // Never derived from Business Report's participants or the folder's assigned_ci_id.
            'ci' => $this->participants->firstNames($check),
            // Every Business Photo (default/first group, then each additional Photo Group in saved
            // order) pre-chunked to at most 2 photos per "page" — one shared source of truth so
            // Web/PDF/DOCX can never disagree on where a page boundary falls. A group's caption
            // (when it has one) rides along on the same page as its own first photo(s), never
            // repeated on that same group's continuation page(s), and never left stranded alone —
            // see paginateBusinessPhotos().
            'photo_pages' => $this->paginateBusinessPhotos($photoGroups),
            'competitor_caption' => $check->competitor_remarks,
            'competitor_photos' => $competitorPhotos,
            // Same pagination as Business Photos, kept as its own list since Competitors is a
            // separate report section (after Map Screenshot, never mixed with Business Photos).
            'competitor_photo_pages' => $this->paginateBusinessPhotos([['caption' => $check->competitor_remarks, 'photos' => $competitorPhotos]]),
        ];
    }

    /**
     * Chunks each group's photos into pages of at most 2 — a group's own caption (if any) is
     * attached only to that group's first page, never repeated on its continuation pages, and a
     * group's pages always start fresh (never sharing a page with another group's photos), so a
     * caption is never left alone without at least one of its own photos on the same page.
     *
     * @param  list<array{caption: ?string, photos: list<array<string, mixed>>}>  $groups
     * @return list<array{caption: ?string, photos: list<array<string, mixed>>}>
     */
    private function paginateBusinessPhotos(array $groups): array
    {
        $pages = [];
        foreach ($groups as $group) {
            if ($group['photos'] === []) {
                continue;
            }
            foreach (array_chunk($group['photos'], 2) as $index => $chunk) {
                $pages[] = ['caption' => $index === 0 ? $group['caption'] : null, 'photos' => $chunk];
            }
        }

        return $pages;
    }

    /**
     * Resolves this Business Check's saved Map Screenshot (if any) into the same `google_map`
     * shape ResidenceCheck's own evidence resolver returns — rendered on its own dedicated page by
     * the shared photo-sections partial/DOCX builder, never mixed in with the Business Pictures,
     * and never shown as a raw filename.
     *
     * @return array{image_path: ?string, web_url: ?string}|null
     */
    private function businessMapEvidence(BusinessCheck $check): ?array
    {
        if (! $check->hasMapScreenshot()) {
            return null;
        }

        return [
            'image_path' => $check->hasCloudMapScreenshot() ? null : $this->safeMediaPath($check->map_screenshot_path ?: $check->map_screenshot_thumbnail_path),
            'cloud' => $this->cloudDescriptor($check->hasCloudMapScreenshot(), $check->map_screenshot_cloud_public_id, $check->map_screenshot_cloud_resource_type, $check->map_screenshot_cloud_delivery_type),
            'web_url' => route('client-folders.business-checks.map-screenshot', [$check->client_folder_id, $check->id]),
        ];
    }

    /** @return array{public_id: string, resource_type: ?string, delivery_type: ?string}|null */
    private function cloudDescriptor(bool $isCloud, ?string $publicId, ?string $resourceType, ?string $deliveryType): ?array
    {
        if (! $isCloud || blank($publicId)) {
            return null;
        }

        return ['public_id' => $publicId, 'resource_type' => $resourceType, 'delivery_type' => $deliveryType];
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
