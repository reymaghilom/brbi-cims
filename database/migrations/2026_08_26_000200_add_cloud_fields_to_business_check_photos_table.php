<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_check_photos', function (Blueprint $table) {
            $table->string('cloud_public_id')->nullable()->after('checksum');
            $table->string('cloud_resource_type', 30)->nullable()->after('cloud_public_id');
            $table->string('cloud_delivery_type', 30)->nullable()->after('cloud_resource_type');
            $table->string('cloud_format', 20)->nullable()->after('cloud_delivery_type');
            $table->unsignedInteger('cloud_width')->nullable()->after('cloud_format');
            $table->unsignedInteger('cloud_height')->nullable()->after('cloud_width');
        });
    }

    public function down(): void
    {
        Schema::table('business_check_photos', function (Blueprint $table) {
            $table->dropColumn(['cloud_public_id', 'cloud_resource_type', 'cloud_delivery_type', 'cloud_format', 'cloud_width', 'cloud_height']);
        });
    }
};
