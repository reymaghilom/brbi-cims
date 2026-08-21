<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ci_activities', function (Blueprint $table): void {
            $table->foreignId('co_maker_id')->nullable()->after('client_folder_id')
                ->constrained('co_makers')->cascadeOnDelete();
        });

        // Same two-step index swap precedent as cibi_reports/residence_business_reports: add the
        // new composite unique (still leftmost on client_folder_id) before dropping the old one,
        // so client_folder_id's FK support is never momentarily unindexed.
        Schema::table('ci_activities', function (Blueprint $table): void {
            $table->unique(['client_folder_id', 'co_maker_id', 'activity_definition_id'], 'ci_activities_client_folder_co_maker_definition_unique');
        });
        Schema::table('ci_activities', function (Blueprint $table): void {
            $table->dropUnique('ci_activities_client_folder_id_activity_definition_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('ci_activities', function (Blueprint $table): void {
            $table->unique(['client_folder_id', 'activity_definition_id'], 'ci_activities_client_folder_id_activity_definition_id_unique');
        });
        Schema::table('ci_activities', function (Blueprint $table): void {
            $table->dropUnique('ci_activities_client_folder_co_maker_definition_unique');
        });
        Schema::table('ci_activities', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('co_maker_id');
        });
    }
};
