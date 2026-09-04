<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ci_activity_bank_targets', function (Blueprint $table): void {
            $table->timestamp('reminder_sent_at')->nullable()->after('scheduled_has_time');
        });

        Schema::table('ci_activity_asset_targets', function (Blueprint $table): void {
            $table->timestamp('reminder_sent_at')->nullable()->after('scheduled_has_time');
        });
    }

    public function down(): void
    {
        Schema::table('ci_activity_bank_targets', function (Blueprint $table): void {
            $table->dropColumn('reminder_sent_at');
        });

        Schema::table('ci_activity_asset_targets', function (Blueprint $table): void {
            $table->dropColumn('reminder_sent_at');
        });
    }
};
