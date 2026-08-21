<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('generated_reports', function (Blueprint $table): void {
            $table->foreignId('co_maker_id')->nullable()->after('client_folder_id')
                ->constrained('co_makers')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('generated_reports', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('co_maker_id');
        });
    }
};
