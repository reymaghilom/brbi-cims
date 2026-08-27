<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('residence_checks', function (Blueprint $table) {
            $table->string('map_evidence_type')->nullable()->after('google_maps_link');
        });
    }

    public function down(): void
    {
        Schema::table('residence_checks', function (Blueprint $table) {
            $table->dropColumn('map_evidence_type');
        });
    }
};
