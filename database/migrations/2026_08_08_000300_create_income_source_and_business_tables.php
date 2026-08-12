<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('income_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_folder_id')->constrained('client_folders')->cascadeOnDelete();
            $table->foreignId('income_source_template_id')->constrained('income_source_templates')->restrictOnDelete();
            $table->string('template_type', 100);
            $table->unsignedInteger('template_version');
            $table->string('source_name');
            $table->string('business_name')->nullable();
            $table->unsignedSmallInteger('contribution_rank')->nullable();
            $table->decimal('estimated_monthly_contribution', 15, 2)->nullable();
            $table->boolean('is_primary')->default(false);
            $table->string('applicant_name_snapshot')->nullable();
            $table->string('branch_name')->nullable();
            $table->decimal('amount_applied', 15, 2)->nullable();
            $table->string('account_officer_name')->nullable();
            $table->string('state', 20)->default('draft');
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('last_edited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('revision')->default(1);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['client_folder_id', 'state']);
            $table->index(['client_folder_id', 'contribution_rank']);
            $table->index(['client_folder_id', 'template_type']);
        });

        Schema::create('general_income_source_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('income_source_id')->unique()->constrained('income_sources')->cascadeOnDelete();
            $table->longText('general_remarks')->nullable();
            $table->json('completion_metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('declared_income_source_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('general_income_source_report_id');
            $table->foreign('general_income_source_report_id', 'declared_income_report_fk')->references('id')->on('general_income_source_reports')->cascadeOnDelete();
            $table->string('source_name');
            $table->string('source_type')->nullable();
            $table->text('description')->nullable();
            $table->decimal('amount_contribution', 15, 2)->nullable();
            $table->unsignedSmallInteger('contribution_rank')->nullable();
            $table->text('remarks')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['general_income_source_report_id', 'contribution_rank'], 'general_income_rank_unique');
        });

        Schema::create('business_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('income_source_id')->unique()->constrained('income_sources')->cascadeOnDelete();
            $table->string('business_name');
            $table->string('report_category', 80);
            $table->text('main_business_address')->nullable();
            $table->text('previous_business_address')->nullable();
            $table->text('reason_for_transfer')->nullable();
            $table->string('registered_owner')->nullable();
            $table->string('relationship_to_borrower')->nullable();
            $table->unsignedSmallInteger('year_established')->nullable();
            $table->unsignedInteger('length_of_stay_months')->nullable();
            $table->decimal('monthly_rent', 15, 2)->nullable();
            $table->string('ownership_type', 80)->nullable();
            $table->string('business_type', 100)->nullable();
            $table->string('scale', 80)->nullable();
            $table->string('informant')->nullable();
            $table->unsignedInteger('properties_declared')->default(0);
            $table->unsignedInteger('properties_inspected')->default(0);
            $table->unsignedInteger('branches_declared')->default(0);
            $table->unsignedInteger('branches_inspected')->default(0);
            $table->longText('report_remarks')->nullable();
            $table->timestamps();
        });

        Schema::create('business_properties', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_report_id')->constrained('business_reports')->cascadeOnDelete();
            $table->string('property_type', 80);
            $table->boolean('is_declared')->default(true);
            $table->boolean('is_inspected')->default(false);
            $table->text('reason_not_inspected')->nullable();
            $table->unsignedInteger('units_available')->nullable();
            $table->unsignedInteger('units_with_tenants')->nullable();
            $table->text('location')->nullable();
            $table->decimal('area_square_meters', 12, 2)->nullable();
            $table->boolean('has_contract')->nullable();
            $table->text('remarks')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('business_tenants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_property_id')->constrained('business_properties')->cascadeOnDelete();
            $table->string('tenant_name');
            $table->decimal('monthly_rent', 15, 2)->nullable();
            $table->decimal('years_renting', 7, 2)->nullable();
            $table->boolean('has_contract')->nullable();
            $table->string('contact_details')->nullable();
            $table->text('remarks')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('business_branches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_report_id')->constrained('business_reports')->cascadeOnDelete();
            $table->text('location');
            $table->boolean('is_declared')->default(true);
            $table->boolean('is_inspected')->default(false);
            $table->text('reason_not_inspected')->nullable();
            $table->decimal('frontage_meters', 12, 2)->nullable();
            $table->decimal('total_area_square_meters', 12, 2)->nullable();
            $table->boolean('is_air_conditioned')->nullable();
            $table->string('operating_days_hours')->nullable();
            $table->unsignedSmallInteger('shifts_count')->nullable();
            $table->unsignedSmallInteger('employees_per_shift')->nullable();
            $table->decimal('average_sales_per_shift', 15, 2)->nullable();
            $table->string('inventory_level', 40)->nullable();
            $table->decimal('monthly_rent', 15, 2)->nullable();
            $table->decimal('years_in_area', 7, 2)->nullable();
            $table->text('nearby_brands')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('business_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_report_id')->constrained('business_reports')->cascadeOnDelete();
            $table->string('product_name');
            $table->string('unit_size')->nullable();
            $table->decimal('selling_price', 15, 2)->nullable();
            $table->string('stock_level', 40)->nullable();
            $table->boolean('is_top_seller')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('business_suppliers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_report_id')->constrained('business_reports')->cascadeOnDelete();
            $table->string('supplier_name');
            $table->text('office_location')->nullable();
            $table->string('contact_information')->nullable();
            $table->boolean('is_confirmed')->nullable();
            $table->decimal('years_transacting', 7, 2)->nullable();
            $table->string('payment_performance')->nullable();
            $table->text('remarks')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('business_observations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_report_id')->constrained('business_reports')->cascadeOnDelete();
            $table->string('observation_code', 100);
            $table->text('question_snapshot');
            $table->text('answer')->nullable();
            $table->text('remarks')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['business_report_id', 'observation_code']);
        });

        Schema::create('business_competitors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_report_id')->constrained('business_reports')->cascadeOnDelete();
            $table->string('name');
            $table->text('location')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_competitors');
        Schema::dropIfExists('business_observations');
        Schema::dropIfExists('business_suppliers');
        Schema::dropIfExists('business_products');
        Schema::dropIfExists('business_branches');
        Schema::dropIfExists('business_tenants');
        Schema::dropIfExists('business_properties');
        Schema::dropIfExists('business_reports');
        Schema::dropIfExists('declared_income_source_items');
        Schema::dropIfExists('general_income_source_reports');
        Schema::dropIfExists('income_sources');
    }
};
