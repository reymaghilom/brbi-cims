<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('generated_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_folder_id')->constrained('client_folders')->cascadeOnDelete();
            $table->foreignId('income_source_id')->nullable()->constrained('income_sources')->cascadeOnDelete();
            $table->string('scope_key', 100);
            $table->string('source_type', 80);
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('report_type', 80);
            $table->string('format', 20);
            $table->unsignedInteger('version');
            $table->unsignedInteger('template_version')->nullable();
            $table->unsignedInteger('source_revision')->nullable();
            $table->json('source_snapshot')->nullable();
            $table->string('status', 30)->default('processing');
            $table->string('private_file_reference')->nullable();
            $table->string('google_drive_file_id')->nullable();
            $table->string('mime_type', 150)->nullable();
            $table->unsignedBigInteger('byte_size')->nullable();
            $table->string('checksum', 64)->nullable();
            $table->foreignId('generated_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('generated_at')->nullable();
            $table->string('failure_code')->nullable();
            $table->text('failure_message')->nullable();
            $table->timestamps();
            $table->unique(['scope_key', 'report_type', 'format', 'version'], 'generated_report_scope_version_unique');
            $table->index(['client_folder_id', 'report_type', 'created_at']);
            $table->index(['income_source_id', 'report_type', 'created_at']);
            $table->index(['status', 'created_at']);
        });

        Schema::create('attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_folder_id')->constrained('client_folders')->cascadeOnDelete();
            $table->string('category', 80);
            $table->string('original_filename');
            $table->string('mime_type', 150)->nullable();
            $table->unsignedBigInteger('byte_size')->nullable();
            $table->string('checksum', 64)->nullable();
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('uploaded_at')->nullable();
            $table->string('private_file_reference')->nullable();
            $table->string('external_provider', 50)->nullable();
            $table->string('external_file_id')->nullable();
            $table->text('external_link')->nullable();
            $table->string('status', 30)->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['client_folder_id', 'category']);
            $table->index('checksum');
        });

        Schema::create('google_drive_references', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_folder_id')->constrained('client_folders')->cascadeOnDelete();
            $table->string('resource_type', 80);
            $table->unsignedBigInteger('resource_id');
            $table->string('drive_file_id')->unique();
            $table->string('parent_drive_folder_id')->nullable();
            $table->text('web_view_link')->nullable();
            $table->string('backup_status', 30)->default('queued');
            $table->unsignedInteger('attempt_count')->default(0);
            $table->timestamp('last_attempted_at')->nullable();
            $table->timestamp('backed_up_at')->nullable();
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['resource_type', 'resource_id']);
            $table->index(['client_folder_id', 'backup_status']);
        });

        Schema::create('telegram_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_folder_id')->constrained('client_folders')->cascadeOnDelete();
            $table->string('category', 40);
            $table->string('message_type', 80);
            $table->longText('caption');
            $table->string('caption_hash', 64);
            $table->string('idempotency_key', 100)->unique();
            $table->text('chat_id_encrypted')->nullable();
            $table->string('telegram_message_id')->nullable();
            $table->text('telegram_link')->nullable();
            $table->foreignId('sent_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->unsignedInteger('photo_count')->default(0);
            $table->unsignedInteger('video_count')->default(0);
            $table->string('status', 30)->default('queued');
            $table->unsignedInteger('retry_count')->default(0);
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
            $table->index(['client_folder_id', 'category', 'sent_at']);
            $table->index(['status', 'created_at']);
        });

        Schema::create('telegram_message_media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('telegram_message_id')->constrained('telegram_messages')->cascadeOnDelete();
            $table->foreignId('media_reference_id')->constrained('media_references')->restrictOnDelete();
            $table->string('telegram_file_id')->nullable();
            $table->string('provider_message_id')->nullable();
            $table->string('status', 30)->default('queued');
            $table->unsignedInteger('sort_order')->default(0);
            $table->text('error_summary')->nullable();
            $table->timestamps();
            $table->unique(['telegram_message_id', 'media_reference_id'], 'telegram_message_media_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_message_media');
        Schema::dropIfExists('telegram_messages');
        Schema::dropIfExists('google_drive_references');
        Schema::dropIfExists('attachments');
        Schema::dropIfExists('generated_reports');
    }
};
