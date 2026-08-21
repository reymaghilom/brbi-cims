<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // MySQL won't drop the unique index while it's still backing the client_folder_id
        // foreign key, so the replacement plain index has to exist first — then the unique
        // index (now redundant) can be dropped safely in a second step.
        Schema::table('co_makers', function (Blueprint $table): void {
            $table->index('client_folder_id', 'co_makers_client_folder_id_index');
        });
        Schema::table('co_makers', function (Blueprint $table): void {
            $table->dropUnique(['client_folder_id']);
        });
    }

    public function down(): void
    {
        Schema::table('co_makers', function (Blueprint $table): void {
            $table->unique('client_folder_id');
        });
        Schema::table('co_makers', function (Blueprint $table): void {
            $table->dropIndex('co_makers_client_folder_id_index');
        });
    }
};
