<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('residence_business_documentations', function (Blueprint $table): void {
            // Nullable preserves every historical generic Business Documentation row exactly as
            // saved. New Business Documentation always supplies the authoritative IncomeSource.
            $table->foreignId('income_source_id')->nullable()->after('co_maker_id')
                ->constrained('income_sources')->nullOnDelete();

            // IncomeSource ids are globally unique and Residence rows keep NULL, so this enforces
            // one Business Documentation context per stable business without affecting Residence
            // or legacy/unassigned rows on MySQL or SQLite.
            $table->unique(['income_source_id', 'category'], 'rbd_income_source_category_unique');
        });
    }

    public function down(): void
    {
        Schema::table('residence_business_documentations', function (Blueprint $table): void {
            $table->dropUnique('rbd_income_source_category_unique');
            $table->dropConstrainedForeignId('income_source_id');
        });
    }
};
