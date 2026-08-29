<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('activity_definitions')
            ->whereIn('code', ['residence_check', 'business_check'])
            ->update(['is_active' => false]);
    }

    public function down(): void
    {
        DB::table('activity_definitions')
            ->whereIn('code', ['residence_check', 'business_check'])
            ->update(['is_active' => true]);
    }
};
