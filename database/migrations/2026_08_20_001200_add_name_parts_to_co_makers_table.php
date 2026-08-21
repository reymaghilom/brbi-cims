<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('co_makers', function (Blueprint $table): void {
            $table->string('first_name')->nullable()->after('full_name');
            $table->string('middle_name')->nullable()->after('first_name');
            $table->string('last_name')->nullable()->after('middle_name');
        });

        // Best-effort split of existing free-text full_name values so the edit form never opens
        // blank for a co-maker saved before this change. full_name itself is left untouched — it
        // stays the source of truth for display until the record is next saved through the new
        // split-name form, which recomputes it from these parts.
        DB::table('co_makers')->orderBy('id')->get(['id', 'full_name'])->each(function (object $row): void {
            $parts = preg_split('/\s+/', trim((string) $row->full_name), -1, PREG_SPLIT_NO_EMPTY);
            $first = $parts[0] ?? '';
            $last = count($parts) > 1 ? end($parts) : $first;
            $middle = count($parts) > 2 ? implode(' ', array_slice($parts, 1, -1)) : null;

            DB::table('co_makers')->where('id', $row->id)->update([
                'first_name' => $first,
                'middle_name' => $middle,
                'last_name' => $last,
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('co_makers', function (Blueprint $table): void {
            $table->dropColumn(['first_name', 'middle_name', 'last_name']);
        });
    }
};
