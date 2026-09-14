<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cibi_loan_records', function (Blueprint $table): void {
            $table->string('original_amount')->nullable()->change();
            $table->string('remaining_balance')->nullable()->change();
            $table->string('amortization_amount')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('cibi_loan_records', function (Blueprint $table): void {
            $table->decimal('original_amount', 15, 2)->nullable()->change();
            $table->decimal('remaining_balance', 15, 2)->nullable()->change();
            $table->decimal('amortization_amount', 15, 2)->nullable()->change();
        });
    }
};
