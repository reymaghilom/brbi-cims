<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('income_sources', function (Blueprint $table): void {
            $table->foreignId('created_by')->nullable()->after('last_edited_by')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('income_sources', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('created_by');
        });
    }
};
