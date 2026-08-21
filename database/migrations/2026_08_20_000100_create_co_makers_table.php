<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('co_makers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_folder_id')->unique()->constrained('client_folders')->cascadeOnDelete();
            $table->string('full_name');
            $table->string('relationship_to_applicant', 100)->nullable();
            $table->string('contact_number', 60)->nullable();
            $table->text('address')->nullable();
            $table->foreignId('last_edited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('co_makers');
    }
};
