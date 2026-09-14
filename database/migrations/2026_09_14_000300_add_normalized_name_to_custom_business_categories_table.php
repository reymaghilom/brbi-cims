<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('custom_business_categories', function (Blueprint $table): void {
            $table->string('normalized_name')->nullable()->after('name');
        });

        $seen = [];
        foreach (DB::table('custom_business_categories')->orderBy('id')->get(['id', 'name']) as $category) {
            $normalized = mb_strtolower(trim((string) $category->name));
            if (isset($seen[$normalized])) {
                throw new RuntimeException(
                    "Cannot add the custom business category normalized-name constraint: rows {$seen[$normalized]} and {$category->id} normalize to the same value [{$normalized}].",
                );
            }

            $seen[$normalized] = $category->id;
            DB::table('custom_business_categories')->where('id', $category->id)->update([
                'normalized_name' => $normalized,
            ]);
        }

        Schema::table('custom_business_categories', function (Blueprint $table): void {
            $table->unique('normalized_name', 'custom_business_categories_normalized_name_unique');
        });
        Schema::table('custom_business_categories', function (Blueprint $table): void {
            $table->string('normalized_name')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('custom_business_categories', function (Blueprint $table): void {
            $table->dropUnique('custom_business_categories_normalized_name_unique');
            $table->dropColumn('normalized_name');
        });
    }
};
