<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documentation_telegram_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('residence_business_documentation_id');
            $table->foreignId('client_folder_id');
            $table->foreignId('co_maker_id')->nullable();
            $table->foreignId('sent_by');
            $table->string('chat_id_snapshot');
            $table->string('status', 20);
            $table->string('payload_fingerprint', 64);
            $table->string('active_key')->nullable();
            $table->json('message_ids')->nullable();
            $table->string('error_summary')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->foreign('residence_business_documentation_id', 'doc_tg_delivery_documentation_fk')
                ->references('id')->on('residence_business_documentations')->cascadeOnDelete();
            $table->foreign('client_folder_id', 'doc_tg_delivery_folder_fk')
                ->references('id')->on('client_folders')->cascadeOnDelete();
            $table->foreign('co_maker_id', 'doc_tg_delivery_co_maker_fk')
                ->references('id')->on('co_makers')->nullOnDelete();
            $table->foreign('sent_by', 'doc_tg_delivery_sender_fk')
                ->references('id')->on('users')->restrictOnDelete();
            $table->index('status', 'doc_tg_delivery_status_idx');
            $table->unique('active_key', 'doc_tg_delivery_active_key_uq');
            $table->index(['residence_business_documentation_id', 'status'], 'doc_tg_delivery_doc_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documentation_telegram_deliveries');
    }
};
