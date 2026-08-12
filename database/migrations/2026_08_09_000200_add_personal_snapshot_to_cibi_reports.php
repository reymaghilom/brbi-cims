<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cibi_reports', function (Blueprint $table): void {
            $table->json('personal_snapshot')->nullable()->after('ci_risk_level');
        });
    }

    public function down(): void
    {
        Schema::table('cibi_reports', function (Blueprint $table): void {
            $table->dropColumn('personal_snapshot');
        });
    }
};
