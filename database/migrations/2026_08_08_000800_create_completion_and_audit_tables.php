<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('completion_rules', function (Blueprint $table) {
            $table->id();
            $table->string('code', 100)->unique();
            $table->string('label');
            $table->string('source_type', 80);
            $table->json('source_condition')->nullable();
            $table->boolean('is_required')->default(true);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->decimal('weight', 7, 4)->nullable();
            $table->timestamps();
            $table->index(['is_active', 'is_required', 'sort_order'], 'completion_rules_active_required_order_idx');
        });

        Schema::create('client_completion_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_folder_id')->constrained('client_folders')->cascadeOnDelete();
            $table->foreignId('completion_rule_id')->constrained('completion_rules')->cascadeOnDelete();
            $table->boolean('is_satisfied')->default(false);
            $table->decimal('score', 7, 4)->nullable();
            $table->string('explanation_key')->nullable();
            $table->timestamp('evaluated_at');
            $table->timestamps();
            $table->unique(['client_folder_id', 'completion_rule_id'], 'client_completion_rule_unique');
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('client_folder_id')->nullable()->constrained('client_folders')->nullOnDelete();
            $table->string('action', 100);
            $table->string('module', 100);
            $table->text('description');
            $table->json('metadata')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['client_folder_id', 'created_at']);
            $table->index(['user_id', 'created_at']);
            $table->index(['module', 'action', 'created_at'], 'audit_module_action_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('client_completion_results');
        Schema::dropIfExists('completion_rules');
    }
};
