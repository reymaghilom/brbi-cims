<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records that a Business Check was intentionally deleted for this exact business.
 *
 * The mirror image of business_report_deleted_at, and deliberately a separate column rather than one
 * shared "deleted" flag: the two are independent decisions. Deleting a Business Check must suppress
 * only the Business Check work item and leave the Business Report exactly as it was. It is cleared
 * again the moment a Business Check is saved for that source.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('income_sources', function (Blueprint $table): void {
            $table->timestamp('business_check_deleted_at')->nullable()->after('business_report_deleted_at');
        });
    }

    public function down(): void
    {
        Schema::table('income_sources', function (Blueprint $table): void {
            $table->dropColumn('business_check_deleted_at');
        });
    }
};
