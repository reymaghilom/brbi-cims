<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ci_activities', function (Blueprint $table): void {
            $table->foreignId('assigned_ci_id')->nullable()->after('activity_definition_id')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('ci_activities', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('assigned_ci_id');
        });
    }
};
