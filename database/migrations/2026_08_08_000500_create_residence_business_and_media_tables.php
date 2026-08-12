<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('residence_business_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_folder_id')->unique()->constrained('client_folders')->cascadeOnDelete();
            $table->date('report_date')->nullable();
            $table->text('default_location')->nullable();
            $table->string('default_subject')->nullable();
            $table->foreignId('ci_user_id')->constrained('users')->restrictOnDelete();
            $table->longText('residence_remarks')->nullable();
            $table->longText('business_remarks')->nullable();
            $table->string('state', 20)->default('draft');
            $table->timestamp('completed_at')->nullable();
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();
        });

        Schema::create('photo_report_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('residence_business_report_id')->constrained('residence_business_reports')->cascadeOnDelete();
            $table->foreignId('income_source_id')->nullable()->constrained('income_sources')->nullOnDelete();
            $table->string('category', 40);
            $table->string('subject_party', 40)->default('applicant');
            $table->string('subject_name')->nullable();
            $table->string('heading_subject')->nullable();
            $table->text('location')->nullable();
            $table->string('business_name')->nullable();
            $table->text('google_maps_link')->nullable();
            $table->longText('remarks')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->index(['residence_business_report_id', 'category', 'sort_order'], 'photo_sections_report_category_order_idx');
        });

        Schema::create('media_references', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_folder_id')->constrained('client_folders')->cascadeOnDelete();
            $table->foreignId('income_source_id')->nullable()->constrained('income_sources')->cascadeOnDelete();
            $table->string('media_type', 20);
            $table->string('category', 40);
            $table->string('file_name');
            $table->string('label')->nullable();
            $table->text('remarks')->nullable();
            $table->string('mime_type', 150)->nullable();
            $table->unsignedBigInteger('byte_size')->nullable();
            $table->string('checksum', 64)->nullable();
            $table->timestamp('captured_at')->nullable();
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->string('temporary_local_path')->nullable();
            $table->timestamp('temporary_expires_at')->nullable();
            $table->string('thumbnail_path')->nullable();
            $table->string('google_drive_file_id')->nullable();
            $table->text('google_drive_link')->nullable();
            $table->string('telegram_message_id')->nullable();
            $table->text('telegram_link')->nullable();
            $table->text('google_maps_link')->nullable();
            $table->string('backup_status', 30)->nullable();
            $table->string('send_status', 30)->nullable();
            $table->timestamps();
            $table->index(['client_folder_id', 'category', 'media_type']);
            $table->index(['income_source_id', 'category', 'media_type']);
            $table->index('google_drive_file_id');
            $table->index('checksum');
        });

        Schema::create('photo_report_media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('photo_report_section_id')->constrained('photo_report_sections')->cascadeOnDelete();
            $table->foreignId('media_reference_id')->constrained('media_references')->restrictOnDelete();
            $table->string('output_label')->nullable();
            $table->text('caption')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->json('crop_orientation_metadata')->nullable();
            $table->timestamps();
            $table->unique(['photo_report_section_id', 'media_reference_id'], 'photo_report_section_media_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('photo_report_media');
        Schema::dropIfExists('media_references');
        Schema::dropIfExists('photo_report_sections');
        Schema::dropIfExists('residence_business_reports');
    }
};
