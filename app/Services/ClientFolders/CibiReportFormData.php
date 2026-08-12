<?php

namespace App\Services\ClientFolders;

use App\Enums\PartyType;
use App\Models\ClientFolder;

class CibiReportFormData
{
    public function for(ClientFolder $clientFolder): array
    {
        // Reload the report relation because the folder overview intentionally selects
        // only state/timestamp columns for its lightweight module summary.
        $clientFolder->load([
            'assignedInvestigator:id,full_name',
            'information',
            'addresses',
            'incomeSources:id,client_folder_id,source_name',
            'cibiReport.investigator:id,full_name',
            'cibiReport.bankAccounts',
            'cibiReport.loanRecords',
            'cibiReport.creditChecks',
            'cibiReport.incomeSourceSummaries',
            'cibiReport.legalFindings',
        ]);

        $personalSnapshot = array_replace(
            $this->personalDefaults($clientFolder),
            $clientFolder->cibiReport?->personal_snapshot ?? [],
            ['name' => $clientFolder->display_name],
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

        return [
            'report' => $clientFolder->cibiReport,
            'personalSnapshot' => $personalSnapshot,
            'summaryTotals' => $summaryTotals,
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

    private function personalDefaults(ClientFolder $clientFolder): array
    {
        $information = $clientFolder->information;
        $addresses = $clientFolder->addresses->keyBy(fn ($address): string => $address->address_type->value);
        $formatAddress = function ($address): ?string {
            $value = $address
                ? collect([$address->address_line_1, $address->address_line_2, $address->barangay, $address->city_municipality, $address->province, $address->postal_code])->filter()->implode(', ')
                : null;

            return filled($value) ? $value : null;
        };

        return [
            'name' => $clientFolder->display_name,
            'age' => $information?->birth_date?->age,
            'spouse_name' => $information?->spouse_name,
            'spouse_age' => null,
            'present_address' => $formatAddress($addresses->get('present')),
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
}
