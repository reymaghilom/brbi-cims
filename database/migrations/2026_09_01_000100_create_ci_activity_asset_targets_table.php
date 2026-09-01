<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ci_activity_asset_targets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ci_activity_id')->constrained('ci_activities')->cascadeOnDelete();
            $table->string('assessor_type', 40);
            $table->string('office_location');
            $table->string('status', 30)->default('pending');
            $table->timestamp('scheduled_at')->nullable();
            $table->boolean('scheduled_has_time')->default(false);
            $table->longText('remarks')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['ci_activity_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ci_activity_asset_targets');
    }
};
