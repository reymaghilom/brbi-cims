<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('residence_business_reports', function (Blueprint $table): void {
            $table->foreignId('co_maker_id')->nullable()->after('client_folder_id')
                ->constrained('co_makers')->cascadeOnDelete();
        });

        // Same two-step index swap as cibi_reports: the new composite unique (leftmost column
        // still client_folder_id) must exist before the old single-column unique — which is
        // currently backing the client_folder_id FK — can be dropped.
        Schema::table('residence_business_reports', function (Blueprint $table): void {
            $table->unique(['client_folder_id', 'co_maker_id'], 'residence_business_reports_client_folder_co_maker_unique');
        });
        Schema::table('residence_business_reports', function (Blueprint $table): void {
            $table->dropUnique('residence_business_reports_client_folder_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('residence_business_reports', function (Blueprint $table): void {
            $table->unique('client_folder_id', 'residence_business_reports_client_folder_id_unique');
        });
        Schema::table('residence_business_reports', function (Blueprint $table): void {
            $table->dropUnique('residence_business_reports_client_folder_co_maker_unique');
        });
        Schema::table('residence_business_reports', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('co_maker_id');
        });
    }
};
