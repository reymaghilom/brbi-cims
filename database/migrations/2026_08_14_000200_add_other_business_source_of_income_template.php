<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('income_source_templates')->updateOrInsert(
            ['template_type' => 'other_business_source_of_income', 'version' => 1],
            [
                'name' => 'Other Business/Source of Income',
                'description' => 'Fallback Business Report for a declared business or income source without a predefined template.',
                'business_category' => 'Other',
                'compatibility_tags' => json_encode([]),
                'form_handler' => 'dedicated-business',
                'data_handler' => 'dedicated-business',
                'preview_handler' => 'dedicated-business',
                'pdf_template_key' => 'other_business_source_of_income',
                'docx_template_key' => 'other_business_source_of_income',
                'is_fallback' => false,
                'is_active' => true,
                'sort_order' => 20,
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );
    }

    public function down(): void
    {
        DB::table('income_source_templates')
            ->where('template_type', 'other_business_source_of_income')
            ->where('version', 1)
            ->whereNotExists(function ($query) {
                $query->selectRaw('1')
                    ->from('income_sources')
                    ->whereColumn('income_sources.income_source_template_id', 'income_source_templates.id');
            })
            ->delete();
    }
};
