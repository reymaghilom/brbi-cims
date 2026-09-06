<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Removes the last traces of the retired Google Drive and Telegram integrations: seven columns that
 * mirrored a media item (or a generated report) into those external services. Both tables stay —
 * only these columns go.
 *
 * Each was verified before removal: no model, controller, action, service, Blade view, factory or
 * test reads or writes it, and every one is NULL in every row. `backup_status` and `send_status`
 * have generic-sounding names but are not generic: they were declared alongside the Drive and
 * Telegram id/link pairs and mirror `google_drive_references.backup_status` and the send state on
 * `telegram_messages`, both of which were dropped with those features.
 *
 * Deliberately NOT touched, because they are actively used and only look related:
 *
 * - media_references.google_maps_link — Google MAPS, not Drive; it is Residence/Business Check map
 *   evidence and is live.
 * - media_references.temporary_local_path / thumbnail_path / storage_provider / cloudinary_* — the
 *   Local and Cloudinary File Storage columns behind CI Activity Supporting Proof.
 * - generated_reports.private_file_reference — the local report artifact pointer that replaced the
 *   Drive mirror, and generated_reports.status, which is the generation state machine.
 *
 * The index on media_references.google_drive_file_id is dropped first so the column drop is clean on
 * every driver.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_references', function (Blueprint $table): void {
            if (Schema::hasIndex('media_references', 'media_references_google_drive_file_id_index')) {
                $table->dropIndex('media_references_google_drive_file_id_index');
            }
        });

        Schema::table('media_references', function (Blueprint $table): void {
            $table->dropColumn(['google_drive_file_id', 'google_drive_link', 'telegram_message_id', 'telegram_link', 'backup_status', 'send_status']);
        });

        Schema::table('generated_reports', function (Blueprint $table): void {
            $table->dropColumn('google_drive_file_id');
        });
    }

    public function down(): void
    {
        // Intentionally irreversible: these columns only ever described integrations that no longer
        // exist, so recreating them would reintroduce dead schema.
    }
};
