<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cibi_reports', function (Blueprint $table): void {
            $table->foreignId('co_maker_id')->nullable()->after('client_folder_id')
                ->constrained('co_makers')->cascadeOnDelete();
        });

        // MySQL won't drop the single-column unique index while it's still backing the
        // client_folder_id foreign key, so the replacement composite unique (which also starts
        // with client_folder_id) has to exist first — then the old one can be dropped safely.
        Schema::table('cibi_reports', function (Blueprint $table): void {
            $table->unique(['client_folder_id', 'co_maker_id'], 'cibi_reports_client_folder_co_maker_unique');
        });
        Schema::table('cibi_reports', function (Blueprint $table): void {
            $table->dropUnique('cibi_reports_client_folder_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('cibi_reports', function (Blueprint $table): void {
            $table->unique('client_folder_id', 'cibi_reports_client_folder_id_unique');
        });
        Schema::table('cibi_reports', function (Blueprint $table): void {
            $table->dropUnique('cibi_reports_client_folder_co_maker_unique');
        });
        Schema::table('cibi_reports', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('co_maker_id');
        });
    }
};
