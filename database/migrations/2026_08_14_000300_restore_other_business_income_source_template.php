<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('income_source_templates')->updateOrInsert(
            ['template_type' => 'other_business_income_source', 'version' => 1],
            [
                'name' => 'OTHER BUSINESS / INCOME SOURCE',
                'description' => 'Official dedicated business validation structure.',
                'business_category' => 'Other',
                'compatibility_tags' => json_encode([]),
                'form_handler' => 'dedicated-business',
                'data_handler' => 'dedicated-business',
                'preview_handler' => 'dedicated-business',
                'pdf_template_key' => 'other_business_income_source',
                'docx_template_key' => 'other_business_income_source',
                'is_fallback' => false,
                'is_active' => false,
                'sort_order' => 19,
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );
    }

    public function down(): void
    {
        DB::table('income_source_templates')
            ->where('template_type', 'other_business_income_source')
            ->where('version', 1)
            ->whereNotExists(function ($query) {
                $query->selectRaw('1')
                    ->from('income_sources')
                    ->whereColumn('income_sources.income_source_template_id', 'income_source_templates.id');
            })
            ->delete();
    }
};
