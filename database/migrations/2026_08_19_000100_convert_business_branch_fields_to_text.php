<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_reports', function (Blueprint $table): void {
            $table->string('length_of_stay_months')->nullable()->change();
            $table->string('branches_declared')->nullable()->default('0')->change();
            $table->string('branches_inspected')->nullable()->default('0')->change();
            $table->string('branches_not_inspected')->nullable()->default('0')->change();
        });

        Schema::table('business_branches', function (Blueprint $table): void {
            $table->string('frontage_meters')->nullable()->change();
            $table->string('total_area_square_meters')->nullable()->change();
            $table->string('average_sales_per_shift')->nullable()->change();
            $table->string('monthly_rent')->nullable()->change();
            $table->string('years_in_area')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('business_reports', function (Blueprint $table): void {
            $table->unsignedInteger('length_of_stay_months')->nullable()->change();
            $table->unsignedInteger('branches_declared')->default(0)->change();
            $table->unsignedInteger('branches_inspected')->default(0)->change();
            $table->unsignedInteger('branches_not_inspected')->default(0)->change();
        });

        Schema::table('business_branches', function (Blueprint $table): void {
            $table->decimal('frontage_meters', 12, 2)->nullable()->change();
            $table->decimal('total_area_square_meters', 12, 2)->nullable()->change();
            $table->decimal('average_sales_per_shift', 15, 2)->nullable()->change();
            $table->decimal('monthly_rent', 15, 2)->nullable()->change();
            $table->decimal('years_in_area', 7, 2)->nullable()->change();
        });
    }
};
