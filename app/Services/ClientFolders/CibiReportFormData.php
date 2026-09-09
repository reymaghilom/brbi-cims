<?php

namespace App\Services\ClientFolders;

use App\Enums\PartyType;
use App\Models\ClientFolder;
use App\Models\CoMaker;

class CibiReportFormData
{
    public function __construct(
        private readonly ClientNameFormatter $names,
        private readonly BankInstitutionPrefill $bankInstitutionPrefill,
    ) {}

    public function for(ClientFolder $clientFolder, ?CoMaker $activePerson = null): array
    {
        // Reload the report relation because the folder overview intentionally selects
        // only state/timestamp columns for its lightweight module summary. cibiReport and
        // incomeSources are constrained to the active person — a Co-Maker's CI/BI form must
        // never load the Applicant's saved report (or vice versa, or another Co-Maker's).
        $clientFolder->load([
            'information',
            'addresses',
            'incomeSources' => fn ($query) => $query->select(['id', 'client_folder_id', 'co_maker_id', 'source_name'])->where('co_maker_id', $activePerson?->id),
            'cibiReport' => fn ($query) => $query->where('co_maker_id', $activePerson?->id),
            'cibiReport.investigator:id,full_name',
            'cibiReport.creator:id,full_name',
            'cibiReport.bankAccounts',
            // IV. replays each Bank/Coop's loan rows in the order they were saved. sort_order is the
            // submitted row order, and id order can drift from it once rows are added or removed
            // across saves — so ordering explicitly (as CibiExcelExporter already does) is what
            // guarantees Loan 1/2/3 come back in the same order they went in.
            'cibiReport.loanRecords' => fn ($query) => $query->orderBy('sort_order')->orderBy('id'),
            'cibiReport.creditChecks',
            'cibiReport.incomeSourceSummaries',
            'cibiReport.legalFindings',
        ]);

        // 'name' is intentionally NOT force-overwritten here once a CIBI Report is already saved —
        // the CI can manually edit "Name of Client/Co-Maker" inside the CIBI form itself, and that
        // edit must only ever affect this report's own personal_snapshot, never the Client Folder's
        // display_name or the Co-Maker's master record. A still-unsaved report has no personal_snapshot
        // yet, so personalDefaults()'s own 'name' (the current Applicant/Co-Maker official name) is
        // the only source — exactly the desired initial prefill.
        $personalSnapshot = array_replace(
            $this->personalDefaults($clientFolder, $activePerson),
            $clientFolder->cibiReport?->personal_snapshot ?? [],
        );
        foreach (['spouse_name', 'present_address', 'residence_status_from', 'monthly_rent', 'length_of_stay_months', 'other_residences', 'previous_address', 'parents_address', 'previous_address_length_of_stay_months', 'separated_year', 'vehicles_owned', 'contact_details', 'other_remarks'] as $field) {
            if (strcasecmp(trim((string) ($personalSnapshot[$field] ?? '')), 'N/A') === 0) {
                $personalSnapshot[$field] = null;
            }
        }
        $summaryTotals = $clientFolder->cibiReport?->summary_totals ?? [
            'institutions_checked' => $clientFolder->cibiReport?->creditChecks->whereNotNull('institution')->count() ?? 0,
            'institutions_declared' => $clientFolder->cibiReport?->creditChecks->where('is_declared', true)->count() ?? 0,
            'loan_records_found' => $clientFolder->cibiReport?->loanRecords->whereNotNull('institution')->count() ?? 0,
        ];
        $defaultStartDate = $clientFolder->cibiReport?->start_date?->format('Y-m-d');
        if (! $clientFolder->cibiReport && blank($defaultStartDate)) {
            // CIBI has not been saved for this exact person yet — the exact person's own most
            // recent Residence Check CI Date may prefill the still-unsaved CIBI form. Prefill
            // before save only; once CIBI is saved, its own start_date above always wins.
            $defaultStartDate = $clientFolder->residenceChecks()
                ->where('co_maker_id', $activePerson?->id)
                ->latest('id')
                ->first(['ci_date'])?->ci_date?->format('Y-m-d');
        }

        return [
            'report' => $clientFolder->cibiReport,
            'defaultStartDate' => $defaultStartDate,
            'personalSnapshot' => $personalSnapshot,
            'summaryTotals' => $summaryTotals,
            'bankAccountPrefillRows' => $this->bankInstitutionPrefill->cibiBankAccountsFromTargets(
                $clientFolder,
                $activePerson,
                $clientFolder->cibiReport?->bankAccounts ?? [],
            ),
            'loanRecordPrefillRows' => $this->bankInstitutionPrefill->cibiLoanRecordsFromTargets(
                $clientFolder,
                $activePerson,
                $clientFolder->cibiReport?->loanRecords ?? [],
            ),
            'partyTypes' => PartyType::cases(),
            'addresses' => $clientFolder->addresses->keyBy(fn ($address): string => $address->address_type->value),
            'purposeOptions' => [
                'working_capital' => 'Working Capital / Inventory / Receivables',
                'buyout_debt_consolidation' => 'Buyout / Debt Consolidation',
                'building_construction_home_renovation' => 'Building Construction / Home Renovation',
                'personal' => 'Personal: Medical / Education / Travel / Estate Management',
                'business_expansion' => 'Business Expansion / Renovation / Start-up Inventory',
                'chattel_property_acquisition' => 'Chattel Property Acquisition',
                'real_estate_property_acquisition' => 'Real Estate Property Acquisition',
                'others' => 'Others',
            ],
        ];
    }

