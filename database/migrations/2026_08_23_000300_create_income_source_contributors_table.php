<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('income_source_contributors', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('income_source_id')->constrained('income_sources')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['income_source_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('income_source_contributors');
    }
};
