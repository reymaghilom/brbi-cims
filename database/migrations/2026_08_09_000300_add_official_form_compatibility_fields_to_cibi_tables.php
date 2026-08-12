<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cibi_reports', function (Blueprint $table): void {
            $table->json('summary_totals')->nullable()->after('personal_snapshot');
        });

        Schema::table('cibi_bank_accounts', function (Blueprint $table): void {
            $table->string('capital_share_text')->nullable()->after('capital_share_amount');
        });

        Schema::table('cibi_loan_records', function (Blueprint $table): void {
            $table->string('cycle_label')->nullable()->after('cycle_number');
        });
    }

    public function down(): void
    {
        Schema::table('cibi_loan_records', fn (Blueprint $table) => $table->dropColumn('cycle_label'));
        Schema::table('cibi_bank_accounts', fn (Blueprint $table) => $table->dropColumn('capital_share_text'));
        Schema::table('cibi_reports', fn (Blueprint $table) => $table->dropColumn('summary_totals'));
    }
};
