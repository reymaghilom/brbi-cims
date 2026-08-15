<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_reports', function (Blueprint $table): void {
            $table->string('monthly_rent')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('business_reports', function (Blueprint $table): void {
            $table->decimal('monthly_rent', 15, 2)->nullable()->change();
        });
    }
};
