<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('residence_business_documentations', function (Blueprint $table) {
            $table->text('remarks')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('residence_business_documentations', function (Blueprint $table) {
            $table->dropColumn('remarks');
        });
    }
};
