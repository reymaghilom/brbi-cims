<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('business_reports', 'rented_from')) {
            return;
        }

        Schema::table('business_reports', function (Blueprint $table): void {
            $table->string('rented_from')->nullable()->after('ownership_type');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('business_reports', 'rented_from')) {
            Schema::table('business_reports', fn (Blueprint $table) => $table->dropColumn('rented_from'));
        }
    }
};
