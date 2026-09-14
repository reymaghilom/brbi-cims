<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Monotonic edit token for optimistic concurrency on a CI Activity, mirroring the column
 * residence_checks and business_checks already carry. updated_at cannot do this job: it is
 * second-precision, so two saves landing in the same second share an identical token and a stale
 * one is indistinguishable from a current one.
 *
 * Default 1 matches what every activity form renders for a row that already exists, so no backfill
 * is needed and no existing CI Activity is altered.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ci_activities', function (Blueprint $table): void {
            $table->unsignedBigInteger('revision')->default(1)->after('updated_by');
        });
    }

    public function down(): void
    {
        Schema::table('ci_activities', function (Blueprint $table): void {
            $table->dropColumn('revision');
        });
    }
};