    /**
     * The exact person's most recent Residence Check Location — a prefill source for a still-unsaved
     * CI/BI Present Address only, mirroring PersonAddressResolver's own CIBI-to-Residence direction
     * in reverse. Callers only invoke this once a saved CIBI Report is already confirmed absent.
     */
    private function residenceLocationFallback(ClientFolder $clientFolder, ?CoMaker $activePerson): ?string
    {
        return $clientFolder->residenceChecks()
            ->where('co_maker_id', $activePerson?->id)
            ->latest('id')
            ->value('location');
    }

    private function personalDefaults(ClientFolder $clientFolder, ?CoMaker $activePerson): array
    {
        // A Co-Maker only has the handful of fields captured on the Co-Maker record itself —
        // there is no ClientInformation/ClientAddress profile to draw richer defaults from, so
        // the rest of the personal snapshot simply starts blank for manual encoding, same as it
        // would for the Applicant before their own profile was ever filled in.
        if ($activePerson) {
            $presentAddress = $activePerson->address;
            if (! $clientFolder->cibiReport && blank($presentAddress)) {
                $presentAddress = $this->residenceLocationFallback($clientFolder, $activePerson);
            }

            return [
                'name' => $this->officialName($clientFolder, $activePerson),
                'age' => null, 'spouse_name' => null, 'spouse_age' => null,
                'present_address' => $presentAddress,
                'length_of_stay_months' => null, 'residence_status' => null, 'residence_status_from' => null,
                'monthly_rent' => null, 'living_with_parents' => false, 'other_residences' => null,
                'home_condition' => null, 'number_of_storeys' => null, 'material_cost_level' => null,
                'living_condition' => null, 'previous_address' => null, 'previous_address_length_of_stay_months' => null,
                'parents_address' => null, 'dependents_count' => null, 'civil_status' => null, 'separated_year' => null,
                'reputation' => null, 'barangay_findings' => null, 'court_background_status' => null, 'court_background' => null,
                'lifestyle' => null, 'vehicles_owned' => null,
                'contact_details' => $activePerson->contact_number,
                'other_remarks' => null,
            ];
        }

        $information = $clientFolder->information;
        $addresses = $clientFolder->addresses->keyBy(fn ($address): string => $address->address_type->value);
        $formatAddress = function ($address): ?string {
            $value = $address
                ? collect([$address->address_line_1, $address->address_line_2, $address->barangay, $address->city_municipality, $address->province, $address->postal_code])->filter()->implode(', ')
                : null;

            return filled($value) ? $value : null;
        };

        $presentAddress = $formatAddress($addresses->get('present'));
        if (! $clientFolder->cibiReport && blank($presentAddress)) {
            $presentAddress = $this->residenceLocationFallback($clientFolder, null);
        }

        return [
            'name' => $clientFolder->display_name,
            'age' => $information?->birth_date?->age,
            'spouse_name' => $information?->spouse_name,
            'spouse_age' => null,
            'present_address' => $presentAddress,
            'length_of_stay_months' => $information?->length_of_stay_months,
            'residence_status' => $information?->home_ownership,
            'residence_status_from' => null,
            'monthly_rent' => null,
            'living_with_parents' => false,
            'other_residences' => $information?->other_residences,
            'home_condition' => $information?->home_condition,
            'number_of_storeys' => null,
            'material_cost_level' => $information?->material_cost_level,
            'living_condition' => $information?->living_condition,
            'previous_address' => $formatAddress($addresses->get('previous')),
            'previous_address_length_of_stay_months' => $addresses->get('previous')?->length_of_stay_months,
            'parents_address' => $formatAddress($addresses->get('parents')),
            'dependents_count' => $information?->dependents_count,
            'civil_status' => $information?->civil_status,
            'separated_year' => null,
            'reputation' => $information?->reputation,
            'barangay_findings' => $information?->barangay_findings,
            'court_background_status' => null,
            'court_background' => $information?->court_background_summary,
            'lifestyle' => $information?->lifestyle,
            'vehicles_owned' => $information?->vehicles_owned,
            'contact_details' => collect([$information?->contact_number, $information?->email])->filter()->implode(' / ') ?: null,
            'other_remarks' => $information?->other_remarks,
        ];
    }

    /**
     * The Applicant's stored display_name is already composed "Last, First Middle [Suffix]" via
     * ClientNameFormatter at folder creation/rename time. A Co-Maker's own stored full_name is a
     * plain "First Middle Last [Suffix]" string used everywhere else (tabs, menus, audit trail) —
     * left untouched here — so the CI/BI report's own "Name of Co-Maker" field is composed fresh
     * from the Co-Maker's structured name parts with the same shared formatter instead.
     */
    private function officialName(ClientFolder $clientFolder, ?CoMaker $activePerson): string
    {
        if (! $activePerson) {
            return $clientFolder->display_name;
        }

        // Structured name parts are backfilled for every Co-Maker saved through the normal
        // Add/Edit flow, but a record created another way (a factory, direct seeding) could still
        // only have full_name set — fall back to it rather than risk a formatting failure on
        // fields that were never captured.
        if (blank($activePerson->last_name) || blank($activePerson->first_name)) {
            return $activePerson->full_name;
        }

        return $this->names->format($activePerson->last_name, $activePerson->first_name, $activePerson->middle_name, $activePerson->suffix);
    }
}
