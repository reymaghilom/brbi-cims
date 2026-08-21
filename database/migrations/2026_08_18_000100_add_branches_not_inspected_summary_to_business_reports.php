<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_reports', function (Blueprint $table) {
            $table->unsignedInteger('branches_not_inspected')->default(0)->after('branches_inspected');
            $table->text('branches_reason_not_inspected')->nullable()->after('branches_not_inspected');
        });
    }

    public function down(): void
    {
        Schema::table('business_reports', function (Blueprint $table) {
            $table->dropColumn(['branches_not_inspected', 'branches_reason_not_inspected']);
        });
    }
};
