<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('income_source_contributors', function (Blueprint $table): void {
            $table->unsignedInteger('position')->nullable()->after('user_id');
        });

        Schema::table('residence_check_contributors', function (Blueprint $table): void {
            $table->unsignedInteger('position')->nullable()->after('user_id');
        });

        Schema::table('business_check_contributors', function (Blueprint $table): void {
            $table->unsignedInteger('position')->nullable()->after('user_id');
        });
    }

    public function down(): void
    {
        Schema::table('income_source_contributors', function (Blueprint $table): void {
            $table->dropColumn('position');
        });

        Schema::table('residence_check_contributors', function (Blueprint $table): void {
            $table->dropColumn('position');
        });

        Schema::table('business_check_contributors', function (Blueprint $table): void {
            $table->dropColumn('position');
        });
    }
};
