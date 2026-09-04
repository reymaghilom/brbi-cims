<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('residence_business_documentations', function (Blueprint $table) {
            // CI Date was added for Photos & Videos Residence/Business Documentation
            // (2026_09_03_000400) but is not part of that workflow after all — removed here rather
            // than editing the already-applied migration.
            $table->dropColumn('ci_date');
        });
    }

    public function down(): void
    {
        Schema::table('residence_business_documentations', function (Blueprint $table) {
            $table->date('ci_date')->nullable()->after('location');
        });
    }
};
