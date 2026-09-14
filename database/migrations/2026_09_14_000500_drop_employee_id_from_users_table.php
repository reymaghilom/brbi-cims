<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Employee ID is no longer part of the system. Users are identified only by users.id (the
 * authenticated session and every user relationship already use it), and they sign in with their
 * username. Dropping the column intentionally and permanently removes any stored Employee ID values;
 * they are not migrated anywhere and no replacement identifier is introduced.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'employee_id')) {
            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            // The original column was created ->unique(); the index must go before the column.
            $table->dropUnique(['employee_id']);
            $table->dropColumn('employee_id');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'employee_id')) {
            return;
        }

        // Structure only — the removed values cannot be reconstructed.
        Schema::table('users', function (Blueprint $table): void {
            $table->string('employee_id', 50)->nullable()->unique()->after('id');
        });
    }
};
