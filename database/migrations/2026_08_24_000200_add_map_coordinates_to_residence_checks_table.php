<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('residence_checks', function (Blueprint $table) {
            $table->decimal('latitude', 10, 7)->nullable()->after('google_maps_link');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
            $table->string('google_place_id')->nullable()->after('longitude');
            $table->text('formatted_map_address')->nullable()->after('google_place_id');
        });
    }

    public function down(): void
    {
        Schema::table('residence_checks', function (Blueprint $table) {
            $table->dropColumn(['latitude', 'longitude', 'google_place_id', 'formatted_map_address']);
        });
    }
};
