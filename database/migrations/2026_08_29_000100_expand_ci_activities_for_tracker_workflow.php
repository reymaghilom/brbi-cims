<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ci_activities', function (Blueprint $table): void {
            $table->string('target')->nullable()->after('name');
            $table->foreignId('creator_id')->nullable()->after('assigned_ci_id')->constrained('users')->nullOnDelete();
            $table->string('status', 30)->default('pending')->change();
            $table->timestamp('scheduled_at')->nullable()->after('status');
            $table->timestamp('reminder_sent_at')->nullable()->after('scheduled_at');
        });

        DB::table('ci_activities')->where('status', 'not_started')->update(['status' => 'pending']);
        DB::table('ci_activities')->where('status', 'in_progress')->update(['status' => 'follow_up']);

        $folderCreators = DB::table('client_folders')->pluck('assigned_ci_id', 'id');
        DB::table('ci_activities')->orderBy('id')->eachById(function (object $activity) use ($folderCreators): void {
            DB::table('ci_activities')->where('id', $activity->id)->update([
                'creator_id' => $folderCreators[$activity->client_folder_id] ?? null,
            ]);
        }, 100, 'id');

        Schema::table('ci_activities', function (Blueprint $table): void {
            $table->dropUnique('ci_activities_client_folder_co_maker_definition_unique');
            $table->index(
                ['client_folder_id', 'co_maker_id', 'activity_definition_id'],
                'ci_activities_folder_person_definition_index',
            );
            $table->index(['status', 'scheduled_at', 'reminder_sent_at'], 'ci_activities_due_reminder_index');
        });
    }

    public function down(): void
    {
        DB::table('ci_activities')->where('status', 'pending')->update(['status' => 'not_started']);
        DB::table('ci_activities')->whereIn('status', ['scheduled', 'follow_up'])->update(['status' => 'in_progress']);

        Schema::table('ci_activities', function (Blueprint $table): void {
            $table->dropIndex('ci_activities_folder_person_definition_index');
            $table->dropIndex('ci_activities_due_reminder_index');
            $table->dropConstrainedForeignId('creator_id');
            $table->dropColumn(['target', 'scheduled_at', 'reminder_sent_at']);
            $table->string('status', 30)->default('not_started')->change();
        });
    }
};
