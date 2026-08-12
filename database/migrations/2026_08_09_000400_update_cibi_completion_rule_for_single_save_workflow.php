<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('completion_rules')->where('code', 'cibi_report')->update([
            'source_condition' => json_encode([
                'required_report_fields' => [
                    'start_date', 'submitted_date', 'party_type', 'branch_name', 'account_officer_name', 'ci_risk_level',
                    'personal_snapshot.age', 'personal_snapshot.spouse_age', 'personal_snapshot.spouse_name',
                    'personal_snapshot.present_address', 'personal_snapshot.residence_status', 'personal_snapshot.home_condition',
                    'personal_snapshot.number_of_storeys', 'personal_snapshot.material_cost_level', 'personal_snapshot.living_condition',
                    'personal_snapshot.parents_address', 'personal_snapshot.civil_status', 'personal_snapshot.reputation',
                    'personal_snapshot.barangay_findings', 'personal_snapshot.lifestyle',
                ],
                'required_child_counts' => [],
            ], JSON_THROW_ON_ERROR),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('completion_rules')->where('code', 'cibi_report')->update([
            'source_condition' => json_encode([
                'required_report_fields' => [
                    'start_date', 'submitted_date', 'party_type', 'branch_name', 'account_officer_name',
                    'amount_applied', 'ci_risk_level', 'purpose_codes', 'prepared_by_name',
                ],
                'required_child_counts' => ['incomeSourceSummaries' => 1, 'legalFindings' => 1],
            ], JSON_THROW_ON_ERROR),
            'updated_at' => now(),
        ]);
    }
};
