<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Use the query builder deliberately: legacy values cannot be hydrated through the
        // ActivityStatus enum until they have been normalized.
        DB::table('ci_activities')
            ->where('status', 'not_started')
            ->update(['status' => 'pending']);

        DB::table('ci_activities')
            ->where('status', 'in_progress')
            ->update(['status' => 'follow_up']);
    }

    public function down(): void
    {
        // Intentionally irreversible. Mapping every valid pending/follow_up row back to a legacy
        // value would corrupt activities created after the tracker workflow was introduced.
    }
};
