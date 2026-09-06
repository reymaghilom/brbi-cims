<?php

namespace App\Actions\ClientFolders;

use App\Models\ActivityDefinition;

class EnsureCanonicalActivityDefinitions
{
    /** @return array<string, array{name: string, sort_order: int}> */
    public static function definitions(): array
    {
        return [
            ActivityDefinition::BARANGAY_CHECK_CODE => ['name' => 'Barangay Check', 'sort_order' => 3],
            ActivityDefinition::NEIGHBOR_CHECK_CODE => ['name' => 'Neighbor Check', 'sort_order' => 4],
            ActivityDefinition::ASSET_CHECK_CODE => ['name' => 'Asset Check', 'sort_order' => 5],
            ActivityDefinition::BANK_COOP_CHECK_CODE => ['name' => 'Bank / Coop Check', 'sort_order' => 6],
        ];
    }

    public function execute(): void
    {
        $now = now();
        $rows = collect(self::definitions())
            ->map(fn (array $definition, string $code): array => [
                'code' => $code,
                'name' => $definition['name'],
                'sort_order' => $definition['sort_order'],
                'is_required' => true,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ])
            ->values()
            ->all();

        ActivityDefinition::query()->upsert(
            $rows,
            ['code'],
            ['name', 'sort_order', 'is_required', 'is_active', 'updated_at'],
        );
    }
}
