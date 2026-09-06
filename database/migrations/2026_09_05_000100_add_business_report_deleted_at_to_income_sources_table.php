<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records that a Business Report was intentionally deleted for this exact business.
 *
 * Hard-deleting the business_reports row alone cannot express the difference between "this business
 * never had a Business Report" (Create Report is valid) and "its Business Report was deliberately
 * removed" (the work item must stay gone). Both look identical from the missing row. This marker
 * lives on the IncomeSource because the suppression belongs to that exact business — it is not a
 * Reports cache, and it is cleared again the moment a Business Report is saved for that source.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('income_sources', function (Blueprint $table): void {
            $table->timestamp('business_report_deleted_at')->nullable()->after('completed_at');
        });
    }

    public function down(): void
    {
        Schema::table('income_sources', function (Blueprint $table): void {
            $table->dropColumn('business_report_deleted_at');
        });
    }
};
