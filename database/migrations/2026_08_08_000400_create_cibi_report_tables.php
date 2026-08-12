<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cibi_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_folder_id')->unique()->constrained('client_folders')->cascadeOnDelete();
            $table->foreignId('ci_in_charge_id')->constrained('users')->restrictOnDelete();
            $table->date('start_date')->nullable();
            $table->date('submitted_date')->nullable();
            $table->string('party_type', 30)->default('borrower');
            $table->string('branch_name')->nullable();
            $table->string('account_officer_name')->nullable();
            $table->decimal('amount_applied', 15, 2)->nullable();
            $table->string('ci_risk_level', 40)->nullable();
            $table->json('purpose_codes')->nullable();
            $table->string('purpose_other')->nullable();
            $table->text('purpose_remarks')->nullable();
            $table->longText('negative_credit_findings')->nullable();
            $table->longText('other_remarks')->nullable();
            $table->string('prepared_by_name')->nullable();
            $table->string('noted_by_name')->nullable();
            $table->string('state', 20)->default('draft');
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('last_edited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();
        });

        Schema::create('cibi_bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cibi_report_id')->constrained('cibi_reports')->cascadeOnDelete();
            $table->string('institution');
            $table->string('branch')->nullable();
            $table->unsignedSmallInteger('year_opened')->nullable();
            $table->string('adb_level', 80)->nullable();
            $table->decimal('capital_share_amount', 15, 2)->nullable();
            $table->text('relevant_remarks')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('cibi_loan_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cibi_report_id')->constrained('cibi_reports')->cascadeOnDelete();
            $table->string('institution');
            $table->decimal('original_amount', 15, 2)->nullable();
            $table->decimal('remaining_balance', 15, 2)->nullable();
            $table->decimal('amortization_amount', 15, 2)->nullable();
            $table->date('granted_date')->nullable();
            $table->date('maturity_date')->nullable();
            $table->unsignedSmallInteger('cycle_number')->nullable();
            $table->string('security_type')->nullable();
            $table->text('payment_performance')->nullable();
            $table->text('remarks')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('cibi_credit_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cibi_report_id')->constrained('cibi_reports')->cascadeOnDelete();
            $table->string('institution');
            $table->string('branch')->nullable();
            $table->boolean('is_declared')->default(false);
            $table->string('check_status', 50)->nullable();
            $table->date('checked_date')->nullable();
            $table->text('key_information')->nullable();
            $table->text('remarks')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('cibi_income_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cibi_report_id')->constrained('cibi_reports')->cascadeOnDelete();
            $table->foreignId('income_source_id')->nullable()->constrained('income_sources')->nullOnDelete();
            $table->string('source_name');
            $table->string('source_type')->nullable();
            $table->string('stability_result')->nullable();
            $table->string('validation_status', 80)->nullable();
            $table->text('key_information')->nullable();
            $table->decimal('monthly_amount', 15, 2)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->index(['cibi_report_id', 'income_source_id']);
        });

        Schema::create('cibi_legal_findings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cibi_report_id')->constrained('cibi_reports')->cascadeOnDelete();
            $table->string('source_level', 40);
            $table->string('result')->nullable();
            $table->text('details')->nullable();
            $table->date('checked_at')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cibi_legal_findings');
        Schema::dropIfExists('cibi_income_sources');
        Schema::dropIfExists('cibi_credit_checks');
        Schema::dropIfExists('cibi_loan_records');
        Schema::dropIfExists('cibi_bank_accounts');
        Schema::dropIfExists('cibi_reports');
    }
};
