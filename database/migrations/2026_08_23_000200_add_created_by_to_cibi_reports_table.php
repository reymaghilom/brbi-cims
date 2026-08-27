<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cibi_reports', function (Blueprint $table): void {
            $table->foreignId('created_by')->nullable()->after('ci_in_charge_id')
                ->constrained('users')->nullOnDelete();
        });

        // Best-effort backfill: ci_in_charge_id is the only historically tracked actor,
        // so it is the closest unambiguous stand-in for "who created this report".
        DB::table('cibi_reports')->update(['created_by' => DB::raw('ci_in_charge_id')]);
    }

    public function down(): void
    {
        Schema::table('cibi_reports', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('created_by');
        });
    }
};
