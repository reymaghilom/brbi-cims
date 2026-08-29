<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ci_activities', function (Blueprint $table): void {
            $table->timestamp('submitted_at')->nullable()->after('completed_at');
            $table->foreignId('submitted_by')->nullable()->after('submitted_at')->constrained('users')->nullOnDelete();
            $table->string('submitted_to')->nullable()->after('submitted_by');
            $table->text('submission_note')->nullable()->after('submitted_to');
        });
    }

    public function down(): void
    {
        Schema::table('ci_activities', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('submitted_by');
            $table->dropColumn(['submitted_at', 'submitted_to', 'submission_note']);
        });
    }
};
