<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_properties', function (Blueprint $table): void {
            $table->string('units_available')->nullable()->change();
            $table->string('units_with_tenants')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('business_properties', function (Blueprint $table): void {
            $table->unsignedInteger('units_available')->nullable()->change();
            $table->unsignedInteger('units_with_tenants')->nullable()->change();
        });
    }
};
