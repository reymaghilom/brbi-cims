<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pending_file_cleanups', function (Blueprint $table): void {
            $table->id();
            $table->string('kind', 30);
            $table->text('path')->nullable();
            $table->string('storage_provider', 30)->nullable();
            $table->text('public_id')->nullable();
            $table->string('resource_type', 30)->nullable();
            $table->string('delivery_type', 30)->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->uuid('claim_token')->nullable()->unique();
            $table->timestamp('claimed_at')->nullable()->index();
            $table->timestamp('last_attempted_at')->nullable();
            $table->string('last_error', 255)->nullable();
            $table->timestamps();

            $table->index(['kind', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pending_file_cleanups');
    }
};
