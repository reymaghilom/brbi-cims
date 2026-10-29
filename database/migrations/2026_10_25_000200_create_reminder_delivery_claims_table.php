<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Authoritative, pre-send reminder claims.
 *
 * A claim is deliberately not an email-delivery receipt. Once acquired it is never automatically
 * expired or released: an uncertain Gmail result therefore fails closed instead of silently
 * authorizing a duplicate message.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reminder_delivery_claims', function (Blueprint $table): void {
            $table->id();
            $table->uuid('claim_uuid')->unique();
            $table->uuid('event_uuid')->unique();
            $table->string('occurrence_key', 64)->unique();
            $table->string('subject_type', 32);
            $table->unsignedBigInteger('subject_id');
            $table->unsignedBigInteger('ci_activity_id');
            $table->unsignedInteger('schedule_version');
            $table->timestamp('occurrence_at');
            $table->unsignedBigInteger('recipient_user_id');
            $table->string('recipient_email_hash', 64);
            $table->string('status', 24)->default('claimed');
            $table->timestamp('claimed_at');
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['subject_type', 'subject_id', 'schedule_version'],
                'reminder_claim_subject_version_unique',
            );
            $table->index(['status', 'claimed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reminder_delivery_claims');
    }
};
