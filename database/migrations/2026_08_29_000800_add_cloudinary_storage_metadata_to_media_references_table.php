<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_references', function (Blueprint $table): void {
            $table->string('storage_provider', 30)->default('local')->index()->after('checksum');
            $table->string('cloudinary_public_id')->nullable()->after('thumbnail_path');
            $table->string('cloudinary_resource_type', 30)->nullable()->after('cloudinary_public_id');
            $table->text('cloudinary_secure_url')->nullable()->after('cloudinary_resource_type');
        });
    }

    public function down(): void
    {
        Schema::table('media_references', function (Blueprint $table): void {
            $table->dropIndex(['storage_provider']);
            $table->dropColumn([
                'storage_provider',
                'cloudinary_public_id',
                'cloudinary_resource_type',
                'cloudinary_secure_url',
            ]);
        });
    }
};
