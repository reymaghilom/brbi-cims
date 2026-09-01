<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ci_activity_bank_targets', function (Blueprint $table): void {
            // Before inquiry types existed, every row represented the Bank / Coop check purpose.
            $table->string('inquiry_type', 40)
                ->default('bank_coop_check')
                ->after('ci_activity_id');
            $table->index(['ci_activity_id', 'inquiry_type'], 'ci_bank_targets_activity_inquiry_index');
        });
    }

    public function down(): void
    {
        Schema::table('ci_activity_bank_targets', function (Blueprint $table): void {
            $table->dropIndex('ci_bank_targets_activity_inquiry_index');
            $table->dropColumn('inquiry_type');
        });
    }
};
