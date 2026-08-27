<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_checks', function (Blueprint $table) {
            $table->string('map_screenshot_cloud_public_id')->nullable()->after('map_screenshot_uploaded_by');
            $table->string('map_screenshot_cloud_resource_type', 30)->nullable()->after('map_screenshot_cloud_public_id');
            $table->string('map_screenshot_cloud_delivery_type', 30)->nullable()->after('map_screenshot_cloud_resource_type');
            $table->string('map_screenshot_cloud_format', 20)->nullable()->after('map_screenshot_cloud_delivery_type');
            $table->unsignedInteger('map_screenshot_cloud_width')->nullable()->after('map_screenshot_cloud_format');
            $table->unsignedInteger('map_screenshot_cloud_height')->nullable()->after('map_screenshot_cloud_width');
        });
    }

    public function down(): void
    {
        Schema::table('business_checks', function (Blueprint $table) {
            $table->dropColumn([
                'map_screenshot_cloud_public_id', 'map_screenshot_cloud_resource_type', 'map_screenshot_cloud_delivery_type',
                'map_screenshot_cloud_format', 'map_screenshot_cloud_width', 'map_screenshot_cloud_height',
            ]);
        });
    }
};
