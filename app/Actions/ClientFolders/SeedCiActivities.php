<?php

namespace App\Actions\ClientFolders;

use App\Models\ActivityDefinition;
use App\Models\ClientFolder;
use App\Models\CoMaker;

class SeedCiActivities
{
    /**
     * Creates one CiActivity row per active ActivityDefinition, owned by the given person
     * (null = Applicant). Used both when a ClientFolder is first created and whenever a new
     * CoMaker is added, so every person always has their own complete activity checklist.
     */
    public function execute(ClientFolder $folder, ?CoMaker $person = null): void
    {
        $folder->activities()->createMany(
            ActivityDefinition::query()
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->get(['id', 'name'])
                ->map(fn (ActivityDefinition $definition): array => [
                    'co_maker_id' => $person?->id,
                    'activity_definition_id' => $definition->id,
                    'name' => $definition->name,
                ])
                ->all(),
        );
    }
}
