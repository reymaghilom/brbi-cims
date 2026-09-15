<?php

use App\Support\Database\NormalizedKeyCollisionPreflight;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Adds the authoritative, unique normalized-name key for Activity Types.
 *
 * SAFETY ORDER. MySQL/MariaDB commit each DDL statement implicitly, so a failure half way through
 * cannot be rolled back. Every existing row is therefore read and checked for normalized-name
 * conflicts BEFORE any schema change — the unique index is never used to discover them. A conflict
 * throws while the table is still exactly as it was. Only after that preflight passes is the column
 * added, filled, uniquely indexed and made NOT NULL.
 *
 * The key is frozen here as the rule ActivityDefinition::normalizedNameKey() applies today:
 * Str::lower(Str::squish($name)) — trims leading/trailing whitespace (including Unicode
 * whitespace), collapses every inner whitespace run to one space, then lower-cases.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('activity_definitions', 'normalized_name')) {
            throw new RuntimeException(
                'activity_definitions.normalized_name already exists, but this migration has not been recorded as run. '
                .'A previous attempt may have been interrupted. Inspect the table manually (drop the partial column/index '
                .'only after confirming it holds nothing you need) and then run the migration again.',
            );
        }

        // 1. Preflight: read only, compute every key in memory, and fail before touching the schema.
        $normalizedById = [];
        $firstIdByKey = [];
        $conflicts = [];
        foreach (DB::table('activity_definitions')->orderBy('id')->get(['id', 'name']) as $definition) {
            $key = self::normalize((string) $definition->name);
            $normalizedById[$definition->id] = $key;
            if (isset($firstIdByKey[$key])) {
                $conflicts[] = "rows {$firstIdByKey[$key]} and {$definition->id} both normalize to [{$key}]";
            } else {
                $firstIdByKey[$key] = $definition->id;
            }
        }

        if ($conflicts !== []) {
            throw new RuntimeException(
                'Cannot add the unique normalized name to activity_definitions; nothing was changed. '
                .'Rename these Activity Types first: '.implode('; ', $conflicts).'.',
            );
        }

        // 1b. Database-collation preflight (MySQL/MariaDB): keys PHP sees as distinct may still be
        //     equal under the column's collation, which is what the unique index will enforce.
        $collisions = app(NormalizedKeyCollisionPreflight::class)->collisions(DB::connection(), 'activity_definitions', $normalizedById);
        if ($collisions['conflicts'] !== []) {
            throw new RuntimeException(
                'Cannot add the unique normalized name to activity_definitions; nothing was changed. '
                ."Under the database collation [{$collisions['collation']}] these Activity Types would be duplicates: "
                .implode('; ', array_map(fn (array $ids): string => 'rows '.implode(', ', array_map(fn (int $id): string => "{$id} [{$normalizedById[$id]}]", $ids)), $collisions['conflicts']))
                .'. Rename them first.',
            );
        }

        // 2. Only now mutate the schema.
        Schema::table('activity_definitions', function (Blueprint $table): void {
            $table->string('normalized_name')->nullable()->after('name');
        });

        foreach ($normalizedById as $id => $key) {
            DB::table('activity_definitions')->where('id', $id)->update(['normalized_name' => $key]);
        }

        Schema::table('activity_definitions', function (Blueprint $table): void {
            $table->unique('normalized_name', 'activity_definitions_normalized_name_unique');
        });
        Schema::table('activity_definitions', function (Blueprint $table): void {
            $table->string('normalized_name')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('activity_definitions', function (Blueprint $table): void {
            $table->dropUnique('activity_definitions_normalized_name_unique');
            $table->dropColumn('normalized_name');
        });
    }

    /** Frozen copy of ActivityDefinition::normalizedNameKey() at the time of this migration. */
    private static function normalize(string $name): string
    {
        return Str::lower(Str::squish($name));
    }
};
