<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('co_makers', function (Blueprint $table): void {
            $table->unsignedBigInteger('revision')->default(1)->after('last_edited_by');
        });
    }

    public function down(): void
    {
        Schema::table('co_makers', function (Blueprint $table): void {
            $table->dropColumn('revision');
        });
    }
};
