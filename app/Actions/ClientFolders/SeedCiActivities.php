<?php

namespace App\Actions\ClientFolders;

use App\Models\ClientFolder;
use App\Models\CoMaker;

class SeedCiActivities
{
    /**
     * Kept as a compatibility boundary for existing Client Folder and Co-Maker creation flows.
     * Fresh person contexts intentionally start empty; CI users add only the activities needed.
     */
    public function execute(ClientFolder $folder, ?CoMaker $person = null): void
    {
        // Intentionally no-op. Existing activity records are preserved unchanged.
    }
}
