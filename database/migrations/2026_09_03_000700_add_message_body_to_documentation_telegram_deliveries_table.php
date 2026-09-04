<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documentation_telegram_deliveries', function (Blueprint $table) {
            $table->text('message_body')->nullable()->after('payload_fingerprint');
        });
    }

    public function down(): void
    {
        Schema::table('documentation_telegram_deliveries', function (Blueprint $table) {
            $table->dropColumn('message_body');
        });
    }
};
