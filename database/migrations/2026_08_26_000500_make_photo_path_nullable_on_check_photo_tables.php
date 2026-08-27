<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A Cloudinary-backed photo (CloudinaryMediaStorage/ClientMediaUploader) never has a local
        // path — 'path' was left NOT NULL from when every photo was local-only, which blocks any
        // Cloudinary-only upload from ever being persisted.
        Schema::table('residence_check_photos', function (Blueprint $table) {
            $table->string('path')->nullable()->change();
        });

        Schema::table('business_check_photos', function (Blueprint $table) {
            $table->string('path')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('residence_check_photos', function (Blueprint $table) {
            $table->string('path')->nullable(false)->change();
        });

        Schema::table('business_check_photos', function (Blueprint $table) {
            $table->string('path')->nullable(false)->change();
        });
    }
};
