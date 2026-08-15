<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('income_source_templates')
            ->where('template_type', 'other_business_income_source')
            ->where('version', 1)
            ->update(['is_active' => false, 'updated_at' => now()]);

        DB::table('income_source_templates')
            ->where('template_type', 'other_business_source_of_income')
            ->where('version', 1)
            ->update([
                'name' => 'Other Business/Source of Income',
                'is_active' => true,
                'sort_order' => 20,
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        DB::table('income_source_templates')
            ->where('template_type', 'other_business_income_source')
            ->where('version', 1)
            ->update(['is_active' => true, 'updated_at' => now()]);

        DB::table('income_source_templates')
            ->where('template_type', 'other_business_source_of_income')
            ->where('version', 1)
            ->update(['name' => 'OTHER BUSINESS / SOURCE OF INCOME', 'updated_at' => now()]);
    }
};
