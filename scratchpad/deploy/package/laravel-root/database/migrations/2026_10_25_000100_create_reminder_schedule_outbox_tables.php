<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Transactional outbox for the n8n email-reminder integration.
 *
 *  - reminder_schedule_states: the last schedule state recorded for one exact reminder subject (a
 *    parent CI Activity, one Bank/Coop target or one Asset target) plus its monotonic version. It
 *    deliberately has NO foreign keys: it must outlive a deleted activity, target, Co-Maker or
 *    folder so the cancellation of that subject's pending email can still be recorded and sent.
 *  - reminder_outbox_events: one immutable event per version, written in the same transaction as
 *    the schedule change that caused it, delivered later and idempotently.
 *
 * Purely additive: no existing table or row is altered.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reminder_schedule_states', function (Blueprint $table): void {
            $table->id();
            $table->string('subject_type', 32);
            $table->unsignedBigInteger('subject_id');
            $table->unsignedBigInteger('ci_activity_id')->index();
            $table->unsignedBigInteger('client_folder_id')->index();
            $table->unsignedInteger('version')->default(0);
            $table->boolean('is_active')->default(false)->index();
            $table->unsignedBigInteger('recipient_user_id')->nullable();
            $table->timestamp('scheduled_at')->nullable();
            $table->boolean('scheduled_has_time')->default(false);
            $table->timestamps();

            $table->unique(['subject_type', 'subject_id']);
        });

        Schema::create('reminder_outbox_events', function (Blueprint $table): void {
            $table->id();
            $table->uuid('event_uuid')->unique();
            $table->string('idempotency_key', 96)->unique();
            $table->string('subject_type', 32);
            $table->unsignedBigInteger('subject_id');
            $table->unsignedInteger('version');
            $table->string('event_type', 32);
            $table->json('payload');
            $table->string('status', 16)->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('next_attempt_at')->nullable();
            $table->uuid('claim_token')->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('last_attempted_at')->nullable();
            $table->unsignedSmallInteger('last_response_status')->nullable();
            $table->string('last_error')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();

            $table->unique(['subject_type', 'subject_id', 'version']);
            $table->index(['status', 'next_attempt_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reminder_outbox_events');
        Schema::dropIfExists('reminder_schedule_states');
    }
};
