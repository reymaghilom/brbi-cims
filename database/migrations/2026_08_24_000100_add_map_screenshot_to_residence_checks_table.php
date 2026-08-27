<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('residence_checks', function (Blueprint $table) {
            $table->string('map_screenshot_file_name')->nullable()->after('google_maps_link');
            $table->string('map_screenshot_path')->nullable()->after('map_screenshot_file_name');
            $table->string('map_screenshot_thumbnail_path')->nullable()->after('map_screenshot_path');
            $table->string('map_screenshot_mime_type', 150)->nullable()->after('map_screenshot_thumbnail_path');
            $table->unsignedBigInteger('map_screenshot_byte_size')->nullable()->after('map_screenshot_mime_type');
            $table->foreignId('map_screenshot_uploaded_by')->nullable()->after('map_screenshot_byte_size')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('residence_checks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('map_screenshot_uploaded_by');
            $table->dropColumn(['map_screenshot_file_name', 'map_screenshot_path', 'map_screenshot_thumbnail_path', 'map_screenshot_mime_type', 'map_screenshot_byte_size']);
        });
    }
};
