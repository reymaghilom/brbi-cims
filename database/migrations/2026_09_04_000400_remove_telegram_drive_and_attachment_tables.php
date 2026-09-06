<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Drops the four tables that belonged exclusively to the retired Telegram History, Google Drive and
 * legacy Attachments / Documents surfaces. Each was verified before removal: no model, controller,
 * service, route, Blade view or test still referenced it, and every one of them was empty.
 *
 * Deliberately NOT touched, because they live on tables active features depend on and dropping them
 * would risk working workflows for no benefit:
 *
 * - media_references.google_drive_file_id / google_drive_link / telegram_message_id / telegram_link /
 *   backup_status / send_status — media_references is the backbone of CI Activity Supporting Proof.
 * - generated_reports.google_drive_file_id — generated_reports remains a fully active feature.
 *
 * Those columns are unused and null everywhere today; they are left in place as inert history rather
 * than altering shared tables. audit_logs is likewise untouched: it is shared history.
 *
 * Child table first so the foreign key on telegram_message_media is gone before its parent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('telegram_message_media');
        Schema::dropIfExists('telegram_messages');
        Schema::dropIfExists('google_drive_references');
        Schema::dropIfExists('attachments');
    }

    public function down(): void
    {
        // Intentionally irreversible: these surfaces were retired, and recreating empty shells for
        // features that no longer exist would only reintroduce dead schema.
    }
};
