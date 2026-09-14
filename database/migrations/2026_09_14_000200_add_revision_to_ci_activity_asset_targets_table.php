<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Monotonic edit token for optimistic concurrency on a single Asset Check target, mirroring the
 * column residence_checks, business_checks, ci_activities and ci_activity_bank_targets carry.
 *
 * The parent CiActivity row lock already serializes every Asset target mutation, so parent-status
 * synchronization, same-parent/different-target ordering and edit-vs-delete integrity were never
 * at risk. What the lock cannot do is tell a CURRENT save apart from a STALE one: two CIs editing
 * the same assessor target simply ran one after the other and the later form silently overwrote
 * the earlier save. updated_at cannot fill that gap either — it is second-precision, so two saves
 * landing in the same second share an identical token.
 *
 * Default 1 matches what the edit form renders for a row that already exists, so no backfill is
 * needed and no existing target is altered.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ci_activity_asset_targets', function (Blueprint $table): void {
            $table->unsignedBigInteger('revision')->default(1)->after('updated_by');
        });
    }

    public function down(): void
    {
        Schema::table('ci_activity_asset_targets', function (Blueprint $table): void {
            $table->dropColumn('revision');
        });
    }
};
