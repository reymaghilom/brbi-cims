<?php

use App\Support\Database\NormalizedKeyCollisionPreflight;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the authoritative, unique normalized-name key for Custom Business Categories.
 *
 * SAFETY ORDER. MySQL/MariaDB commit each DDL statement implicitly, so a failure half way through
 * cannot be rolled back. Every existing row is therefore read and checked for normalized-name
 * conflicts BEFORE any schema change: a conflict throws while the table is still exactly as it was.
 * Only after that preflight passes is the column added, filled, uniquely indexed and made NOT NULL.
 *
 * The key is frozen here as the rule CustomBusinessCategory::normalizeName() applies today:
 * mb_strtolower(trim((string) $name)) — trims leading/trailing ASCII whitespace and lower-cases;
 * inner spaces are NOT collapsed.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('custom_business_categories', 'normalized_name')) {
            throw new RuntimeException(
                'custom_business_categories.normalized_name already exists, but this migration has not been recorded as run. '
                .'A previous attempt may have been interrupted. Inspect the table manually (drop the partial column/index '
                .'only after confirming it holds nothing you need) and then run the migration again.',
            );
        }

        // 1. Preflight: read only, compute every key in memory, and fail before touching the schema.
        $normalizedById = [];
        $firstIdByKey = [];
        $conflicts = [];
        foreach (DB::table('custom_business_categories')->orderBy('id')->get(['id', 'name']) as $category) {
            $key = self::normalize($category->name);
            $normalizedById[$category->id] = $key;
            if (isset($firstIdByKey[$key])) {
                $conflicts[] = "rows {$firstIdByKey[$key]} and {$category->id} both normalize to [{$key}]";
            } else {
                $firstIdByKey[$key] = $category->id;
            }
        }

        if ($conflicts !== []) {
            throw new RuntimeException(
                'Cannot add the unique normalized name to custom_business_categories; nothing was changed. '
                .'Rename or merge these categories first: '.implode('; ', $conflicts).'.',
            );
        }

        // 1b. Database-collation preflight (MySQL/MariaDB): keys PHP sees as distinct may still be
        //     equal under the column's collation, which is what the unique index will enforce.
        $collisions = app(NormalizedKeyCollisionPreflight::class)->collisions(DB::connection(), 'custom_business_categories', $normalizedById);
        if ($collisions['conflicts'] !== []) {
            throw new RuntimeException(
                'Cannot add the unique normalized name to custom_business_categories; nothing was changed. '
                ."Under the database collation [{$collisions['collation']}] these categories would be duplicates: "
                .implode('; ', array_map(fn (array $ids): string => 'rows '.implode(', ', array_map(fn (int $id): string => "{$id} [{$normalizedById[$id]}]", $ids)), $collisions['conflicts']))
                .'. Rename or merge them first.',
            );
        }

        // 2. Only now mutate the schema.
        Schema::table('custom_business_categories', function (Blueprint $table): void {
            $table->string('normalized_name')->nullable()->after('name');
        });

        foreach ($normalizedById as $id => $key) {
            DB::table('custom_business_categories')->where('id', $id)->update(['normalized_name' => $key]);
        }

        Schema::table('custom_business_categories', function (Blueprint $table): void {
            $table->unique('normalized_name', 'custom_business_categories_normalized_name_unique');
        });
        Schema::table('custom_business_categories', function (Blueprint $table): void {
            $table->string('normalized_name')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('custom_business_categories', function (Blueprint $table): void {
            $table->dropUnique('custom_business_categories_normalized_name_unique');
            $table->dropColumn('normalized_name');
        });
    }

    /** Frozen copy of CustomBusinessCategory::normalizeName() at the time of this migration. */
    private static function normalize(?string $name): string
    {
        return mb_strtolower(trim((string) $name));
    }
};
