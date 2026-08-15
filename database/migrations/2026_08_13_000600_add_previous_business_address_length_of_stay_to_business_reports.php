<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('business_reports', 'previous_business_address_length_of_stay')) {
            return;
        }

        Schema::table('business_reports', function (Blueprint $table): void {
            $table->string('previous_business_address_length_of_stay', 100)->nullable()->after('previous_business_address');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('business_reports', 'previous_business_address_length_of_stay')) {
            Schema::table('business_reports', fn (Blueprint $table) => $table->dropColumn('previous_business_address_length_of_stay'));
        }
    }
};
