<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('residence_checks', function (Blueprint $table): void {
            $table->foreignId('updated_by')->nullable()->after('ci_user_id')
                ->constrained('users')->nullOnDelete();
        });

        Schema::table('business_checks', function (Blueprint $table): void {
            $table->foreignId('updated_by')->nullable()->after('ci_user_id')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('residence_checks', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('updated_by');
        });

        Schema::table('business_checks', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('updated_by');
        });
    }
};
