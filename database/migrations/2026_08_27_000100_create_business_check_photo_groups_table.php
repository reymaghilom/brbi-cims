<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_check_photo_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_check_id')->constrained('business_checks')->cascadeOnDelete();
            $table->text('caption')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->index(['business_check_id', 'sort_order']);
        });

        // Nullable for backward compatibility — a historical Business Check's photos, saved before
        // this feature existed, keep resolving via the ungrouped fallback (see
        // BusinessCheckController::form()'s "legacy Photo Group 1" grouping) rather than needing a
        // data migration or ever being physically moved/re-uploaded.
        Schema::table('business_check_photos', function (Blueprint $table) {
            $table->foreignId('business_check_photo_group_id')->nullable()->after('business_check_id')
                ->constrained('business_check_photo_groups')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('business_check_photos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('business_check_photo_group_id');
        });
        Schema::dropIfExists('business_check_photo_groups');
    }
};
