<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('income_sources', function (Blueprint $table) {
            $table->string('amount_applied')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('income_sources', function (Blueprint $table) {
            $table->decimal('amount_applied', 15, 2)->nullable()->change();
        });
    }
};
