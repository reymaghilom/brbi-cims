<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ci_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_folder_id')->constrained('client_folders')->cascadeOnDelete();
            $table->foreignId('activity_definition_id')->constrained('activity_definitions')->restrictOnDelete();
            $table->string('name');
            $table->string('status', 30)->default('not_started');
            $table->date('visit_date')->nullable();
            $table->time('time_in')->nullable();
            $table->time('time_out')->nullable();
            $table->string('visited_by')->nullable();
            $table->string('person_met_contact')->nullable();
            $table->longText('remarks')->nullable();
            $table->text('supporting_reference')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['client_folder_id', 'activity_definition_id']);
            $table->index(['client_folder_id', 'status']);
            $table->index(['status', 'updated_at']);
        });

        Schema::create('activity_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ci_activity_id')->constrained('ci_activities')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->longText('note');
            $table->boolean('follow_up_needed')->default(false);
            $table->timestamps();
            $table->index(['ci_activity_id', 'created_at']);
        });

        Schema::create('activity_media', function (Blueprint $table) {
            $table->foreignId('ci_activity_id')->constrained('ci_activities')->cascadeOnDelete();
            $table->foreignId('media_reference_id')->constrained('media_references')->restrictOnDelete();
            $table->string('label')->nullable();
            $table->timestamps();
            $table->primary(['ci_activity_id', 'media_reference_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_media');
        Schema::dropIfExists('activity_notes');
        Schema::dropIfExists('ci_activities');
    }
};
