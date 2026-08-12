<?php

namespace Database\Seeders;

use App\Models\ActivityDefinition;
use App\Models\CompletionRule;
use App\Models\IncomeSourceTemplate;
use App\Models\ReportTemplate;
use Illuminate\Database\Seeder;

class ReferenceDataSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            ['residence_check', 'Residence Check'], ['business_check', 'Business Check'],
            ['barangay_check', 'Barangay Check'], ['neighbor_check', 'Neighbor Check'],
            ['asset_check', 'Asset Check'], ['bank_coop_check', 'Bank / Coop Check'],
        ] as $index => [$code, $name]) {
            ActivityDefinition::updateOrCreate(['code' => $code], ['name' => $name, 'sort_order' => $index + 1, 'is_required' => true, 'is_active' => true]);
        }

        IncomeSourceTemplate::updateOrCreate(
            ['template_type' => 'business_source_validation', 'version' => 1],
            ['name' => 'Business Source of Income Validation', 'description' => 'Legacy broad template retained for existing records but unavailable for new selection.', 'compatibility_tags' => ['properties', 'tenants', 'branches', 'products', 'suppliers', 'observations', 'competitors'], 'form_handler' => 'dedicated-business', 'data_handler' => 'dedicated-business', 'preview_handler' => 'dedicated-business', 'pdf_template_key' => 'business-source-validation', 'docx_template_key' => 'business-source-validation', 'is_fallback' => false, 'is_active' => false, 'sort_order' => 50],
        );
        foreach ([
            ['leasing_non_agricultural', 'Leasing - Non-Agricultural Real Estate', 'Leasing', ['properties', 'tenants'], 1],
            ['retail_grocery_water_refilling', 'Retail / Grocery / Water Refilling', 'Retail', ['branches', 'products', 'suppliers', 'observations', 'competitors'], 2],
        ] as [$type, $name, $category, $tags, $order]) {
            IncomeSourceTemplate::updateOrCreate(
                ['template_type' => $type, 'version' => 1],
                ['name' => $name, 'description' => 'Official dedicated business validation structure.', 'business_category' => $category, 'compatibility_tags' => $tags, 'form_handler' => 'dedicated-business', 'data_handler' => 'dedicated-business', 'preview_handler' => 'dedicated-business', 'pdf_template_key' => $type, 'docx_template_key' => $type, 'is_fallback' => false, 'is_active' => true, 'sort_order' => $order],
            );
        }
        IncomeSourceTemplate::updateOrCreate(
            ['template_type' => 'general_income_sources', 'version' => 1],
            ['name' => 'SOURCES OF INCOME DECLARED BY CLIENT', 'description' => 'To be used as guide for credit profiling, credit investigation, and credit evaluation.', 'form_handler' => 'general-income-sources', 'data_handler' => 'general-income-sources', 'preview_handler' => 'general-income-sources', 'pdf_template_key' => 'general-income-sources', 'docx_template_key' => 'general-income-sources', 'is_fallback' => true, 'is_active' => true, 'sort_order' => 999],
        );

        foreach (['cibi', 'business_income_source', 'general_income_source', 'residence_business_photo'] as $type) {
            foreach (['pdf', 'docx'] as $format) {
                ReportTemplate::updateOrCreate(
                    ['report_type' => $type, 'format' => $format, 'version' => 1],
                    ['name' => strtoupper(str_replace('_', ' ', $type)).' '.strtoupper($format), 'template_reference' => "$type.$format.v1", 'paper_width_inches' => 8.5, 'paper_height_inches' => 13.0, 'is_active' => true],
                );
            }
        }

        foreach ([
            ['client_information', 'Client Information', 'client_information', [
                'required_folder_fields' => ['first_name', 'last_name'],
                'required_information_fields' => ['birth_date', 'civil_status', 'contact_number'],
                'required_address' => ['type' => 'present', 'fields' => ['address_line_1', 'city_municipality', 'province']],
            ]],
            ['cibi_report', 'CI / BI Report', 'cibi_report', [
                'required_report_fields' => [
                    'start_date', 'submitted_date', 'party_type', 'branch_name', 'account_officer_name', 'ci_risk_level',
                    'personal_snapshot.age', 'personal_snapshot.spouse_age', 'personal_snapshot.spouse_name',
                    'personal_snapshot.present_address', 'personal_snapshot.residence_status', 'personal_snapshot.home_condition',
                    'personal_snapshot.number_of_storeys', 'personal_snapshot.material_cost_level', 'personal_snapshot.living_condition',
                    'personal_snapshot.parents_address', 'personal_snapshot.civil_status', 'personal_snapshot.reputation',
                    'personal_snapshot.barangay_findings', 'personal_snapshot.lifestyle',
                ],
                'required_child_counts' => [],
            ]],
            ['income_sources', 'Business / Income Sources', 'income_sources'],
            ['residence_business_report', 'Residence & Business Report', 'residence_business_report'],
            ['required_activities', 'Required CI Activities', 'ci_activities'],
            ['required_reports', 'Required Generated Reports', 'generated_reports'],
        ] as $index => $definition) {
            [$code, $label, $sourceType] = $definition;
            CompletionRule::updateOrCreate(['code' => $code], ['label' => $label, 'source_type' => $sourceType, 'source_condition' => $definition[3] ?? null, 'is_required' => true, 'is_active' => true, 'sort_order' => $index + 1, 'weight' => null]);
        }
    }
}
