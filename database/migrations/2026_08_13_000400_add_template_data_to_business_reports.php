<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('business_reports', 'template_data')) {
            return;
        }

        Schema::table('business_reports', function (Blueprint $table): void {
            $table->json('template_data')->nullable()->after('report_remarks');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('business_reports', 'template_data')) {
            Schema::table('business_reports', fn (Blueprint $table) => $table->dropColumn('template_data'));
        }
    }
};
