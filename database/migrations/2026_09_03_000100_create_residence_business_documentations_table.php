<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('residence_business_documentations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_folder_id')->constrained('client_folders')->cascadeOnDelete();
            $table->foreignId('co_maker_id')->nullable()->constrained('co_makers')->cascadeOnDelete();
            $table->string('category', 20);
            $table->text('location');
            // media_references already exists (created 2026_08_08_000500) — the one map
            // screenshot is stored there like any other local upload, just singled out by this FK.
            $table->foreignId('map_screenshot_media_id')->nullable()->constrained('media_references', 'id', 'rbd_map_screenshot_media_id_foreign')->nullOnDelete();
            $table->string('state', 20)->default('draft');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['client_folder_id', 'co_maker_id', 'category'], 'rbd_folder_co_maker_category_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('residence_business_documentations');
    }
};
