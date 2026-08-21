<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('residence_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_folder_id')->constrained('client_folders')->cascadeOnDelete();
            $table->foreignId('co_maker_id')->nullable()->constrained('co_makers')->cascadeOnDelete();
            $table->date('ci_date');
            $table->text('location')->nullable();
            $table->longText('remarks')->nullable();
            $table->text('google_maps_link')->nullable();
            $table->foreignId('ci_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['client_folder_id', 'co_maker_id']);
        });

        Schema::create('residence_check_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('residence_check_id')->constrained('residence_checks')->cascadeOnDelete();
            $table->string('file_name');
            $table->string('path');
            $table->string('thumbnail_path')->nullable();
            $table->string('mime_type', 150)->nullable();
            $table->unsignedBigInteger('byte_size')->nullable();
            $table->string('checksum', 64)->nullable();
            $table->text('caption')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['residence_check_id', 'sort_order']);
        });

        Schema::create('business_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_folder_id')->constrained('client_folders')->cascadeOnDelete();
            $table->foreignId('co_maker_id')->nullable()->constrained('co_makers')->cascadeOnDelete();
            $table->foreignId('income_source_id')->constrained('income_sources')->cascadeOnDelete();
            $table->date('ci_date');
            $table->text('location')->nullable();
            $table->longText('remarks')->nullable();
            $table->longText('competitor_remarks')->nullable();
            $table->text('google_maps_link')->nullable();
            $table->foreignId('ci_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['client_folder_id', 'co_maker_id']);
            $table->index('income_source_id');
        });

        Schema::create('business_check_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_check_id')->constrained('business_checks')->cascadeOnDelete();
            $table->string('category', 20)->default('business');
            $table->string('file_name');
            $table->string('path');
            $table->string('thumbnail_path')->nullable();
            $table->string('mime_type', 150)->nullable();
            $table->unsignedBigInteger('byte_size')->nullable();
            $table->string('checksum', 64)->nullable();
            $table->text('caption')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['business_check_id', 'category', 'sort_order'], 'business_check_photos_check_category_order_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_check_photos');
        Schema::dropIfExists('business_checks');
        Schema::dropIfExists('residence_check_photos');
        Schema::dropIfExists('residence_checks');
    }
};
