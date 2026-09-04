<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Business Check now persists its own Business Name snapshot instead of always reading
        // the live IncomeSource/BusinessReport name — required so a later rename on either side
        // can no longer change what an already-saved Business Check displays.
        Schema::table('business_checks', function (Blueprint $table): void {
            $table->string('business_name')->nullable()->after('income_source_id');
        });

        // Business Report and Business Check delete is now permanent (no Recycle Bin entry, no
        // restore) — dropping soft-delete columns matches ResidenceCheck, which never had one.
        Schema::table('business_reports', function (Blueprint $table): void {
            $table->dropSoftDeletes();
        });
        Schema::table('business_checks', function (Blueprint $table): void {
            $table->dropSoftDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('business_checks', function (Blueprint $table): void {
            $table->softDeletes();
        });
        Schema::table('business_reports', function (Blueprint $table): void {
            $table->softDeletes();
        });
        Schema::table('business_checks', function (Blueprint $table): void {
            $table->dropColumn('business_name');
        });
    }
};
