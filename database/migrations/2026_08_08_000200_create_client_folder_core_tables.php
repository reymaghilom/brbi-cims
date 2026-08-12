<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_folders', function (Blueprint $table) {
            $table->id();
            $table->string('folder_number', 50)->unique();
            $table->string('display_name');
            $table->string('last_name', 100);
            $table->string('first_name', 100);
            $table->string('middle_name', 100)->nullable();
            $table->string('suffix', 30)->nullable();
            $table->foreignId('assigned_ci_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 30)->default('on_progress');
            $table->decimal('progress_percent', 5, 2)->default(0);
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['last_name', 'first_name', 'middle_name']);
            $table->index(['assigned_ci_id', 'status', 'deleted_at']);
            $table->index(['status', 'created_at']);
        });

        Schema::create('client_information', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_folder_id')->unique()->constrained('client_folders')->cascadeOnDelete();
            $table->string('spouse_name')->nullable();
            $table->date('birth_date')->nullable();
            $table->string('civil_status', 40)->nullable();
            $table->string('contact_number', 60)->nullable();
            $table->string('email')->nullable();
            $table->unsignedInteger('length_of_stay_months')->nullable();
            $table->unsignedSmallInteger('dependents_count')->nullable();
            $table->string('home_ownership', 60)->nullable();
            $table->string('home_condition', 60)->nullable();
            $table->string('material_cost_level', 60)->nullable();
            $table->string('living_condition', 60)->nullable();
            $table->string('reputation', 100)->nullable();
            $table->string('lifestyle', 100)->nullable();
            $table->text('vehicles_owned')->nullable();
            $table->text('other_residences')->nullable();
            $table->text('barangay_findings')->nullable();
            $table->text('court_background_summary')->nullable();
            $table->text('other_remarks')->nullable();
            $table->string('completion_state', 20)->default('draft');
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('last_edited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('client_addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_folder_id')->constrained('client_folders')->cascadeOnDelete();
            $table->string('address_type', 40);
            $table->string('address_line_1');
            $table->string('address_line_2')->nullable();
            $table->string('barangay')->nullable();
            $table->string('city_municipality')->nullable();
            $table->string('province')->nullable();
            $table->string('postal_code', 20)->nullable();
            $table->string('country', 100)->default('Philippines');
            $table->text('google_maps_link')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->unsignedInteger('length_of_stay_months')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->index(['client_folder_id', 'address_type', 'is_primary']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_addresses');
        Schema::dropIfExists('client_information');
        Schema::dropIfExists('client_folders');
    }
};
