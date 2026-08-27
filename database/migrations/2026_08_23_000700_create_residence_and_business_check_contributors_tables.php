<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('residence_check_contributors', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('residence_check_id')->constrained('residence_checks')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['residence_check_id', 'user_id']);
        });

        Schema::create('business_check_contributors', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_check_id')->constrained('business_checks')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['business_check_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_check_contributors');
        Schema::dropIfExists('residence_check_contributors');
    }
};
