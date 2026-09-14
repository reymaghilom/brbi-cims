<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Monotonic edit token for optimistic concurrency on an existing Business Check, mirroring the
 * column residence_checks already carries. Required because updated_at cannot do this job: it is
 * second-precision, so two saves landing in the same second share an identical token and a stale
 * one is indistinguishable from a current one.
 *
 * Default 1 matches what the edit form renders for every row that already exists, so no backfill
 * is needed and no existing Business Check is altered.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_checks', function (Blueprint $table): void {
            $table->unsignedBigInteger('revision')->default(1)->after('updated_by');
        });
    }

    public function down(): void
    {
        Schema::table('business_checks', function (Blueprint $table): void {
            $table->dropColumn('revision');
        });
    }
};
